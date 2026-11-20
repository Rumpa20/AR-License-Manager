<?php
/**
 * Generate License: creates/reuses a customer, generates a key, stores only
 * the hash, and shows the plaintext key exactly once via session flash.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\LicenseKey;
use ARLM\Logger;
use ARLM\Settings;
use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$licenseTypes = ['single_domain', 'multi_domain', 'unlimited_domain', 'monthly', 'annual', 'lifetime', 'trial', 'custom'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);

    $productId = (int) View::post('product');
    $type = in_array(View::post('license_type'), $licenseTypes, true) ? View::post('license_type') : (string) Settings::get('default_license_type', 'annual');
    $limit = max(1, (int) View::post('activation_limit', (string) max(1, (int) Settings::get('default_activation_limit', '1'))));
    $days = View::post('duration_days') === '' ? null : max(1, (int) View::post('duration_days'));
    if ($days === null && $type !== 'lifetime') {
        $days = max(1, (int) Settings::get('default_duration_days', '365'));
    }
    if ($type === 'lifetime') {
        $days = null;
    }
    $email = filter_var(trim(View::post('email')), FILTER_VALIDATE_EMAIL) ?: '';
    $name = mb_substr(trim(View::post('name')), 0, 191);
    $source = View::post('source') === 'smf' ? 'smf' : 'external';
    $smfMemberId = $source === 'smf' && View::post('smf_member_id') !== '' ? max(0, (int) View::post('smf_member_id')) : null;
    $notes = mb_substr(View::post('notes'), 0, 2000);
    $email = View::post('notify') === '1' && View::post('notify_to') !== '' ? (string) filter_var(trim(View::post('notify_to')), FILTER_VALIDATE_EMAIL) : $email;

    $product = \ARLM\Database::one("SELECT * FROM " . $t('products') . " WHERE id = ? AND status = 'active'", [$productId]);
    if ($product === null) {
        View::setFlash('error', 'Select a valid active product.');
        View::redirect('index.php?page=generate');
    }
    if ($email === '') {
        View::setFlash('error', 'A valid customer email is required.');
        View::redirect('index.php?page=generate');
    }

    $plain = '';
    \ARLM\Database::begin();
    try {
        $customer = \ARLM\Database::one('SELECT id, status FROM ' . $t('customers') . ' WHERE email = ?', [$email]);
        if ($customer === null) {
            \ARLM\Database::run(
                'INSERT INTO ' . $t('customers') . ' (smf_member_id, email, name, source, status) VALUES (?, ?, ?, ?, \'active\')',
                [$smfMemberId, $email, $name !== '' ? $name : null, $source]
            );
            $customerId = (int) \ARLM\Database::pdo()->lastInsertId();
            Logger::audit('admin', Auth::adminId(), 'customer_created', 'customer', $customerId, $email);
        } else {
            $customerId = (int) $customer['id'];
            if ($customer['status'] !== 'active') {
                throw new RuntimeException('Customer is ' . $customer['status'] . '. Re-activate the customer first.');
            }
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plain = LicenseKey::generate(Settings::get('key_prefix', 'ARLM'));
            try {
                \ARLM\Database::run(
                    'INSERT INTO ' . $t('licenses') . '
                     (key_hash, key_last4, product_id, customer_id, license_type, max_activations, current_activations, status, expires_at, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, 0, \'active\', ?, ?, ?)',
                    [
                        LicenseKey::hash($plain), LicenseKey::last4($plain), $productId, $customerId,
                        $type, $limit, $days !== null ? date('Y-m-d 23:59:59', strtotime('+' . $days . ' days')) : null,
                        $notes !== '' ? $notes : null, 'admin:' . ((string) (Auth::adminId() ?? 0)),
                    ]
                );
                break;
            } catch (\PDOException $e) {
                if (str_contains((string) $e->getMessage(), 'Duplicate') && $attempt < 4) {
                    continue;
                }
                throw $e;
            }
        }
        $licenseId = (int) \ARLM\Database::pdo()->lastInsertId();
        \ARLM\Database::commit();
    } catch (Throwable $e) {
        \ARLM\Database::rollBack();
        View::setFlash('error', 'Generation failed: ' . $e->getMessage());
        View::redirect('index.php?page=generate');
    }

    Logger::audit('admin', Auth::adminId(), 'license_generated', 'license', $licenseId, 'product=' . $product['product_uid'] . ' customer=' . $email);
    if (View::post('notify') === '1') {
        $to = View::post('notify_to') !== '' ? trim(View::post('notify_to')) : $email;
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            @mail(
                $to,
                'Your ' . $product['name'] . ' license key',
                "Thank you for your purchase.\n\nProduct: " . $product['name'] . "\nLicense type: " . $type .
                "\nActivation limit: " . $limit .
                "\nExpires: " . ($days !== null ? date('Y-m-d', strtotime('+' . $days . ' days')) : 'never') .
                "\nLicense key: " . $plain . "\n\nActivate at: " . Settings::get('server_url') . "\n\n" . Settings::get('brand', 'ARNIT CREATION'),
                'From: ' . (Settings::get('notify_from') !== '' ? Settings::get('notify_from') : 'no-reply@' . preg_replace('#^https?://(www\.)?#i', '', Settings::get('server_url')))
            );
        }
    }
    View::setFlash('ok', 'License generated.');
    $_SESSION['arlm_new_key'] = ['key' => $plain, 'product' => (string) $product['name'], 'expires' => $days !== null ? date('Y-m-d 23:59:59', strtotime('+' . $days . ' days')) : null, 'limit' => $limit];
    View::redirect('index.php?page=generate');
}

$products = \ARLM\Database::all("SELECT id, name, product_uid, default_license_type, default_activation_limit, default_duration_days FROM " . $t('products') . " WHERE status = 'active' ORDER BY name");
$newKey = $_SESSION['arlm_new_key'] ?? null;
unset($_SESSION['arlm_new_key']);
?>
<h1><?= View::h(View::t('generate')) ?></h1>

<?php if ($newKey !== null): ?>
<div class="card">
  <h2>License key</h2>
  <p class="alert warn"><?= View::h(View::t('secret_once')) ?></p>
  <p class="secret-box" id="lickey"><?= View::h($newKey['key']) ?></p>
  <p>
    <?= View::h(View::t('product')) ?>: <strong><?= View::h($newKey['product']) ?></strong> &middot;
    <?= View::h(View::t('activation_limit')) ?>: <strong><?= (int) $newKey['limit'] ?></strong> &middot;
    <?= View::h(View::t('expires_at')) ?>: <strong><?= View::h($newKey['expires'] ?? 'never') ?></strong>
  </p>
</div>
<?php endif; ?>

<form class="card" method="post">
  <?= Csrf::field() ?>
  <h2><?= View::h(View::t('create')) ?></h2>
  <div class="filters">
    <div style="flex:1;min-width:220px;">
      <label><?= View::h(View::t('product')) ?> *</label>
      <select name="product" required>
        <option value=""></option>
<?php foreach ($products as $pr): ?>
        <option value="<?= (int) $pr['id'] ?>" data-type="<?= View::h($pr['default_license_type']) ?>" data-limit="<?= (int) $pr['default_activation_limit'] ?>" data-days="<?= View::h((string) ($pr['default_duration_days'] ?? '')) ?>">
          <?= View::h($pr['name']) ?> (<?= View::h($pr['product_uid']) ?>)
        </option>
<?php endforeach; ?>
      </select>
    </div>
    <div><label><?= View::h(View::t('license_type')) ?></label>
      <select name="license_type" id="ltype">
<?php foreach ($licenseTypes as $lt): ?>
        <option value="<?= View::h($lt) ?>" <?= $lt === (string) Settings::get('default_license_type', 'annual') ? 'selected' : '' ?>><?= View::h(str_replace('_', ' ', $lt)) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div><label><?= View::h(View::t('activation_limit')) ?></label><input type="number" name="activation_limit" min="1" id="alimit" value="<?= (int) Settings::get('default_activation_limit', '1') ?>"></div>
    <div><label><?= View::h(View::t('duration_days')) ?></label><input type="number" name="duration_days" min="1" id="ddays" value="<?= View::h(Settings::get('default_duration_days', '365')) ?>"></div>
  </div>
  <div class="filters">
    <div><label><?= View::h(View::t('email')) ?> *</label><input type="email" name="email" value="<?= View::h(View::query('email')) ?>" required></div>
    <div><label><?= View::h(View::t('name')) ?></label><input type="text" name="name"></div>
    <div><label>Customer source</label>
      <select name="source">
        <option value="external">external</option>
        <option value="smf">SMF member</option>
      </select>
    </div>
    <div><label>SMF member ID (optional)</label><input type="number" name="smf_member_id" min="0"></div>
  </div>
  <label><?= View::h(View::t('notes')) ?></label>
  <textarea name="notes"></textarea>
  <label style="margin-top:14px;"><input type="checkbox" name="notify" value="1" style="width:auto;"> Email the key to the customer</label>
  <input type="text" name="notify_to" placeholder="override email (optional)">
  <button type="submit"><?= View::h(View::t('generate')) ?></button>
</form>
<script>
document.querySelector('select[name=product]')?.addEventListener('change', function () {
    var o = this.options[this.selectedIndex];
    if (!o || !o.value) return;
    document.getElementById('ltype').value = o.dataset.type;
    document.getElementById('alimit').value = o.dataset.limit;
    document.getElementById('ddays').value = o.dataset.days;
});
</script>
