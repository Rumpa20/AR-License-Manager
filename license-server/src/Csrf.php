<?php
/**
 * AR License Manager - CSRF token helper for the admin application.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['arlm_csrf'])) {
            $_SESSION['arlm_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['arlm_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): void
    {
        if (!is_string($token) || empty($_SESSION['arlm_csrf']) || !hash_equals((string) $_SESSION['arlm_csrf'], $token)) {
            http_response_code(403);
            exit('Invalid security token. Go back, reload the page and try again.');
        }
    }
}
