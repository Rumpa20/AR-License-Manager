<?php
/**
 * Customers: search/list, status changes (suspend/block/reactivate),
 * per-customer license and order counts, CSV export. Every state change
 * is CSRF-checked and audit logged.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Logger;
use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$states = ['active', 'suspended', 'blocked'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);
    $id = (int) View::post('id');
    $to = (string) ($_POST['to'] ?? '');
    $customer = $id > 0 ? \ARLM\Database::one('SELECT id, email, status FROM ' . $t('customers') . ' WHERE id = ?', [$id]) : null;

    if ($customer === null || !in_array($to, $states, true)) {
        View::setFlash('error', 'Unknown customer or status.');
    } elseif ($customer['status'] === $to) {
        View::setFlash('warn', 'Customer is already ' . $to . '.');
    } else {
        \ARLM\Database::run('UPDATE ' . $t('customers') . ' SET status = ? WHERE id = ?', [$to, $id]);
        Logger::audit('admin', Auth::adminId(), 'customer_set_' . $to, 'customer', $id, (string) $customer['email']);
        View::setFlash('ok', 'Customer #' . $id . ' set to ' . $to . '.');
    }
    View::redirect('index.php?page=customers&q=' . urlencode(View::query('q')) . '&status=' . urlencode(View::query('status')));
}

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all('SELECT id, smf_member_id, email, name, source, status, created_at FROM ' . $t('customers') . ' ORDER BY id DESC');
    View::csv(['id', 'smf_member_id', 'email', 'name', 'source', 'status', 'created_at'], $rows, 'arlm-customers.csv');
}

[$sortCol, $sortDir] = View::sort(['id', 'email', 'created_at'], 'id');

$where = ['1=1'];
$args = [];
$q = View::query('q');
if ($q !== '') {
    $where[] = '(c.email LIKE ? OR c.name LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
$statusFilter = in_array(View::query('status'), $states, true) ? View::query('status') : '';
if ($statusFilter !== '') {
    $where[] = 'c.status = ?';
    $args[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$total = (int) \ARLM\Database::value(
    "SELECT COUNT(*) FROM " . $t('customers') . " c WHERE {$whereSql}",
    $args
);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT c.id, c.smf_member_id, c.email, c.name, c.source, c.status, c.created_at,
            (SELECT COUNT(*) FROM " . $t('licenses') . " l WHERE l.customer_id = c.id) AS license_count,
            (SELECT COUNT(*) FROM " . $t('orders') . " o WHERE o.customer_id = c.id) AS order_count
     FROM " . $t('customers') . " c
     WHERE {$whereSql} ORDER BY c.{$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ', 20',
    $args
);
$base = 'index.php?page=customers&q=' . urlencode($q) . '&status=' . urlencode($statusFilter);
?>
<h1><?= View::h(View::t('customers')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="customers">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h($q) ?>" placeholder="email / name"></div>
  <div><label><?= View::h(View::t('status')) ?></label>
    <select name="status"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($states as $s): ?>
      <option value="<?= View::h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= View::h(View::t($s)) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit"><?= View::h(View::t('filter')) ?></button>
  <a class="btn btn-sub" href="index.php?page=customers&export=csv"><?= View::h(View::t('export_csv')) ?></a>
</form>

<div class="card">
  <table>
    <tr>
      <th><a href="index.php?page=customers&sort=id&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">ID</a></th>
      <th><a href="index.php?page=customers&sort=email&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('email')) ?></a></th>
      <th><?= View::h(View::t('name')) ?></th><th>SMF</th><th>Source</th>
      <th><?= View::h(View::t('license')) ?></th><th><?= View::h(View::t('orders')) ?></th><th><?= View::h(View::t('status')) ?></th><th><?= View::h(View::t('actions')) ?></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td><?= View::h($row['email']) ?></td>
      <td><?= View::h($row['name'] ?? '') ?></td>
      <td><?= $row['smf_member_id'] !== null ? (int) $row['smf_member_id'] : '-' ?></td>
      <td><?= View::h($row['source']) ?></td>
      <td><a href="index.php?page=licenses&q=<?= View::h(urlencode((string) $row['email'])) ?>"><?= (int) $row['license_count'] ?></a></td>
      <td><a href="index.php?page=orders&q=<?= View::h(urlencode((string) $row['email'])) ?>"><?= (int) $row['order_count'] ?></a></td>
      <td><?= View::statusBadge((string) $row['status']) ?></td>
      <td class="row-actions">
        <a class="btn btn-sub btn-sm" href="index.php?page=generate&email=<?= View::h(urlencode((string) $row['email'])) ?>"><?= View::h(View::t('generate')) ?></a>
        <form class="inline-form" method="post" onsubmit="return confirm('Change this customer status?');">
          <?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="q" value="<?= View::h($q) ?>">
          <input type="hidden" name="status" value="<?= View::h($statusFilter) ?>">
<?php if ($row['status'] === 'active'): ?>
          <input type="hidden" name="to" value="suspended">
          <button type="submit" class="btn-sub btn-sm btn"><?= View::h(View::t('suspended')) ?></button>
<?php else: ?>
          <input type="hidden" name="to" value="active">
          <button type="submit" class="btn-sm"><?= View::h(View::t('active')) ?></button>
<?php endif; ?>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </table>
  <?= View::paginate($total, $pagen, 20, $base) ?>
</div>
