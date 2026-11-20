<?php
/**
 * AR License Manager - Admin authentication and session security.
 * Sessions use strict mode and regenerate IDs on privilege change;
 * login brute force is rate limited through security_logs.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_name('ARLMSESS');
        session_start();
    }

    public static function loggedIn(): bool
    {
        return !empty($_SESSION['arlm_admin_id']);
    }

    public static function adminId(): ?int
    {
        return self::loggedIn() ? (int) $_SESSION['arlm_admin_id'] : null;
    }

    public static function attempt(string $username, string $password): bool
    {
        $ip = Http::clientIp();
        $recent = (int) Database::value(
            'SELECT COUNT(*) FROM ' . Database::table('security_logs') . "
             WHERE event = 'admin_login_failed' AND ip = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)",
            [$ip]
        );
        if ($recent >= 5) {
            Logger::security('admin_login_blocked', 'warning', $username, $ip, 'Too many failed logins');
            throw new ApiException(429, 'TOO_MANY_ATTEMPTS', 'Too many failed attempts. Wait 10 minutes.');
        }

        $row = Database::one('SELECT id, password_hash FROM ' . Database::table('admins') . ' WHERE username = ? LIMIT 1', [$username]);
        $ok = $row !== null && password_verify($password, (string) $row['password_hash']);
        if (!$ok) {
            Logger::security('admin_login_failed', 'warning', $username, $ip);
            return false;
        }
        Logger::security('admin_login_ok', 'info', $username, $ip);
        session_regenerate_id(true);
        $_SESSION['arlm_admin_id'] = (int) $row['id'];
        $_SESSION['arlm_admin_name'] = $username;
        $_SESSION['arlm_csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public static function requireLogin(): void
    {
        if (!self::loggedIn()) {
            header('Location: index.php?action=login');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }
}
