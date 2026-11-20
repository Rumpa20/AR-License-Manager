<?php
/**
 * AR License Manager - Admin shell layout: responsive nav, dark/light
 * theme variables, consistent cards/tables/badges. Mobile-first.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class AdminLayout
{
    public const MENU = [
        'dashboard'     => 'dashboard',
        'products'      => 'products',
        'licenses'      => 'all_licenses',
        'generate'      => 'generate',
        'customers'     => 'customers',
        'orders'        => 'orders',
        'activations'   => 'activations',
        'api_clients'   => 'api_clients',
        'api_logs'      => 'api_logs',
        'security_logs' => 'security_logs',
        'reports'       => 'reports',
        'settings'      => 'settings',
        'system'        => 'system',
    ];

    public static function render(string $page, string $content): void
    {
        $title = View::t(self::MENU[$page] ?? $page);
        $brand = View::t('brand');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::h($title) ?> - <?= View::h(View::t('app_name')) ?></title>
<style>
:root { --brand:#16324f; --accent:#2e86de; --bg:#f4f6f9; --card:#fff; --text:#1f2933; --line:#d9e1e8; --ok:#1e8449; --warn:#b9770e; --bad:#a93226; }
@media (prefers-color-scheme: dark) {
:root { --brand:#0d1b2a; --bg:#12181f; --card:#1b2430; --text:#e2e8f0; --line:#2c3a49; --accent:#4a9ede; }
}
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif; background:var(--bg); color:var(--text); }
a { color:var(--accent); text-decoration:none; }
header.top { background:var(--brand); color:#fff; padding:10px 16px; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
header.top .name { font-weight:700; }
nav { background:var(--card); border-bottom:1px solid var(--line); padding:6px 10px; display:flex; flex-wrap:wrap; gap:4px; }
nav a { padding:7px 11px; border-radius:6px; font-size:.88rem; color:var(--text); }
nav a.cur { background:var(--accent); color:#fff; }
.wrap { max-width:1100px; margin:18px auto; padding:0 14px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:14px; overflow-x:auto; }
h1 { font-size:1.25rem; color:var(--brand); margin:0 0 12px; }
h2 { font-size:1rem; color:var(--brand); }
table { border-collapse:collapse; width:100%; font-size:.9rem; }
th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); white-space:nowrap; }
th a { color:inherit; }
.badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.78rem; font-weight:600; }
.st-active, .st-paid, .st-fulfilled, .st-ok { background:#d7efe1; color:#1e5631; }
.st-expired, .st-warn { background:#fdf2dc; color:#8a5a06; }
.st-suspended, .st-pending { background:#fdeccd; color:#7d5308; }
.st-revoked, .st-failed, .st-critical, .st-blocked { background:#fdecea; color:#8c2f26; }
.st-inactive, .st-info, .st-deactivated { background:#e5eaf0; color:#41546b; }
.alert { padding:10px 12px; border-radius:6px; margin-bottom:10px; font-size:.9rem; }
.alert.error { background:#fdecea; color:#8c2f26; border:1px solid #f5c6c0; }
.alert.ok { background:#e6f6ec; color:#1e5631; border:1px solid #b7e2c6; }
.alert.warn { background:#fdf2dc; color:#7d5308; border:1px solid #f0d9a8; }
label { display:block; margin:10px 0 4px; font-weight:600; font-size:.88rem; }
input[type=text],input[type=password],input[type=number],input[type=email],input[type=url],input[type=date],select,textarea { width:100%; max-width:460px; padding:9px; border:1px solid var(--line); border-radius:6px; font-size:.95rem; background:var(--card); color:var(--text); }
textarea { min-height:80px; }
button,.btn { display:inline-block; margin-top:10px; margin-right:6px; padding:9px 18px; border:0; border-radius:6px; font-size:.92rem; cursor:pointer; background:var(--accent); color:#fff; }
.btn-sub { background:#5a6b7d; }
.btn-danger { background:var(--bad); }
.btn-sm { padding:4px 10px; font-size:.8rem; margin:0; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
.stat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; }
.stat h3 { margin:0 0 4px; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:#8096a8; }
.stat p { margin:0; font-size:1.45rem; font-weight:700; color:var(--brand); }
.pager a { display:inline-block; padding:5px 10px; border:1px solid var(--line); border-radius:6px; margin-right:4px; font-size:.85rem; }
.pager a.cur { background:var(--accent); color:#fff; border-color:var(--accent); }
.muted { color:#8096a8; font-size:.85rem; }
.inline-form { display:inline; }
.filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:12px; }
.filters label { margin:0 0 2px; }
.filters input, .filters select { max-width:220px; padding:7px; }
.secret-box { font-family:ui-monospace,monospace; background:#eef2f6; padding:10px; border-radius:6px; word-break:break-all; }
.row-actions { display:flex; gap:6px; flex-wrap:wrap; }
.mono { font-family:ui-monospace,monospace; font-size:.82rem; }
</style>
</head>
<body>
<header class="top">
  <span class="name"><?= View::h(View::t('app_name')) ?> &middot; <?= View::h($brand) ?></span>
  <span>
    <span class="muted"><?= View::h((string) ($_SESSION['arlm_admin_name'] ?? '')) ?></span>
    <a href="index.php?page=dashboard" style="color:#cfe3f7;"><?= View::h(View::t('dashboard')) ?></a> &middot;
    <a href="index.php?action=logout" style="color:#cfe3f7;"><?= View::h(View::t('logout')) ?></a>
  </span>
</header>
<nav>
<?php foreach (self::MENU as $p => $label): ?>
  <a href="index.php?page=<?= View::h($p) ?>" class="<?= View::h($p === $page ? 'cur' : '') ?>"><?= View::h(View::t($label)) ?></a>
<?php endforeach; ?>
</nav>
<div class="wrap">
<?= View::alert(View::takeFlashes()) ?>
<?= $content ?>
</div>
</body>
</html>
<?php
    }
}
