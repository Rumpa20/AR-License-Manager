<?php
/**
 * Settings: all configurable values from section 14 of the spec. Stored in
 * the settings table via Settings::set / setMany. Secrets (key pepper,
 * signing keys, store shared secret) are never shown after creation; the
 * store shared secret can be rotated with a single one-time display.
 * Re-rotating the license key pepper or signing keys is deliberately NOT
 * offered here: it would invalidate every existing client immediately.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Logger;
use ARLM\Settings;
use ARLM\View;

$licenseTypes = ['single_domain', 'multi_domain', 'unlimited_domain', 'monthly', 'annual', 'lifetime', 'trial', 'custom'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'rotate_store_secret') {
        $new = bin2hex(random_bytes(32));
        Settings::set('store_shared_secret', $new);
        Logger::audit('admin', Auth::adminId(), 'store_secret_rotated', 'settings', null);
        View::setFlash('ok', 'Store shared secret rotated.');
        $_SESSION['arlm_store_secret_once'] = $new;
        View::redirect('index.php?page=settings');
    }

    if ($do !== 'save') {
        View::setFlash('error', 'Unknown action.');
        View::redirect('index.php?page=settings');
    }

    $serverUrl = rtrim(trim((string) filter_var(View::post('server_url'), FILTER_VALIDATE_URL) ?: ''), '/');
    $apiInput = preg_replace('#[^a-z0-9:/._-]#i', '', trim(View::post('api_base_url'))) ?? '';
    if ($serverUrl === '' || !str_starts_with($serverUrl, 'https://')) {
        View::setFlash('error', 'Server URL must be a valid https:// URL.');
        View::redirect('index.php?page=settings');
    }
    $apiBase = $apiInput !== '' && str_starts_with($apiInput, 'https://')
        ? rtrim($apiInput, '/')
        : $serverUrl . '/api/v1';

    $days = View::post('default_duration_days') === '' ? '' : (string) max(1, min(36500, (int) View::post('default_duration_days')));
    $lang = preg_replace('/[^a-z_]/', '', strtolower(View::post('language'))) ?: 'english';
    if (!is_file(ARLM_ROOT . '/lang/' . $lang . '.php')) {
        $lang = 'english';
    }
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', View::post('key_prefix')) ?: 'ARLM');
    if (strlen($prefix) < 2 || strlen($prefix) > 10) {
        $prefix = 'ARLM';
    }

    Settings::setMany([
        'server_url'               => $serverUrl,
        'api_base_url'             => $apiBase,
        'default_activation_limit' => (string) max(1, min(100000, (int) View::post('default_activation_limit', '1'))),
        'default_duration_days'    => $days !== '' ? $days : '365',
        'default_license_type'     => in_array(View::post('default_license_type'), $licenseTypes, true) ? View::post('default_license_type') : 'annual',
        'key_prefix'               => $prefix,
        'rate_limit_m'             => (string) max(1, min(600, (int) View::post('rate_limit_m', '60'))),
        'allow_local_domains'      => View::post('allow_local_domains') === '1' ? '1' : '0',
        'maintenance_mode'         => View::post('maintenance_mode') === '1' ? '1' : '0',
        'notify_email'             => (string) filter_var(trim(View::post('notify_email')), FILTER_VALIDATE_EMAIL) ?: '',
        'notify_from'              => (string) filter_var(trim(View::post('notify_from')), FILTER_VALIDATE_EMAIL) ?: '',
        'security_logging'         => View::post('security_logging') === '1' ? '1' : '0',
        'language'                 => $lang,
        'brand'                    => mb_substr(trim(View::post('brand')), 0, 64),
    ]);
    Logger::audit('admin', Auth::adminId(), 'settings_saved', 'settings', null);
    View::setFlash('ok', 'Settings saved.');
    View::redirect('index.php?page=settings');
}

$rotated = $_SESSION['arlm_store_secret_once'] ?? null;
unset($_SESSION['arlm_store_secret_once']);
$langDir = is_dir(ARLM_ROOT . '/lang') ? scandir(ARLM_ROOT . '/lang') : [];
$languages = [];
foreach ((array) $langDir as $f) {
    if (is_string($f) && preg_match('/^([a-z_]+)\.php$/', $f, $m) && is_file(ARLM_ROOT . '/lang/' . $f)) {
        $languages[] = $m[1];
    }
}
if ($languages === []) {
    $languages = ['english'];
}
$e = static fn (string $name, string $default): string => View::h(Settings::get($name, $default));
$chk = static fn (string $name): bool => Settings::get($name, '0') === '1';
?>
<h1><?= View::h(View::t('settings')) ?></h1>

<?php if ($rotated !== null): ?>
<div class="card">
  <h2>New store shared secret</h2>
  <p class="alert warn"><?= View::h(View::t('secret_once')) ?></p>
  <p class="secret-box"><?= View::h($rotated) ?></p>
  <p class="muted">Update this value in your store integration settings immediately - the old secret no longer works.</p>
</div>
<?php endif; ?>

<form class="card" method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="save">
  <h2>Server</h2>
  <div class="filters">
    <div><label>Server URL (https)</label><input type="url" name="server_url" value="<?= $e('server_url', 'https://www.abruar.online/arlm') ?>" required></div>
    <div><label>API Base URL</label><input type="text" name="api_base_url" value="<?= $e('api_base_url', Settings::get('server_url') . '/api/v1') ?>"></div>
  </div>
  <h2>Licenses</h2>
  <div class="filters">
    <div><label><?= View::h(View::t('activation_limit')) ?> (default)</label><input type="number" name="default_activation_limit" min="1" max="100000" value="<?= View::h(Settings::get('default_activation_limit', '1')) ?>"></div>
    <div><label><?= View::h(View::t('duration_days')) ?> (default)</label><input type="number" name="default_duration_days" min="1" max="36500" value="<?= View::h(Settings::get('default_duration_days', '365')) ?>"></div>
    <div><label><?= View::h(View::t('license_type')) ?> (default)</label>
      <select name="default_license_type">
<?php foreach ($licenseTypes as $lt): ?>
        <option value="<?= View::h($lt) ?>" <?= Settings::get('default_license_type', 'annual') === $lt ? 'selected' : '' ?>><?= View::h(str_replace('_', ' ', $lt)) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div><label>Key prefix</label><input type="text" name="key_prefix" value="<?= $e('key_prefix', 'ARLM') ?>" maxlength="10"></div>
  </div>
  <h2>Security</h2>
  <div class="filters">
    <div><label><?= View::h(View::t('rate_limit')) ?> (default)</label><input type="number" name="rate_limit_m" min="1" max="600" value="<?= View::h(Settings::get('rate_limit_m', '60')) ?>"></div>
    <div><label>Allow localhost / staging domains</label>
      <select name="allow_local_domains">
        <option value="0" <?= !$chk('allow_local_domains') ? 'selected' : '' ?>><?= View::h(View::t('no')) ?></option>
        <option value="1" <?= $chk('allow_local_domains') ? 'selected' : '' ?>><?= View::h(View::t('yes')) ?></option>
      </select>
    </div>
    <div><label>Security logging</label>
      <select name="security_logging">
        <option value="1" <?= $chk('security_logging') ? 'selected' : '' ?>><?= View::h(View::t('yes')) ?></option>
        <option value="0" <?= !$chk('security_logging') ? 'selected' : '' ?>><?= View::h(View::t('no')) ?></option>
      </select>
    </div>
    <div><label>Maintenance mode (reject all API calls)</label>
      <select name="maintenance_mode">
        <option value="0" <?= !$chk('maintenance_mode') ? 'selected' : '' ?>><?= View::h(View::t('no')) ?></option>
        <option value="1" <?= $chk('maintenance_mode') ? 'selected' : '' ?>><?= View::h(View::t('yes')) ?></option>
      </select>
    </div>
  </div>
  <h2>Notifications and language</h2>
  <div class="filters">
    <div><label>Notification email (to)</label><input type="email" name="notify_email" value="<?= $e('notify_email', '') ?>"></div>
    <div><label>Notification from address</label><input type="email" name="notify_from" value="<?= $e('notify_from', '') ?>"></div>
    <div><label>Language</label>
      <select name="language">
<?php foreach ($languages as $langOpt): ?>
        <option value="<?= View::h($langOpt) ?>" <?= Settings::get('language', 'english') === $langOpt ? 'selected' : '' ?>><?= View::h($langOpt) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div><label>Brand name</label><input type="text" name="brand" value="<?= $e('brand', 'ARNIT CREATION') ?>" maxlength="64"></div>
  </div>
  <button type="submit"><?= View::h(View::t('save')) ?></button>
</form>

<form class="card" method="post" onsubmit="return confirm('Rotate the store shared secret? The store integration must be updated immediately or webhook fulfillment will fail.');">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="rotate_store_secret">
  <h2>Store integration secret</h2>
  <p class="muted">Used by your store to sign purchase notifications (HMAC-SHA256). Rotating displays the new value once.</p>
  <button type="submit" class="btn btn-sub">Rotate store secret</button>
</form>
