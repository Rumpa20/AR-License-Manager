<?php
/**
 * Activations: list with domain/license/customer joins, admin deactivate
 * (counter-safe), reactivate (limit-checked) and audit logging.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Logger;
use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$states = ['active', 'deactivated'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);
    $id = (int) View::post('id');
    $do = (string) ($_POST['do'] ?? '');
    $row = $id > 0 ? \ARLM\Database::one(
        'SELECT a.id, a.license_id, a.domain, a.status, l.current_activations, l.max_activations
         FROM ' . $t('activations') . ' a
         JOIN ' . $t('licenses') . ' l ON l.id = a.license_id WHERE a.id = ?',
        [$id]
    ) : null;

    if ($row === null || $do !== 'deactivate' && $do !== 'reactivate') {
        View::setFlash('error', 'Unknown activation or action.');
    } elseif ($do === 'deactivate') {
        if ($row['status'] !== 'active') {
            View::setFlash('warn', 'Activation is not active.');
        } else {
            \ARLM\Database::begin();
            try {
                \ARLM\Database::run(
                    "UPDATE " . $t('activations') . " SET status = 'deactivated', deactivated_at = NOW() WHERE id = ? AND status = 'active'",
                    [$id]
                );
                \ARLM\Database::run(
                    'UPDATE ' . $t('licenses') . ' SET current_activations = GREATEST(current_activations - 1, 0) WHERE id = ? AND current_activations > 0',
                    [$row['license_id']]
                );
                \ARLM\Database::commit();
                Logger::audit('admin', Auth::adminId(), 'activation_deactivated', 'activation', $id, (string) $row['domain']);
                View::setFlash('ok', 'Activation #' . $id . ' (' . $row['domain'] . ') deactivated.');
            } catch (Throwable) {
                \ARLM\Database::rollBack();
                View::setFlash('error', 'Deactivation failed.');
            }
        }
    } elseif ($row['status'] !== 'deactivated') {
        View::setFlash('warn', 'Activation is already active.');
    } elseif ((int) $row['current_activations'] >= (int) $row['max_activations']) {
        View::setFlash('error', 'Activation limit reached for this license. Reset activations first.');
    } else {
        \ARLM\Database::begin();
        try {
            \ARLM\Database::run(
                "UPDATE " . $t('activations') . " SET status = 'active', deactivated_at = NULL WHERE id = ? AND status = 'deactivated'",
                [$id]
            );
            \ARLM\Database::run(
                'UPDATE ' . $t('licenses') . ' SET current_activations = current_activations + 1 WHERE id = ?',
                [$row['license_id']]
            );
            \ARLM\Database::commit();
            Logger::audit('admin', Auth::adminId(), 'activation_reactivated', 'activation', $id, (string) $row['domain']);
            View::setFlash('ok', 'Activation #' . $id . ' (' . $row['domain'] . ') reactivated.');
        } catch (Throwable) {
            \ARLM\Database::rollBack();
            View::setFlash('error', 'Reactivation failed.');
        }
    }
    View::redirect('index.php?page=activations&q=' . urlencode(View::query('q')) . '&status=' . urlencode(View::query('status')) . '&product=' . (int) View::query('product'));
}

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all(
        'SELECT a.id, a.license_id, a.domain, a.status, a.ip, a.activated_at, a.last_seen_at, a.deactivated_at
         FROM ' . $t('activations') . ' a ORDER BY a.id DESC'
    );
    View::csv(['id', 'license_id', 'domain', 'status', 'ip', 'activated_at', 'last_seen_at', 'deactivated_at'], $rows, 'arlm-activations.csv');
}

[$sortCol, $sortDir] = View::sort(['id', 'activated_at', 'last_seen_at'], 'id');

$where = ['1=1'];
$args = [];
$q = View::query('q');
if ($q !== '') {
    $where[] = '(a.domain LIKE ? OR l.key_last4 = ? OR c.email LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '', -4));
    $args[] = '%' . $q . '%';
}
$statusFilter = in_array(View::query('status'), $states, true) ? View::query('status') : '';
if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $args[] = $statusFilter;
}
$productFilter = (int) View::query('product');
if ($productFilter > 0) {
    $where[] = 'l.product_id = ?';
    $args[] = $productFilter;
}
$whereSql = implode(' AND ', $where);

$total = (int) \ARLM\Database::value(
    "SELECT COUNT(*) FROM " . $t('activations') . " a
     JOIN " . $t('licenses') . " l ON l.id = a.license_id
     JOIN " . $t('customers') . " c ON c.id = l.customer_id WHERE {$whereSql}",
    $args
);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT a.id, a.license_id, a.domain, a.status, a.ip, a.activated_at, a.last_seen_at,
            l.key_last4, p.name AS product_name, c.email AS customer_email
     FROM " . $t('activations') . " a
     JOIN " . $t('licenses') . " l ON l.id = a.license_id
     JOIN " . $t('products') . " p ON p.id = l.product_id
     JOIN " . $t('customers') . " c ON c.id = l.customer_id
     WHERE {$whereSql} ORDER BY a.{$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ', 20',
    $args
);
$products = \ARLM\Database::all('SELECT id, name FROM ' . $t('products') . ' ORDER BY name');
$base = 'index.php?page=activations&q=' . urlencode($q) . '&status=' . urlencode($statusFilter) . '&product=' . $productFilter;
?>
<h1><?= View::h(View::t('activations')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="activations">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h($q) ?>" placeholder="domain / last-4 / email"></div>
  <div><label><?= View::h(View::t('status')) ?></label>
    <select name="status"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($states as $s): ?>
      <option value="<?= View::h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= View::h(View::t($s)) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div><label><?= View::h(View::t('product')) ?></label>
    <select name="product"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($products as $pr): ?>
      <option value="<?= (int) $pr['id'] ?>" <?= $productFilter === (int) $pr['id'] ? 'selected' : '' ?>><?= View::h($pr['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit"><?= View::h(View::t('filter')) ?></button>
  <a class="btn btn-sub" href="index.php?page=activations&export=csv"><?= View::h(View::t('export_csv')) ?></a>
</form>

<div class="card">
  <table>
    <tr>
      <th><a href="index.php?page=activations&sort=id&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">ID</a></th>
      <th><?= View::h(View::t('domain')) ?></th><th><?= View::h(View::t('license')) ?></th><th><?= View::h(View::t('product')) ?></th><th><?= View::h(View::t('customer')) ?></th>
      <th><?= View::h(View::t('ip')) ?></th><th><?= View::h(View::t('created')) ?></th><th>Last seen</th>
      <th><a href="index.php?page=activations&sort=last_seen_at&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">&nbsp;</a></th>
      <th><?= View::h(View::t('status')) ?></th><th><?= View::h(View::t('actions')) ?></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td class="mono"><?= View::h($row['domain']) ?></td>
      <td class="mono">&bull;&bull;&bull;&bull;<?= View::h($row['key_last4']) ?> (#<?= (int) $row['license_id'] ?>)</td>
      <td><?= View::h($row['product_name']) ?></td>
      <td><?= View::h($row['customer_email']) ?></td>
      <td class="mono"><?= View::h($row['ip'] ?? '-') ?></td>
      <td><?= View::h($row['activated_at']) ?></td>
      <td><?= View::h($row['last_seen_at'] ?? '-') ?></td>
      <td><?= View::statusBadge((string) $row['status']) ?></td>
      <td>
        <form class="inline-form" method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="q" value="<?= View::h($q) ?>">
          <input type="hidden" name="status" value="<?= View::h($statusFilter) ?>">
          <input type="hidden" name="product" value="<?= (int) $productFilter ?>">
<?php if ($row['status'] === 'active'): ?>
          <input type="hidden" name="do" value="deactivate">
          <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deactivate <?= View::h($row['domain']) ?> for this license?');"><?= View::h(View::t('deactivate')) ?></button>
<?php else: ?>
          <input type="hidden" name="do" value="reactivate">
          <button type="submit" class="btn btn-sub btn-sm"><?= View::h(View::t('active')) ?></button>
<?php endif; ?>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </table>
  <?= View::paginate($total, $pagen, 20, $base) ?>
</div>
