<?php
/**
 * System Information: read-only environment report - PHP version, required
 * extensions, database server version, table row counts and install date.
 * Used to verify host compatibility before filing a support request.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Settings;
use ARLM\View;

$extOk = static fn (string $name): array => [extension_loaded($name), $name];
$extensions = [
    $extOk('sodium'),
    $extOk('pdo_mysql'),
    $extOk('mbstring'),
    $extOk('json'),
    $extOk('openssl'),
    $extOk('curl'),
    $extOk('intl'),
];

$dbVersion = 'unknown';
try {
    $dbVersion = (string) (\ARLM\Database::value('SELECT VERSION()') ?? 'unknown');
} catch (Throwable) {
}

$tables = [
    'settings' => 'Configuration key/value store',
    'products' => 'Digital products',
    'customers' => 'Customers (SMF + external)',
    'licenses' => 'Issued licenses (hash-only keys)',
    'activations' => 'Domain activations',
    'orders' => 'Store purchase ledger',
    'api_clients' => 'REST API clients',
    'api_logs' => 'API request log / idempotency',
    'security_logs' => 'Security events',
    'audit_logs' => 'Admin & system audit trail',
    'admins' => 'Dashboard administrator accounts',
];
$counts = [];
foreach (array_keys($tables) as $name) {
    try {
        $counts[$name] = (int) \ARLM\Database::value('SELECT COUNT(*) FROM ' . \ARLM\Database::table($name));
    } catch (Throwable) {
        $counts[$name] = null;
    }
}

$logsWritable = is_writable(ARLM_ROOT . '/../storage/logs');
$sgLoaded = function_exists('sodium_crypto_sign_verify_detached');
$serverUrl = Settings::get('server_url');
$apiBase = Settings::get('api_base_url');
?>
<h1><?= View::h(View::t('system')) ?></h1>

<div class="grid">
  <div class="stat"><h3>PHP version</h3><p><?= View::h(PHP_VERSION) ?></p></div>
  <div class="stat"><h3>Interface</h3><p><?= View::h(PHP_SAPI) ?></p></div>
  <div class="stat"><h3>MySQL</h3><p style="font-size:1rem"><?= View::h($dbVersion) ?></p></div>
  <div class="stat"><h3>Ed25519 (sodium)</h3><p><?= $sgLoaded ? 'OK' : 'MISSING' ?></p></div>
</div>

<div class="card">
  <h2>PHP extensions</h2>
  <table>
    <tr><th>Extension</th><th>Status</th><th>Required</th></tr>
<?php foreach ($extensions as [$ok, $name]): ?>
    <tr>
      <td class="mono"><?= View::h($name) ?></td>
      <td><?= View::statusBadge($ok ? 'active' : 'failed') ?><?= View::h(View::t($ok ? 'yes' : 'no')) ?></td>
      <td><?= in_array($name, ['sodium', 'pdo_mysql', 'mbstring'], true) ? 'Yes' : 'Optional' ?></td>
    </tr>
<?php endforeach; ?>
    <tr><td class="mono">sodium verify function</td><td><?= View::statusBadge($sgLoaded ? 'active' : 'failed') ?><?= View::h(View::t($sgLoaded ? 'yes' : 'no')) ?></td><td>Yes</td></tr>
  </table>
</div>

<div class="card">
  <h2>Database tables</h2>
  <table>
    <tr><th>Table</th><th>Purpose</th><th>Rows</th></tr>
<?php foreach ($tables as $name => $purpose): ?>
    <tr>
      <td class="mono"><?= View::h(\ARLM\Database::table($name)) ?></td>
      <td><?= View::h($purpose) ?></td>
      <td><?= $counts[$name] === null ? '<span class="badge st-failed">error</span>' : (int) $counts[$name] ?></td>
    </tr>
<?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Configuration</h2>
  <table>
    <tr><th>Item</th><th>Value</th></tr>
    <tr><td>Server URL</td><td class="mono"><?= View::h($serverUrl) ?></td></tr>
    <tr><td>API Base URL</td><td class="mono"><?= View::h($apiBase) ?></td></tr>
    <tr><td>Log directory writable</td><td><?= View::h(View::t($logsWritable ? 'yes' : 'no')) ?></td></tr>
    <tr><td>Maintenance mode</td><td><?= View::statusBadge(Settings::get('maintenance_mode', '0') === '1' ? 'pending' : 'active') ?><?= View::h(Settings::get('maintenance_mode', '0') === '1' ? 'on' : 'off') ?></td></tr>
    <tr><td>Installed at</td><td><?= View::h(Settings::get('installed_at', '-')) ?></td></tr>
    <tr><td>Local / staging domains allowed</td><td><?= View::h(View::t(Settings::get('allow_local_domains', '0') === '1' ? 'yes' : 'no')) ?></td></tr>
  </table>
  <p class="muted">Secrets (database password, key pepper, Ed25519 private key, store shared secret) are never displayed here. Rotate the store secret from the Settings page.</p>
</div>
