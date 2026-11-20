<?php
/**
 * Reports: monthly license generation (last 12 months), active vs expired
 * totals, product-wise usage, activation statistics, API usage and order
 * fulfillment. CSV export requires a logged-in admin (enforced in View::csv).
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);

$export = View::query('export');
if ($export === 'csv') {
    $what = View::query('what');
    if ($what === 'monthly') {
        $rows = \ARLM\Database::all(
            "SELECT DATE_FORMAT(issued_at, '%Y-%m') AS month, COUNT(*) AS generated,
                    SUM(status = 'active') AS active, SUM(status = 'expired') AS expired,
                    SUM(status = 'suspended') AS suspended, SUM(status = 'revoked') AS revoked
             FROM " . $t('licenses') . "
             WHERE issued_at >= (NOW() - INTERVAL 12 MONTH)
             GROUP BY month ORDER BY month DESC"
        );
        View::csv(['month', 'generated', 'active', 'expired', 'suspended', 'revoked'], $rows, 'arlm-report-monthly.csv');
    }
    if ($what === 'products') {
        $rows = \ARLM\Database::all(
            'SELECT p.id, p.product_uid, p.name, COUNT(l.id) AS licenses,
                    SUM(l.status = \'active\') AS active,
                    SUM(l.max_activations) AS capacity,
                    (SELECT COUNT(*) FROM ' . $t('activations') . ' a JOIN ' . $t('licenses') . ' l2 ON l2.id = a.license_id WHERE l2.product_id = p.id AND a.status = \'active\') AS active_activations
             FROM ' . $t('products') . ' p
             LEFT JOIN ' . $t('licenses') . ' l ON l.product_id = p.id
             GROUP BY p.id ORDER BY licenses DESC'
        );
        View::csv(['product_id', 'product_uid', 'name', 'licenses', 'active', 'capacity', 'active_activations'], $rows, 'arlm-report-products.csv');
    }
    if ($what === 'api') {
        $rows = \ARLM\Database::all(
            "SELECT endpoint, method, COUNT(*) AS requests,
                    SUM(status_code <> 200) AS errors,
                    ROUND(AVG(duration_ms), 1) AS avg_ms
             FROM " . $t('api_logs') . "
             WHERE created_at >= (NOW() - INTERVAL 30 DAY)
             GROUP BY endpoint, method ORDER BY requests DESC"
        );
        View::csv(['endpoint', 'method', 'requests', 'errors', 'avg_ms'], $rows, 'arlm-report-api.csv');
    }
}

$licenseTotals = \ARLM\Database::one(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active, SUM(status = 'expired') AS expired,
            SUM(status = 'suspended') AS suspended, SUM(status = 'revoked') AS revoked,
            SUM(status = 'pending') AS pending
     FROM " . $t('licenses')
) ?? [];
$monthly = \ARLM\Database::all(
    "SELECT DATE_FORMAT(issued_at, '%Y-%m') AS month, COUNT(*) AS generated,
            SUM(status = 'active') AS active, SUM(status = 'expired') AS expired
     FROM " . $t('licenses') . "
     WHERE issued_at >= (NOW() - INTERVAL 12 MONTH)
     GROUP BY month ORDER BY month DESC"
);
$productUsage = \ARLM\Database::all(
    'SELECT p.name, p.product_uid, COUNT(l.id) AS licenses,
            SUM(l.status = \'active\') AS active,
            COALESCE(SUM(l.current_activations), 0) AS activations
     FROM ' . $t('products') . ' p
     LEFT JOIN ' . $t('licenses') . ' l ON l.product_id = p.id
     GROUP BY p.id ORDER BY licenses DESC'
);
$activationStats = \ARLM\Database::one(
    "SELECT COUNT(*) AS total, SUM(status = 'active') AS active_now, SUM(status = 'deactivated') AS deactivated_total,
            SUM(activated_at >= (NOW() - INTERVAL 30 DAY)) AS last_30d
     FROM " . $t('activations')
) ?? [];
$apiUsage = \ARLM\Database::all(
    "SELECT endpoint, COUNT(*) AS requests, SUM(status_code <> 200) AS errors
     FROM " . $t('api_logs') . "
     WHERE created_at >= (NOW() - INTERVAL 30 DAY)
     GROUP BY endpoint ORDER BY requests DESC"
);
$orderStats = \ARLM\Database::one(
    "SELECT COUNT(*) AS total_orders,
            SUM(status = 'paid') AS paid_waiting, SUM(status = 'fulfilled') AS fulfilled,
            SUM(status = 'failed') AS failed, SUM(status = 'refunded') AS refunded,
            ROUND(SUM(status IN ('paid','fulfilled')) * amount, 2) AS amount_collected
     FROM " . $t('orders')
) ?? [];
$securityRecent = \ARLM\Database::all(
    "SELECT event, severity, COUNT(*) AS hits
     FROM " . $t('security_logs') . "
     WHERE created_at >= (NOW() - INTERVAL 30 DAY)
     GROUP BY event, severity ORDER BY hits DESC LIMIT 15"
);
?>
<h1><?= View::h(View::t('reports')) ?></h1>

<h2><?= View::h(View::t('license')) ?> - status overview</h2>
<div class="grid">
  <div class="stat"><h3><?= View::h(View::t('total')) ?></h3><p><?= (int) ($licenseTotals['total'] ?? 0) ?></p></div>
  <div class="stat"><h3><?= View::h(View::t('active')) ?></h3><p><?= (int) ($licenseTotals['active'] ?? 0) ?></p></div>
  <div class="stat"><h3><?= View::h(View::t('expired')) ?></h3><p><?= (int) ($licenseTotals['expired'] ?? 0) ?></p></div>
  <div class="stat"><h3><?= View::h(View::t('suspended')) ?></h3><p><?= (int) ($licenseTotals['suspended'] ?? 0) ?></p></div>
  <div class="stat"><h3><?= View::h(View::t('revoked')) ?></h3><p><?= (int) ($licenseTotals['revoked'] ?? 0) ?></p></div>
</div>

<div class="card">
  <h2>Monthly generation (12 months)
    <a class="btn btn-sub btn-sm" href="index.php?page=reports&export=csv&what=monthly"><?= View::h(View::t('export_csv')) ?></a>
  </h2>
  <table>
    <tr><th>Month</th><th>Generated</th><th><?= View::h(View::t('active')) ?></th><th><?= View::h(View::t('expired')) ?></th></tr>
<?php foreach ($monthly as $m): ?>
    <tr><td><?= View::h($m['month']) ?></td><td><?= (int) $m['generated'] ?></td><td><?= (int) $m['active'] ?></td><td><?= (int) $m['expired'] ?></td></tr>
<?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Product-wise usage
    <a class="btn btn-sub btn-sm" href="index.php?page=reports&export=csv&what=products"><?= View::h(View::t('export_csv')) ?></a>
  </h2>
  <table>
    <tr><th><?= View::h(View::t('product')) ?></th><th>UID</th><th><?= View::h(View::t('total')) ?> <?= View::h(View::t('license')) ?></th><th><?= View::h(View::t('active')) ?></th><th><?= View::h(View::t('activations')) ?> in use</th></tr>
<?php foreach ($productUsage as $pr): ?>
    <tr>
      <td><?= View::h($pr['name']) ?></td><td class="mono"><?= View::h($pr['product_uid']) ?></td>
      <td><?= (int) $pr['licenses'] ?></td><td><?= (int) $pr['active'] ?></td><td><?= (int) $pr['activations'] ?></td>
    </tr>
<?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2><?= View::h(View::t('activations')) ?></h2>
  <div class="grid">
    <div class="stat"><h3><?= View::h(View::t('total')) ?></h3><p><?= (int) ($activationStats['total'] ?? 0) ?></p></div>
    <div class="stat"><h3>Active now</h3><p><?= (int) ($activationStats['active_now'] ?? 0) ?></p></div>
    <div class="stat"><h3>Deactivated ever</h3><p><?= (int) ($activationStats['deactivated_total'] ?? 0) ?></p></div>
    <div class="stat"><h3>Last 30 days</h3><p><?= (int) ($activationStats['last_30d'] ?? 0) ?></p></div>
  </div>
</div>

<div class="card">
  <h2>API usage (30 days)
    <a class="btn btn-sub btn-sm" href="index.php?page=reports&export=csv&what=api"><?= View::h(View::t('export_csv')) ?></a>
  </h2>
  <table>
    <tr><th><?= View::h(View::t('endpoint')) ?></th><th>Requests</th><th>Errors</th></tr>
<?php foreach ($apiUsage as $u): ?>
    <tr><td class="mono"><?= View::h($u['endpoint']) ?></td><td><?= (int) $u['requests'] ?></td><td><?= (int) $u['errors'] ?></td></tr>
<?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Order fulfillment</h2>
  <div class="grid">
    <div class="stat"><h3><?= View::h(View::t('total')) ?></h3><p><?= (int) ($orderStats['total_orders'] ?? 0) ?></p></div>
    <div class="stat"><h3>Paid, awaiting license</h3><p><?= (int) ($orderStats['paid_waiting'] ?? 0) ?></p></div>
    <div class="stat"><h3>Fulfilled</h3><p><?= (int) ($orderStats['fulfilled'] ?? 0) ?></p></div>
    <div class="stat"><h3>Amount collected</h3><p><?= View::h(number_format((float) ($orderStats['amount_collected'] ?? 0), 2)) ?></p></div>
  </div>
</div>

<div class="card">
  <h2>Security events (30 days)</h2>
  <table>
    <tr><th><?= View::h(View::t('event')) ?></th><th><?= View::h(View::t('severity')) ?></th><th>Hits</th></tr>
<?php foreach ($securityRecent as $s): ?>
    <tr><td class="mono"><?= View::h($s['event']) ?></td><td><?= View::statusBadge((string) $s['severity']) ?></td><td><?= (int) $s['hits'] ?></td></tr>
<?php endforeach; ?>
  </table>
</div>
