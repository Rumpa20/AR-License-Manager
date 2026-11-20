<?php
/**
 * AR License Manager - HTTP helpers: JSON I/O, HTTPS enforcement,
 * request size limits and safe client IP detection.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Http
{
    public const MAX_BODY_BYTES = 65536;

    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') === 'on' || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        return $proto === 'https';
    }

    public static function requireHttps(bool $allowHttp = false): void
    {
        if (!$allowHttp && !self::isHttps()) {
            self::jsonOut(400, ['success' => false, 'error_code' => 'HTTPS_REQUIRED', 'message' => 'HTTPS is required for all API requests.']);
        }
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        return trim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
    }

    public static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public static function jsonBody(): array
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY_BYTES) {
            return [];
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function jsonOut(int $status, array $payload, bool $exit = true): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($exit) {
            exit;
        }
    }

    public static function baseUrl(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return $scheme . '://' . $host . $dir;
    }
}
