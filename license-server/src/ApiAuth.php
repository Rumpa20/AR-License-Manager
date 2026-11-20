<?php
/**
 * AR License Manager - API client authentication.
 *
 * Clients authenticate with X-ARLM-Client-Id / X-ARLM-Secret headers.
 * The secret is stored only as a SHA-256 hash, compared in constant time.
 * POST endpoints additionally require:
 *   timestamp  - unix seconds within CLOCK_SKEW of server time
 *   request_id - unique string per logical request; a duplicate
 *                (client, request_id) pair returns the STORED original
 *                response, which gives true idempotency and replay guard.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class ApiAuth
{
    public const CLOCK_SKEW = 300;

    public static function authenticate(): array
    {
        $clientId = trim((string) ($_SERVER['HTTP_X_ARLM_CLIENT_ID'] ?? ''));
        $secret = (string) ($_SERVER['HTTP_X_ARLM_SECRET'] ?? '');
        if ($clientId === '' || $secret === '') {
            Logger::security('api_auth_missing', 'warning', null, Http::clientIp());
            throw new ApiException(401, 'AUTH_REQUIRED', 'API credentials are required.');
        }
        $row = Database::one(
            'SELECT id, name, client_id, secret_hash, permissions, rate_limit_m, status
             FROM ' . Database::table('api_clients') . ' WHERE client_id = ? LIMIT 1',
            [substr($clientId, 0, 32)]
        );
        if ($row === null || !hash_equals((string) $row['secret_hash'], hash('sha256', $secret))) {
            Logger::security('api_auth_failed', 'warning', substr($clientId, 0, 32), Http::clientIp());
            throw new ApiException(401, 'AUTH_INVALID', 'Invalid API credentials.');
        }
        if ($row['status'] !== 'active') {
            Logger::security('api_auth_disabled', 'warning', substr($clientId, 0, 32), Http::clientIp());
            throw new ApiException(403, 'CLIENT_DISABLED', 'This API client is disabled or revoked.');
        }
        Database::run('UPDATE ' . Database::table('api_clients') . ' SET last_used_at = NOW() WHERE id = ?', [(int) $row['id']]);
        return $row;
    }

    /** @return array{client: array, request_id: string, timestamp: int} */
    public static function authenticatePost(array $body): array
    {
        $client = self::authenticate();
        RateLimiter::check((int) $client['id'], (int) $client['rate_limit_m']);

        $requestId = (string) ($body['request_id'] ?? '');
        $timestamp = (int) ($body['timestamp'] ?? 0);
        if ($requestId === '' || strlen($requestId) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $requestId)) {
            throw new ApiException(400, 'REQUEST_ID_REQUIRED', 'A unique request_id of up to 64 alphanumeric characters is required.');
        }
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::CLOCK_SKEW) {
            throw new ApiException(400, 'BAD_TIMESTAMP', 'Request timestamp is missing or outside the allowed clock skew.');
        }
        return ['client' => $client, 'request_id' => $requestId, 'timestamp' => $timestamp];
    }

    public static function replayedResponse(int $clientId, string $requestId): ?array
    {
        $row = Database::one(
            'SELECT response_json FROM ' . Database::table('api_logs') . '
             WHERE api_client_id = ? AND request_id = ? AND status_code = 200 LIMIT 1',
            [$clientId, $requestId]
        );
        if ($row === null || $row['response_json'] === null || $row['response_json'] === '') {
            return null;
        }
        $decoded = json_decode((string) $row['response_json'], true);
        return is_array($decoded) ? $decoded : null;
    }
}
