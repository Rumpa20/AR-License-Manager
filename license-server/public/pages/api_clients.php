<?php
/**
 * API Clients: register clients, one-time credential display, disable /
 * revoke, per-client rate limit, usage counters. Secrets are stored only
 * as SHA-256 hashes; the plaintext secret is shown exactly once.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Logger;
use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$endpoints = ['activate', 'validate', 'deactivate', 'check', 'products'];
$states = ['active', 'disabled', 'revoked'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) View::post('id');
    $client = $id > 0 ? \ARLM\Database::one('SELECT id, name, status FROM ' . $t('api_clients') . ' WHERE id = ?', [$id]) : null;

    if ($do === 'create') {
        $name = mb_substr(trim(View::post('name')), 0, 191);
        $perms = array_values(array_intersect($endpoints, array_map('trim', explode(',', View::post('permissions')))));
        $rate = max(1, min(600, (int) View::post('rate_limit_m', '60')));
        if ($name === '') {
            View::setFlash('error', 'Client name is required.');
        } elseif ($perms === []) {
            View::setFlash('error', 'Select at least one permission.');
        } else {
            $clientId = bin2hex(random_bytes(16));
            $plainSecret = bin2hex(random_bytes(32));
            \ARLM\Database::run(
                'INSERT INTO ' . $t('api_clients') . ' (name, client_id, secret_hash, permissions, rate_limit_m, status) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $clientId, hash('sha256', $plainSecret), implode(',', $perms), $rate, 'active']
            );
            $newId = (int) \ARLM\Database::pdo()->lastInsertId();
            Logger::audit('admin', Auth::adminId(), 'api_client_created', 'api_client', $newId, $name);
            View::setFlash('ok', 'API client created.');
            $_SESSION['arlm_new_client'] = ['client_id' => $clientId, 'secret' => $plainSecret, 'name' => $name];
        }
        View::redirect('index.php?page=api_clients');
    }

    if ($client === null || !in_array($do, ['set_status', 'set_rate'], true)) {
        View::setFlash('error', 'Unknown API client or action.');
    } elseif ($do === 'set_status') {
        $to = (string) ($_POST['to'] ?? '');
        if (!in_array($to, $states, true)) {
            View::setFlash('error', 'Unknown status.');
        } else {
            \ARLM\Database::run('UPDATE ' . $t('api_clients') . ' SET status = ? WHERE id = ?', [$to, $id]);
            Logger::audit('admin', Auth::adminId(), 'api_client_set_' . $to, 'api_client', $id, (string) $client['name']);
            View::setFlash('ok', 'Client ' . $id . ' set to ' . $to . '.');
        }
    } else {
        $rate = max(1, min(600, (int) View::post('rate_limit_m', '60')));
        \ARLM\Database::run('UPDATE ' . $t('api_clients') . ' SET rate_limit_m = ? WHERE id = ?', [$rate, $id]);
        Logger::audit('admin', Auth::adminId(), 'api_client_rate_changed', 'api_client', $id, (string) $rate);
        View::setFlash('ok', 'Rate limit updated for client ' . $client['name'] . '.');
    }
    View::redirect('index.php?page=api_clients');
}

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all('SELECT id, name, client_id, permissions, rate_limit_m, status, last_used_at, created_at FROM ' . $t('api_clients') . ' ORDER BY id DESC');
    View::csv(['id', 'name', 'client_id', 'permissions', 'rate_limit_m', 'status', 'last_used_at', 'created_at'], $rows, 'arlm-api-clients.csv');
}

$credentials = $_SESSION['arlm_new_client'] ?? null;
unset($_SESSION['arlm_new_client']);
$rows = \ARLM\Database::all(
    'SELECT c.id, c.name, c.client_id, c.permissions, c.rate_limit_m, c.status, c.last_used_at, c.created_at,
            (SELECT COUNT(*) FROM ' . $t('api_logs') . ' g WHERE g.api_client_id = c.id) AS request_count,
            (SELECT COUNT(*) FROM ' . $t('api_logs') . ' g WHERE g.api_client_id = c.id AND g.status_code <> 200) AS error_count
     FROM ' . $t('api_clients') . ' c ORDER BY c.id DESC'
);
?>
<h1><?= View::h(View::t('api_clients')) ?></h1>

<?php if ($credentials !== null): ?>
<div class="card">
  <h2>New credentials: <?= View::h($credentials['name']) ?></h2>
  <p class="alert warn"><?= View::h(View::t('secret_once')) ?></p>
  <p><label>Client ID</label><span class="secret-box" id="cid"><?= View::h($credentials['client_id']) ?></span></p>
  <p><label>Client Secret</label><span class="secret-box" id="csecret"><?= View::h($credentials['secret']) ?></span></p>
</div>
<?php endif; ?>

<form class="card" method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="create">
  <h2><?= View::h(View::t('create')) ?></h2>
  <div class="filters">
    <div><label><?= View::h(View::t('name')) ?> *</label><input type="text" name="name" required></div>
    <div><label><?= View::h(View::t('permissions')) ?></label>
      <input type="text" name="permissions" value="activate,validate,deactivate,check,products">
      <p class="muted">allowed: <?= View::h(implode(', ', $endpoints)) ?></p>
    </div>
    <div><label><?= View::h(View::t('rate_limit')) ?></label><input type="number" name="rate_limit_m" min="1" max="600" value="60"></div>
  </div>
  <button type="submit"><?= View::h(View::t('create')) ?></button>
</form>

<div class="card">
  <table>
    <tr>
      <th>ID</th><th><?= View::h(View::t('name')) ?></th><th><?= View::h(View::t('client_id')) ?></th>
      <th><?= View::h(View::t('permissions')) ?></th><th><?= View::h(View::t('rate_limit')) ?></th>
      <th>Requests</th><th>Errors</th><th>Last used</th><th><?= View::h(View::t('status')) ?></th><th><?= View::h(View::t('actions')) ?></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td><?= View::h($row['name']) ?></td>
      <td class="mono"><?= View::h($row['client_id']) ?></td>
      <td class="mono"><?= View::h($row['permissions']) ?></td>
      <td>
        <form class="inline-form" method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="set_rate">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="number" name="rate_limit_m" min="1" max="600" value="<?= (int) $row['rate_limit_m'] ?>" style="width:80px;max-width:110px;padding:5px;">
          <button type="submit" class="btn btn-sub btn-sm"><?= View::h(View::t('save')) ?></button>
        </form>
      </td>
      <td><?= (int) $row['request_count'] ?></td>
      <td><?= (int) $row['error_count'] ?></td>
      <td><?= View::h($row['last_used_at'] ?? '-') ?></td>
      <td><?= View::statusBadge((string) $row['status']) ?></td>
      <td class="row-actions">
<?php if ($row['status'] === 'active'): ?>
        <form class="inline-form" method="post" onsubmit="return confirm('Revoke this client? All requests will be rejected immediately.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="set_status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="to" value="revoked">
          <button type="submit" class="btn btn-danger btn-sm"><?= View::h(View::t('revoked')) ?></button>
        </form>
        <form class="inline-form" method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="set_status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="to" value="disabled">
          <button type="submit" class="btn btn-sub btn-sm"><?= View::h(View::t('inactive')) ?></button>
        </form>
<?php else: ?>
        <form class="inline-form" method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="set_status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="to" value="active">
          <button type="submit" class="btn btn-sm"><?= View::h(View::t('active')) ?></button>
        </form>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </table>
</div>
