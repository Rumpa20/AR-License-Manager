<?php
/**
 * Security Logs: read-only audit of failed logins, blocks, CSRF hits and
 * other security events. Severity filter, text search, CSV export.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);
$severities = ['info', 'warning', 'critical'];

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all('SELECT id, event, severity, actor, ip, details, created_at FROM ' . $t('security_logs') . ' ORDER BY id DESC');
    View::csv(['id', 'event', 'severity', 'actor', 'ip', 'details', 'created_at'], $rows, 'arlm-security-logs.csv');
}

[$sortCol, $sortDir] = View::sort(['id', 'created_at'], 'id');

$where = ['1=1'];
$args = [];
$q = View::query('q');
if ($q !== '') {
    $where[] = '(s.event LIKE ? OR s.actor LIKE ? OR s.ip LIKE ? OR s.details LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
$sevFilter = in_array(View::query('severity'), $severities, true) ? View::query('severity') : '';
if ($sevFilter !== '') {
    $where[] = 's.severity = ?';
    $args[] = $sevFilter;
}
$whereSql = implode(' AND ', $where);

$total = (int) \ARLM\Database::value('SELECT COUNT(*) FROM ' . $t('security_logs') . " s WHERE {$whereSql}", $args);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT s.id, s.event, s.severity, s.actor, s.ip, s.details, s.created_at
     FROM " . $t('security_logs') . " s
     WHERE {$whereSql} ORDER BY s.{$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ', 20',
    $args
);
$base = 'index.php?page=security_logs&severity=' . urlencode($sevFilter) . '&q=' . urlencode($q);
?>
<h1><?= View::h(View::t('security_logs')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="security_logs">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h($q) ?>" placeholder="event / actor / ip"></div>
  <div><label><?= View::h(View::t('severity')) ?></label>
    <select name="severity"><option value=""><?= View::h(View::t('all')) ?></option>
<?php foreach ($severities as $sev): ?>
      <option value="<?= View::h($sev) ?>" <?= $sevFilter === $sev ? 'selected' : '' ?>><?= View::h($sev) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <button type="submit"><?= View::h(View::t('filter')) ?></button>
  <a class="btn btn-sub" href="index.php?page=security_logs&export=csv"><?= View::h(View::t('export_csv')) ?></a>
</form>

<div class="card">
  <table>
    <tr>
      <th><a href="index.php?page=security_logs&sort=id&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>">ID</a></th>
      <th><?= View::h(View::t('event')) ?></th><th><?= View::h(View::t('severity')) ?></th><th>Actor</th>
      <th><?= View::h(View::t('ip')) ?></th><th><?= View::h(View::t('details')) ?></th>
      <th><a href="index.php?page=security_logs&sort=created_at&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('date')) ?></a></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td class="mono"><?= View::h($row['event']) ?></td>
      <td><?= View::statusBadge((string) $row['severity']) ?></td>
      <td><?= View::h($row['actor'] ?? '-') ?></td>
      <td class="mono"><?= View::h($row['ip'] ?? '-') ?></td>
      <td><?= View::h(mb_substr((string) ($row['details'] ?? ''), 0, 80)) ?></td>
      <td><?= View::h($row['created_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </table>
  <?= View::paginate($total, $pagen, 20, $base) ?>
</div>
