<?php
/**
 * AR License Manager - API, security and audit logging.
 * Raw license keys are never logged; only the last 4 characters.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class Logger
{
    public static function api(int|null $clientId, string $endpoint, string $method, array $fields): int|null
    {
        try {
            Database::run(
                'INSERT INTO ' . Database::table('api_logs') . '
                 (api_client_id, endpoint, method, request_id, license_ref, ip, status_code, error_code, duration_ms, response_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $clientId,
                    substr($endpoint, 0, 64),
                    substr($method, 0, 8),
                    $fields['request_id'] ?? null,
                    $fields['license_ref'] ?? null,
                    $fields['ip'] ?? null,
                    $fields['status_code'] ?? 200,
                    $fields['error_code'] ?? null,
                    (int) ($fields['duration_ms'] ?? 0),
                    $fields['response_json'] ?? null,
                ]
            );
            return (int) Database::pdo()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('ARLM api log failed');
            return null;
        }
    }

    public static function security(string $event, string $severity, ?string $actor, ?string $ip, string $details = ''): void
    {
        try {
            Database::run(
                'INSERT INTO ' . Database::table('security_logs') . ' (event, severity, actor, ip, details) VALUES (?, ?, ?, ?, ?)',
                [substr($event, 0, 64), $severity, $actor === null ? null : substr($actor, 0, 64), $ip, substr($details, 0, 2000)]
            );
        } catch (\Throwable $e) {
            error_log('ARLM security log failed');
        }
    }

    public static function audit(string $actorType, ?int $actorId, string $action, ?string $objectType = null, ?int $objectId = null, string $details = ''): void
    {
        try {
            Database::run(
                'INSERT INTO ' . Database::table('audit_logs') . ' (actor_type, actor_id, action, object_type, object_id, details) VALUES (?, ?, ?, ?, ?, ?)',
                [$actorType, $actorId, substr($action, 0, 64), $objectType, $objectId, substr($details, 0, 2000)]
            );
        } catch (\Throwable $e) {
            error_log('ARLM audit log failed');
        }
    }
}
