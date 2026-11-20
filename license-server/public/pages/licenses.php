<?php
/**
 * All Licenses: search/filter/sort, bulk actions, row actions (suspend,
 * reactivate, revoke, extend, reset activations, change limit), CSV export.
 * Raw keys are never listed (hash-only storage; last-4 reference only).
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Logger;
use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$statuses = ['active', 'expired', 'suspended', 'revoked', 'pending'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);
    $do = (string) ($_POST['do'] ?? '');
    $back = 'index.php?page=licenses&q=' . urlencode(View::query('q')) . '&status=' . urlencode(View::query('status')) . '&product=' . (int) View::query('product');

    if ($do === 'bulk_status') {
        $to = (string) ($_POST['to'] ?? '');
        if (!in_array($to, ['suspended', 'active', 'revoked'], true)) {
            View::setFlash('error', 'Unknown bulk action.');
        } else {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn ($i) => $i > 0));
            if ($ids === []) {
                View::setFlash('warn', 'No licenses selected.');
            } else {
                \ARLM\Database::run('UPDATE ' . $t('licenses') . ' SET status = ? WHERE id IN (' . implode(',', $ids) . ')', [$to]);
                foreach ($ids as $id) {
                    Logger::audit('admin', Auth::adminId(), 'license_set_' . $to, 'license', $id);
                }
                View::setFlash('ok', count($ids) . ' license(s) set to ' . $to . '.');
            }
        }
        View::redirect($back);
    }

    $id = (int) View::post('id');
    $license = $id > 0 ? \ARLM\Database::one('SELECT * FROM ' . $t('licenses') . ' WHERE id = ?', [$id]) : null;
    if ($license === null) {
        View::setFlash('error', 'License not found.');
        View::redirect($back);
    }

    switch ($do) {
        case 'set_status':
            $to = (string) ($_POST['to'] ?? '');
            if (in_array($to, ['active', 'suspended', 'revoked', 'pending'], true)) {
                \ARLM\Database::run('UPDATE ' . $t('licenses') . ' SET status = ? WHERE id = ?', [$to, $id]);
                Logger::audit('admin', Auth::adminId(), 'license_set_' . $to, 'license', $id);
                View::setFlash('ok', 'License #' . $id . ' set to ' . $to . '.');
            } else {
                View::setFlash('error', 'Unknown status.');
            }
            break;

        case 'extend':
            $days = (int) View::post('days', '0');
            $fromExpiry = View::post('from_expiry') === '1';
            if ($days > 0 && $days < 3650) {
                $base = $fromExpiry ? 'COALESCE(expires_at, NOW())' : 'NOW()';
                \ARLM\Database::run(
                    "UPDATE " . $t('licenses') . "
                     SET expires_at = $base + INTERVAL ? DAY, status = IF(status = 'expired', 'active', status)
                     WHERE id = ?",
                    [$days, $id]
                );
                $after = (string) \ARLM\Database::value('SELECT expires_at FROM ' . $t('licenses') . ' WHERE id = ?', [$id]);
                Logger::audit('admin', Auth::adminId(), 'license_extended', 'license', $id, 'new expiry: ' . $after);
                View::setFlash('ok', 'License #' . $id . ' extended to ' . $after . '.');
            } else {
                View::setFlash('error', 'Days must be between 1 and 3650.');
            }
            break;

        case 'reset_activations':
            \ARLM\Database::begin();
            try {
                \ARLM\Database::run("UPDATE " . $t('activations') . " SET status = 'deactivated', deactivated_at = NOW() WHERE license_id = ? AND status = 'active'", [$id]);
                \ARLM\Database::run('UPDATE ' . $t('licenses') . ' SET current_activations = 0 WHERE id = ?', [$id]);
                \ARLM\Database::commit();
                Logger::audit('admin', Auth::adminId(), 'license_activations_reset', 'license', $id);
                View::setFlash('ok', 'All activations reset for license #' . $id . '.');
            } catch (\Throwable) {
                \ARLM\Database::rollBack();
                View::setFlash('error', 'Reset failed.');
            }
            break;

        case 'change_limit':
            $limit = (int) View::post('max_activations', '1');
            if ($limit >= 0 && $limit <= 100000) {
                \ARLM\Database::run('UPDATE ' . $t('licenses') . ' SET max_activations = ? WHERE id = ?', [$limit, $id]);
                Logger::audit('admin', Auth::adminId(), 'license_limit_changed', 'license', $id, 'new limit: ' . $limit);
                View::setFlash('ok', 'Activation limit updated for license #' . $id . '.');
            } else {
                View::setFlash('error', 'Limit must be 0-100000.');
            }
            break;
    }
    View::redirect($back);
}

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all(
        'SELECT l.id, l.key_last4, p.name AS product, c.email AS customer, l.license_type, l.max_activations, l.current_activations, l.status, l.issued_at, l.expires_at
         FROM ' . $t('licenses') . ' l
         JOIN ' . $t('products') . ' p ON p.id = l.product_id
         JOIN ' . $t('customers') . ' c ON c.id = l.customer_id
         ORDER BY l.id DESC'
    );
    View::csv(
        ['license_id', 'key_last4', 'product', 'customer', 'type', 'max_activations', 'current_activations', 'status', 'issued_at', 'expires_at'],
        $rows,
        'arlm-licenses.csv'
    );
}

[$sortCol, $sortDir] = View::sort(['id', 'status', 'issued_at', 'expires_at'], 'id');

$where = ['1=1'];
$args = [];
$q = View::query('q');
if ($q !== '') {
    $where[] = '(c.email LIKE ? OR p.name LIKE ? OR l.key_last4 = ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '', -4));
}
$statusFilter = in_array(View::query('status'), $statuses, true) ? View::query('status') : '';
if ($statusFilter !== '') {
    $where[] = 'l.status = ?';
    $args[] = $statusFilter;
}
$productFilter = (int) View::query('product');
if ($productFilter > 0) {
    $where[] = 'l.product_id = ?';
    $args[] = $productFilter;
}
$whereSql = implode(' AND ', $where);

$total = (int) \ARLM\Database::value(
    "SELECT COUNT(*) FROM " . $t('licenses') . " l
     JOIN " . $t('products') . " p ON p.id = l.product_id
     JOIN " . $t('customers') . " c ON c.id = l.customer_id WHERE {$whereSql}",
    $args
);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT l.id, l.key_last4, l.license_type, l.max_activations, l.current_activations, l.status, l.expires_at,
            p.name AS product_name, c.email AS customer_email
     FROM " . $t('licenses') . " l
     JOIN " . $t('products') . " p ON p.id = l.product_id
     JOIN " . $t('customers') . " c ON c.id = l.customer_id
     WHERE {$whereSql} ORDER BY l.{$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ', 20',
    $args
);
$products = \ARLM\Database::all('SELECT id, name FROM ' . $t('products') . ' ORDER BY name');
?>
<h1><?= View::h(View::t('all_licenses')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="licenses">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h($q) ?>" placeholder="email / product / last-4"></div>
  <div><label><?= View::h(View::t('status')) ?></label>
    <select name="status"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($statuses as $s): ?>
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
  <a class="btn btn-sub" href="index.php?page=licenses&export=csv"><?= View::h(View::t('export_csv')) ?></a>
</form>

<form method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="bulk_status">
  <input type="hidden" name="q" value="<?= View::h($q) ?>">
  <input type="hidden" name="status" value="<?= View::h($statusFilter) ?>">
  <input type="hidden" name="product" value="<?= (int) $productFilter ?>">
  <div class="card">
    <div class="filters">
      <button type="submit" name="to" value="suspended"><?= View::h(View::t('suspended')) ?></button>
      <button type="submit" name="to" value="active"><?= View::h(View::t('active')) ?></button>
      <button type="submit" name="to" value="revoked" onclick="return confirm('Revoke the selected licenses? Customers will be locked out.');"><?= View::h(View::t('revoked')) ?></button>
      <span class="muted">select rows first</span>
    </div>
    <table>
      <tr>
        <th><input type="checkbox" onclick="var c=this.closest('table').querySelectorAll('input[name=\'ids[]\']');for(var i=0;i<c.length;i++)c[i].checked=this.checked;"></th>
        <th>ID</th><th><?= View::h(View::t('license')) ?></th><th><?= View::h(View::t('product')) ?></th><th><?= View::h(View::t('customer')) ?></th>
        <th><?= View::h(View::t('type')) ?></th><th><?= View::h(View::t('activations')) ?></th>
        <th><a href="index.php?page=licenses&sort=expires_at&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('expires_at')) ?></a></th>
        <th><a href="index.php?page=licenses&sort=status&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('status')) ?></a></th>
      </tr>
<?php foreach ($rows as $row): ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>"></td>
        <td><?= (int) $row['id'] ?></td>
        <td class="mono">&bull;&bull;&bull;&bull;<?= View::h($row['key_last4']) ?></td>
        <td><?= View::h($row['product_name']) ?></td>
        <td><a href="index.php?page=customers&q=<?= View::h(urlencode((string) $row['customer_email'])) ?>"><?= View::h($row['customer_email']) ?></a></td>
        <td><?= View::h(str_replace('_', ' ', (string) $row['license_type'])) ?></td>
        <td><?= (int) $row['current_activations'] ?>/<?= (int) $row['max_activations'] ?></td>
        <td><?= $row['expires_at'] === null ? '?' : View::h($row['expires_at']) ?></td>
        <td><?= View::statusBadge((string) $row['status']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <?= View::paginate($total, $pagen, 20, 'index.php?page=licenses&q=' . urlencode($q) . '&status=' . urlencode($statusFilter) . '&product=' . $productFilter) ?>
  </div>
</form>

<h2><?= View::h(View::t('actions')) ?></h2>
<form class="card" method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="set_status">
  <div class="filters">
    <div><label>License ID</label><input type="number" name="id" required></div>
    <div><label><?= View::h(View::t('status')) ?></label>
      <select name="to">
        <option value="active"><?= View::h(View::t('active')) ?></option>
        <option value="suspended"><?= View::h(View::t('suspended')) ?></option>
        <option value="revoked"><?= View::h(View::t('revoked')) ?></option>
        <option value="pending"><?= View::h(View::t('pending')) ?></option>
      </select>
    </div>
    <button type="submit"><?= View::h(View::t('save')) ?></button>
  </div>
</form>

<form class="card" method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="extend">
  <div class="filters">
    <div><label>License ID</label><input type="number" name="id" required></div>
    <div><label><?= View::h(View::t('duration_days')) ?></label><input type="number" name="days" min="1" max="3650" value="30"></div>
    <div><label>Base</label>
      <select name="from_expiry">
        <option value="1">from current expiry</option>
        <option value="0">from today</option>
      </select>
    </div>
    <button type="submit">Extend</button>
  </div>
</form>

<form class="card" method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="change_limit">
  <div class="filters">
    <div><label>License ID</label><input type="number" name="id" required></div>
    <div><label><?= View::h(View::t('activation_limit')) ?></label><input type="number" name="max_activations" min="0" max="100000" value="1"></div>
    <button type="submit"><?= View::h(View::t('save')) ?></button>
  </div>
</form>

<form class="card" method="post" onsubmit="return confirm('Deactivate ALL activations of this license and zero the counter?');">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="reset_activations">
  <div class="filters">
    <div><label>License ID</label><input type="number" name="id" required></div>
    <button type="submit" class="btn-danger">Reset activations</button>
  </div>
</form>
