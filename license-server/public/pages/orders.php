<?php
/**
 * Orders: read-only ledger created by the store webhook. Shows amount,
 * transaction, product, customer and fulfillment state. Historical order
 * records are never deleted or rewritten here; the webhook owns the
 * pending -> paid -> fulfilled lifecycle.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$states = ['pending', 'paid', 'failed', 'fulfilled', 'refunded', 'blocked'];

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all(
        'SELECT o.id, o.order_ref, o.txn_id, o.store_name, p.name AS product, c.email AS customer,
                o.amount, o.currency, o.status, o.paid_at, o.fulfilled_at, o.created_at
         FROM ' . $t('orders') . ' o
         LEFT JOIN ' . $t('products') . ' p ON p.id = o.product_id
         LEFT JOIN ' . $t('customers') . " c ON c.id = o.customer_id
         ORDER BY o.id DESC"
    );
    View::csv(
        ['id', 'order_ref', 'txn_id', 'store', 'product', 'customer', 'amount', 'currency', 'status', 'paid_at', 'fulfilled_at', 'created_at'],
        $rows,
        'arlm-orders.csv'
    );
}

[$sortCol, $sortDir] = View::sort(['id', 'status', 'created_at', 'paid_at'], 'id');

$where = ['1=1'];
$args = [];
$q = View::query('q');
if ($q !== '') {
    $where[] = '(o.order_ref LIKE ? OR o.txn_id LIKE ? OR c.email LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
$statusFilter = in_array(View::query('status'), $states, true) ? View::query('status') : '';
if ($statusFilter !== '') {
    $where[] = 'o.status = ?';
    $args[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$total = (int) \ARLM\Database::value(
    'SELECT COUNT(*) FROM ' . $t('orders') . " o LEFT JOIN " . $t('customers') . " c ON c.id = o.customer_id WHERE {$whereSql}",
    $args
);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT o.id, o.order_ref, o.txn_id, o.amount, o.currency, o.status, o.paid_at, o.fulfilled_at, o.license_id,
            p.name AS product_name, c.email AS customer_email
     FROM " . $t('orders') . " o
     LEFT JOIN " . $t('products') . " p ON p.id = o.product_id
     LEFT JOIN " . $t('customers') . " c ON c.id = o.customer_id
     WHERE {$whereSql} ORDER BY o.{$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ', 20',
    $args
);
$base = 'index.php?page=orders&q=' . urlencode($q) . '&status=' . urlencode($statusFilter);
?>
<h1><?= View::h(View::t('orders')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="orders">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h($q) ?>" placeholder="order ref / txn / email"></div>
  <div><label><?= View::h(View::t('status')) ?></label>
    <select name="status"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($states as $s): ?>
      <option value="<?= View::h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= View::h(View::t($s)) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit"><?= View::h(View::t('filter')) ?></button>
  <a class="btn btn-sub" href="index.php?page=orders&export=csv"><?= View::h(View::t('export_csv')) ?></a>
</form>

<div class="card">
  <table>
    <tr>
      <th><a href="index.php?page=orders&sort=id&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">ID</a></th>
      <th>Order Ref</th><th>Txn</th><th><?= View::h(View::t('product')) ?></th><th><?= View::h(View::t('customer')) ?></th>
      <th>Amount</th><th><?= View::h(View::t('license')) ?></th>
      <th><a href="index.php?page=orders&sort=status&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('status')) ?></a></th>
      <th><a href="index.php?page=orders&sort=created_at&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('created')) ?></a></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td class="mono"><?= View::h($row['order_ref']) ?></td>
      <td class="mono"><?= View::h(substr((string) ($row['txn_id'] ?? ''), 0, 24)) ?></td>
      <td><?= View::h($row['product_name'] ?? '-') ?></td>
      <td><?= View::h($row['customer_email'] ?? '-') ?></td>
      <td><?= View::h($row['currency']) ?> <?= View::h(number_format((float) $row['amount'], 2)) ?></td>
      <td><?= $row['license_id'] !== null ? '#' . (int) $row['license_id'] : '-' ?></td>
      <td><?= View::statusBadge((string) $row['status']) ?></td>
      <td><?= View::h($row['created_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </table>
  <?= View::paginate($total, $pagen, 20, $base) ?>
</div>
<p class="muted">Orders are fulfilled automatically by the signed store webhook. Pending orders whose payment was never confirmed stay pending and can be refunded in the store.</p>
