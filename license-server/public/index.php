<?php
/**
 * AR License Manager - Admin application front controller.
 * Routes ?page={module} to public/pages/{module}.php after authentication.
 * Every POST handler inside modules validates CSRF before any state change.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use ARLM\AdminLayout;
use ARLM\ApiException;
use ARLM\Auth;
use ARLM\Csrf;
use ARLM\View;

View::bootLanguage();
Auth::startSession();
$action = (string) ($_GET['action'] ?? '');

if ($action === 'logout') {
    Auth::logout();
    View::redirect('index.php?page=login');
}

$loginError = null;
if (!Auth::loggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['do'] ?? '') === 'login') {
        try {
            Csrf::validate($_POST['csrf_token'] ?? null);
            if (Auth::attempt(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
                View::redirect('index.php?page=dashboard');
            }
            $loginError = 'Invalid username or password.';
        } catch (ApiException $e) {
            $loginError = $e->getMessage();
        }
    }

    if (!Auth::loggedIn()) {
        ob_start();
        ?>
        <form class="card" method="post" style="max-width:420px;">
          <h2><?= View::h(View::t('login')) ?></h2>
          <?php if ($loginError !== null): ?><div class="alert error"><?= View::h($loginError) ?></div><?php endif; ?>
          <?= Csrf::field() ?>
          <input type="hidden" name="do" value="login">
          <label for="username"><?= View::h(View::t('username')) ?></label>
          <input id="username" type="text" name="username" required>
          <label for="password"><?= View::h(View::t('password')) ?></label>
          <input id="password" type="password" name="password" autocomplete="current-password" required>
          <button type="submit"><?= View::h(View::t('login')) ?></button>
        </form>
        <?php
        AdminLayout::render('login', (string) ob_get_clean());
        exit;
    }
}

$page = preg_replace('/[^a-z_]/', '', (string) ($_GET['page'] ?? 'dashboard')) ?: 'dashboard';
$allowed = array_keys(AdminLayout::MENU);
if (!in_array($page, $allowed, true)) {
    http_response_code(404);
    $page = 'dashboard';
}

Auth::requireLogin();

if (isset($_GET['export']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

ob_start();
$module = __DIR__ . '/pages/' . $page . '.php';
if (!is_file($module)) {
    http_response_code(500);
    exit('Admin module missing: ' . htmlspecialchars($page, ENT_QUOTES));
}
require $module;
AdminLayout::render($page, (string) ob_get_clean());
