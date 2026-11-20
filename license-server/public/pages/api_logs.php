<?php
/**
 * API Logs: read-only request ledger (endpoint, method, request id, license
 * reference, IP, status, error code, duration). Raw license keys and signed
 * secrets are never stored - only a last-4 license reference.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all('SELECT id, api_client_id, endpoint, method, request_id, license_ref, ip, status_code, error_code, duration_ms, created_at FROM ' . $t('api_logs') . ' ORDER BY id DESC');
    View::csv(['id', 'client_id_ref', 'endpoint', 'method', 'request_id', 'license_ref', 'ip', 'status_code', 'error_code', 'duration_ms', 'created_at'], $rows, 'arlm-api-logs.csv');
}

[$sortCol, $sortDir] = View::sort(['id', 'status_code', 'created_at', 'duration_ms'], 'id');

$where = ['1=1'];
$args = [];
$clientFilter = (int) View::query('client');
if ($clientFilter > 0) {
    $where[] = 'g.api_client_id = ?';
    $args[] = $clientFilter;
}
$endpointFilter = preg_replace('/[^a-z_]/', '', View::query('endpoint')) ?? '';
if ($endpointFilter !== '') {
    $where[] = 'g.endpoint = ?';
    $args[] = $endpointFilter;
}
$onlyErrors = View::query('errors') === '1';
if ($onlyErrors) {
    $where[] = 'g.status_code <> 200';
}
$q = View::query('q');
if ($q !== '') {
    $where[] = '(g.request_id LIKE ? OR g.ip LIKE ? OR g.error_code LIKE ? OR g.license_ref = ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '', -4));
}
$whereSql = implode(' AND ', $where);

$total = (int) \ARLM\Database::value('SELECT COUNT(*) FROM ' . $t('api_logs') . " g WHERE {$whereSql}", $args);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT g.id, g.api_client_id, g.endpoint, g.method, g.request_id, g.license_ref, g.ip, g.status_code, g.error_code, g.duration_ms, g.created_at,
            c.name AS client_name
     FROM " . $t('api_logs') . " g
     LEFT JOIN " . $t('api_clients') . " c ON c.id = g.api_client_id
     WHERE {$whereSql} ORDER BY g.{$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ', 20',
    $args
);
$clients = \ARLM\Database::all('SELECT id, name FROM ' . $t('api_clients') . ' ORDER BY name');
$base = 'index.php?page=api_logs&client=' . $clientFilter . '&endpoint=' . urlencode($endpointFilter) . '&errors=' . ($onlyErrors ? '1' : '') . '&q=' . urlencode($q);
?>
<h1><?= View::h(View::t('api_logs')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="api_logs">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h($q) ?>" placeholder="request id / ip / error"></div>
  <div><label>Client</label>
    <select name="client"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($clients as $c): ?>
      <option value="<?= (int) $c['id'] ?>" <?= $clientFilter === (int) $c['id'] ? 'selected' : '' ?>><?= View::h($c['name']) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div><label><?= View::h(View::t('endpoint')) ?></label>
    <input type="text" name="endpoint" value="<?= View::h($endpointFilter) ?>" placeholder="activate">
  </div>
  <div><label>Errors only</label><input type="checkbox" name="errors" value="1" <?= $onlyErrors ? 'checked' : '' ?> style="width:auto;"></div>
  <button type="submit"><?= View::h(View::t('filter')) ?></button>
  <a class="btn btn-sub" href="index.php?page=api_logs&export=csv"><?= View::h(View::t('export_csv')) ?></a>
</form>

<div class="card">
  <table>
    <tr>
      <th><a href="index.php?page=api_logs&sort=id&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">ID</a></th>
      <th><?= View::h(View::t('client_id')) ?></th><th><?= View::h(View::t('endpoint')) ?></th><th>Method</th><th>Request ID</th>
      <th>License</th><th><?= View::h(View::t('ip')) ?></th>
      <th><a href="index.php?page=api_logs&sort=status_code&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">HTTP</a></th>
      <th><?= View::h(View::t('error_code')) ?></th><th>ms</th>
      <th><a href="index.php?page=api_logs&sort=created_at&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('date')) ?></a></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td><?= View::h($row['client_name'] ?? (string) ($row['api_client_id'] ?? '?')) ?></td>
      <td class="mono"><?= View::h($row['endpoint']) ?></td>
      <td><?= View::h($row['method']) ?></td>
      <td class="mono"><?= View::h(substr((string) ($row['request_id'] ?? ''), 0, 16)) ?></td>
      <td class="mono"><?= View::h($row['license_ref'] ?? '-') ?></td>
      <td class="mono"><?= View::h($row['ip'] ?? '-') ?></td>
      <td><?= (int) $row['status_code'] ?></td>
      <td><?= View::h($row['error_code'] ?? '-') ?></td>
      <td><?= (int) $row['duration_ms'] ?></td>
      <td><?= View::h($row['created_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </table>
  <?= View::paginate($total, $pagen, 20, $base) ?>
</div>
