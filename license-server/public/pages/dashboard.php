<?php
/**
 * Admin dashboard: license/product/API statistics and recent activity.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Database;
use ARLM\Settings;

$t = static fn (string $n): string => Database::table($n);

$stats = [
    'total_products' => (int) Database::value('SELECT COUNT(*) FROM ' . $t('products')),
    'total_licenses' => (int) Database::value('SELECT COUNT(*) FROM ' . $t('licenses')),
    'active'         => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'active'"),
    'expired'        => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'expired'"),
    'suspended'      => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'suspended'"),
    'revoked'        => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'revoked'"),
    'pending'        => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'pending'"),
    'activations'    => (int) Database::value("SELECT COUNT(*) FROM " . $t('activations') . " WHERE status = 'active'"),
    'customers'      => (int) Database::value('SELECT COUNT(*) FROM ' . $t('customers')),
    'orders'         => (int) Database::value('SELECT COUNT(*) FROM ' . $t('orders')),
    'api_calls'      => (int) Database::value('SELECT COUNT(*) FROM ' . $t('api_logs')),
];

$recentActivity = Database::all(
    'SELECT action, actor_type, object_type, object_id, details, created_at
     FROM ' . $t('audit_logs') . ' ORDER BY id DESC LIMIT 10'
);
$recentOrders = Database::all(
    'SELECT order_ref, product_id, customer_id, amount, currency, status, created_at
     FROM ' . $t('orders') . ' ORDER BY id DESC LIMIT 10'
);
$perProduct = Database::all(
    'SELECT p.name, COUNT(l.id) AS licenses,
        SUM(CASE WHEN l.status = "active" THEN 1 ELSE 0 END) AS active_licenses
     FROM ' . $t('products') . ' p LEFT JOIN ' . $t('licenses') . ' l ON l.product_id = p.id
     GROUP BY p.id, p.name ORDER BY licenses DESC LIMIT 10'
);
?>
<div class="grid">
<?php foreach ($stats as $label => $value): ?>
  <div class="stat"><h3><?= \ARLM\View::h(str_replace('_', ' ', (string) $label)) ?></h3><p><?= (int) $value ?></p></div>
<?php endforeach; ?>
</div>

<div class="card">
  <h2><?= \ARLM\View::h(\ARLM\View::t('product')) ?> &middot; <?= \ARLM\View::h(\ARLM\View::t('licenses')) ?></h2>
<?php if ($perProduct === []): ?>
  <p class="muted"><?= \ARLM\View::h(\ARLM\View::t('none')) ?></p>
<?php else: ?>
  <table><tr><th><?= \ARLM\View::h(\ARLM\View::t('name')) ?></th><th><?= \ARLM\View::h(\ARLM\View::t('total')) ?></th><th><?= \ARLM\View::h(\ARLM\View::t('active')) ?></th></tr>
  <?php foreach ($perProduct as $row): ?>
    <tr><td><?= \ARLM\View::h($row['name']) ?></td><td><?= (int) $row['licenses'] ?></td><td><?= (int) $row['active_licenses'] ?></td></tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>

<div class="card">
  <h2><?= \ARLM\View::h(\ARLM\View::t('orders')) ?></h2>
<?php if ($recentOrders === []): ?>
  <p class="muted"><?= \ARLM\View::h(\ARLM\View::t('none')) ?></p>
<?php else: ?>
  <table><tr><th><?= \ARLM\View::h('Order') ?></th><th><?= \ARLM\View::h(\ARLM\View::t('product')) ?></th><th><?= \ARLM\View::h(\ARLM\View::t('customer')) ?></th><th><?= \ARLM\View::h('Amount') ?></th><th><?= \ARLM\View::h(\ARLM\View::t('status')) ?></th><th><?= \ARLM\View::h(\ARLM\View::t('date')) ?></th></tr>
  <?php foreach ($recentOrders as $row): ?>
    <tr>
      <td class="mono"><?= \ARLM\View::h($row['order_ref']) ?></td>
      <td><?= (int) $row['product_id'] ?></td>
      <td><?= (int) $row['customer_id'] ?></td>
      <td><?= \ARLM\View::h(number_format((float) $row['amount'], 2)) ?> <?= \ARLM\View::h($row['currency']) ?></td>
      <td><?= \ARLM\View::statusBadge((string) $row['status']) ?></td>
      <td><?= \ARLM\View::h($row['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>

<div class="card">
  <h2><?= \ARLM\View::h('Recent') ?> &middot; <?= \ARLM\View::h('Activity') ?></h2>
<?php if ($recentActivity === []): ?>
  <p class="muted"><?= \ARLM\View::h(\ARLM\View::t('none')) ?></p>
<?php else: ?>
  <table><tr><th><?= \ARLM\View::h('Action') ?></th><th><?= \ARLM\View::h('Actor') ?></th><th><?= \ARLM\View::h('Object') ?></th><th><?= \ARLM\View::h(\ARLM\View::t('details')) ?></th><th><?= \ARLM\View::h(\ARLM\View::t('date')) ?></th></tr>
  <?php foreach ($recentActivity as $row): ?>
    <tr>
      <td class="mono"><?= \ARLM\View::h($row['action']) ?></td>
      <td><?= \ARLM\View::h($row['actor_type']) ?></td>
      <td><?= \ARLM\View::h($row['object_type'] === null ? \ARLM\View::t('none') : $row['object_type'] . ' #' . $row['object_id']) ?></td>
      <td><?= \ARLM\View::h($row['details']) ?></td>
      <td><?= \ARLM\View::h($row['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>
<p class="muted">API: <?= \ARLM\View::h(Settings::get('api_base_url')) ?></p>
