<?php
/**
 * AR License Manager - Admin application entry point.
 * Stage 1 build: secure login, logout and live dashboard statistics.
 * Additional admin pages (products, licenses, customers, orders,
 * activations, API clients, logs, reports, settings) are added on top
 * of this controller in later stages.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use ARLM\ApiException;
use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Database;
use ARLM\Http;
use ARLM\Logger;

Auth::startSession();
$action = (string) ($_GET['action'] ?? 'dashboard');

if ($action === 'logout') {
    Auth::logout();
    header('Location: index.php?action=login');
    exit;
}

$loginError = null;
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            Csrf::validate($_POST['csrf_token'] ?? null);
            if (Auth::attempt(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
                header('Location: index.php');
                exit;
            }
            $loginError = 'Invalid username or password.';
        } catch (ApiException $e) {
            $loginError = $e->getMessage();
        }
    }
    if (Auth::loggedIn()) {
        header('Location: index.php');
        exit;
    }
}

if (!Auth::loggedIn()) {
    $action = 'login';
}

$stats = [];
if ($action === 'dashboard') {
    $t = static fn (string $n): string => Database::table($n);
    $stats = [
        'products'    => (int) Database::value("SELECT COUNT(*) FROM " . $t('products')),
        'licenses'    => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses')),
        'active'      => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'active'"),
        'expired'     => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'expired'"),
        'suspended'   => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'suspended'"),
        'revoked'     => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'revoked'"),
        'pending'     => (int) Database::value("SELECT COUNT(*) FROM " . $t('licenses') . " WHERE status = 'pending'"),
        'activations' => (int) Database::value("SELECT COUNT(*) FROM " . $t('activations') . " WHERE status = 'active'"),
        'customers'   => (int) Database::value("SELECT COUNT(*) FROM " . $t('customers')),
        'orders'      => (int) Database::value("SELECT COUNT(*) FROM " . $t('orders')),
        'api_calls'   => (int) Database::value("SELECT COUNT(*) FROM " . $t('api_logs')),
    ];
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AR License Manager - Admin</title>
<style>
:root { --brand:#1e3a5f; --accent:#2e86de; --bg:#f4f6f9; --card:#fff; --text:#1f2933; --line:#d9e1e8; --ok:#1e8449; --warn:#b9770e; --bad:#a93226; }
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif; background:var(--bg); color:var(--text); }
header { background:var(--brand); color:#fff; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
header a { color:#cfe3f7; text-decoration:none; }
.wrap { max-width:960px; margin:20px auto; padding:0 16px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; }
.card h3 { margin:0 0 4px; font-size:.8rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; }
.card p { margin:0; font-size:1.5rem; font-weight:700; color:var(--brand); }
label { display:block; margin:12px 0 4px; font-weight:600; font-size:.9rem; }
input[type=text],input[type=password] { width:100%; max-width:420px; padding:10px; border:1px solid var(--line); border-radius:6px; font-size:1rem; }
button { margin-top:14px; padding:10px 22px; background:var(--accent); border:0; color:#fff; font-size:1rem; border-radius:6px; }
.alert { padding:10px 12px; border-radius:6px; margin-bottom:10px; font-size:.9rem; background:#fdecea; color:#8c2f26; border:1px solid #f5c6c0; }
.muted { color:#64748b; font-size:.85rem; }
</style>
</head>
<body>
<?php if ($action === 'login'): ?>
<header><span><strong>AR License Manager</strong></span><span class="muted">v1.0 &middot; ARNIT CREATION</span></header>
<div class="wrap">
<?php if ($loginError !== null): ?><div class="alert"><?= h($loginError) ?></div><?php endif; ?>
  <form class="card" method="post">
    <h2>Admin login</h2>
    <?= Csrf::field() ?>
    <label for="username">Username</label><input id="username" type="text" name="username" required>
    <label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Log in</button>
  </form>
</div>
<?php else: ?>
<header>
  <span><strong>AR License Manager</strong> &middot; <?= h($_SESSION['arlm_admin_name'] ?? 'admin') ?></span>
  <a href="index.php?action=logout">Log out</a>
</header>
<div class="wrap">
  <div class="grid">
<?php foreach ($stats as $label => $value): ?>
    <div class="card"><h3><?= h(str_replace('_', ' ', (string) $label)) ?></h3><p><?= (int) $value ?></p></div>
<?php endforeach; ?>
  </div>
  <p class="muted">API base URL: <code><?= h(\ARLM\Settings::get('api_base_url', Http::baseUrl() . '/../api/v1/')) ?></code></p>
</div>
<?php endif; ?>
</body>
</html>
