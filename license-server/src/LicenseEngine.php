<?php
/**
 * AR License Manager - Core license engine.
 *
 * Endpoints (all signed with Ed25519):
 *  - activate:   consume one activation slot for a domain (idempotent)
 *  - validate:   full license state check without consuming anything
 *  - deactivate: release an activation slot
 *  - check:      lightweight status/expiry probe
 *  - products:   list active products
 *
 * Error codes: PRODUCT_NOT_FOUND, PRODUCT_INACTIVE, LICENSE_NOT_FOUND,
 * LICENSE_PENDING, LICENSE_SUSPENDED, LICENSE_REVOKED, LICENSE_EXPIRED,
 * DOMAIN_NOT_ACTIVATED, DOMAIN_INVALID, DOMAIN_NOT_FOUND, LIMIT_REACHED,
 * CUSTOMER_SUSPENDED, PRODUCT_MISMATCH, PERMISSION_DENIED.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class LicenseEngine
{
    private static ?string $lastKeyRef = null;

    public static function hasPermission(array $client, string $endpoint): bool
    {
        $perms = array_map('trim', explode(',', (string) $client['permissions']));
        return in_array($endpoint, $perms, true);
    }

    public static function licenseRef(): ?string
    {
        return self::$lastKeyRef;
    }

    public static function activate(array $body, array $ctx): array
    {
        $license = self::loadLicenseAndProduct($body);
        $domain = self::resolveDomain($body);

        Database::begin();
        try {
            $existing = Database::one(
                'SELECT id, status FROM ' . Database::table('activations') . '
                 WHERE license_id = ? AND domain_hash = ? LIMIT 1',
                [(int) $license['id'], Domains::hash($domain)]
            );

            if ($existing !== null && $existing['status'] === 'active') {
                Database::run(
                    'UPDATE ' . Database::table('activations') . ' SET last_seen_at = NOW(), ip = ?, user_agent = ? WHERE id = ?',
                    [Http::clientIp(), self::userAgent(), (int) $existing['id']]
                );
                Database::commit();
                Logger::audit('api', (int) $ctx['client']['id'], 'activation_repeat', 'license', (int) $license['id'], $domain);
                return self::licenseResponse($license, $domain, 'License already activated on this domain.', $ctx);
            }

            $effective = self::effectiveState($license);
            if ($effective !== 'active') {
                Database::rollBack();
                throw self::stateException($effective);
            }

            if ($existing !== null) {
                Database::run(
                    "UPDATE " . Database::table('activations') . "
                     SET status = 'active', ip = ?, user_agent = ?, activated_at = NOW(), last_seen_at = NOW(), deactivated_at = NULL
                     WHERE id = ?",
                    [Http::clientIp(), self::userAgent(), (int) $existing['id']]
                );
                $message = 'License re-activated on this domain.';
            } else {
                if ((int) $license['current_activations'] >= (int) $license['max_activations']) {
                    Database::rollBack();
                    Logger::security('activation_limit_hit', 'info', null, Http::clientIp(), 'license_id=' . $license['id']);
                    throw new ApiException(409, 'LIMIT_REACHED', 'Activation limit reached for this license.');
                }
                Database::run(
                    "INSERT INTO " . Database::table('activations') . "
                     (license_id, domain, domain_hash, status, ip, user_agent, activated_at, last_seen_at)
                     VALUES (?, ?, ?, 'active', ?, ?, NOW(), NOW())",
                    [(int) $license['id'], $domain, Domains::hash($domain), Http::clientIp(), self::userAgent()]
                );
                Database::run(
                    'UPDATE ' . Database::table('licenses') . ' SET current_activations = current_activations + 1 WHERE id = ?',
                    [(int) $license['id']]
                );
                $message = 'License activated successfully.';
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Logger::audit('api', (int) $ctx['client']['id'], 'domain_activated', 'license', (int) $license['id'], $domain);
        return self::licenseResponse($license, $domain, $message, $ctx);
    }

    public static function validate(array $body, array $ctx): array
    {
        $license = self::loadLicenseAndProduct($body);
        $domain = self::resolveDomain($body);

        $activation = Database::one(
            'SELECT id, status FROM ' . Database::table('activations') . '
             WHERE license_id = ? AND domain_hash = ? LIMIT 1',
            [(int) $license['id'], Domains::hash($domain)]
        );
        if ($activation === null || $activation['status'] !== 'active') {
            throw new ApiException(404, 'DOMAIN_NOT_ACTIVATED', 'This domain is not activated for this license.');
        }
        Database::run(
            'UPDATE ' . Database::table('activations') . ' SET last_seen_at = NOW(), ip = ? WHERE id = ?',
            [Http::clientIp(), (int) $activation['id']]
        );
        return self::licenseResponse($license, $domain, 'License validated successfully.', $ctx);
    }

    public static function deactivate(array $body, array $ctx): array
    {
        $license = self::loadLicenseAndProduct($body);
        $domain = self::resolveDomain($body);

        $activation = Database::one(
            'SELECT id, status FROM ' . Database::table('activations') . '
             WHERE license_id = ? AND domain_hash = ? LIMIT 1',
            [(int) $license['id'], Domains::hash($domain)]
        );
        if ($activation === null || $activation['status'] !== 'active') {
            return self::licenseResponse($license, $domain, 'This domain was not active.', $ctx);
        }

        Database::begin();
        try {
            Database::run(
                "UPDATE " . Database::table('activations') . " SET status = 'deactivated', deactivated_at = NOW() WHERE id = ?",
                [(int) $activation['id']]
            );
            Database::run(
                'UPDATE ' . Database::table('licenses') . ' SET current_activations = GREATEST(current_activations - 1, 0) WHERE id = ?',
                [(int) $license['id']]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Logger::audit('api', (int) $ctx['client']['id'], 'domain_deactivated', 'license', (int) $license['id'], $domain);
        return self::licenseResponse($license, $domain, 'Domain deactivated successfully.', $ctx);
    }

    public static function check(array $body, array $ctx): array
    {
        $license = self::loadLicenseAndProduct($body);
        return self::licenseResponse($license, null, 'License status retrieved.', $ctx);
    }

    public static function products(): array
    {
        $rows = Database::all(
            "SELECT product_uid, name, version, category, default_license_type, default_activation_limit
             FROM " . Database::table('products') . " WHERE status = 'active' ORDER BY name ASC"
        );
        return [
            'success'  => true,
            'products' => $rows,
            'count'    => count($rows),
        ];
    }

    /** @return array<string, mixed> license row joined with product + customer */
    private static function loadLicenseAndProduct(array $body): array
    {
        $plainKey = trim((string) ($body['license_key'] ?? ''));
        $productUid = trim((string) ($body['product_id'] ?? ''));
        if ($plainKey === '' || $productUid === '') {
            throw new ApiException(400, 'BAD_REQUEST', 'license_key and product_id are required.');
        }
        if (!LicenseKey::isValidFormat($plainKey)) {
            throw new ApiException(400, 'BAD_REQUEST', 'License key format is invalid.');
        }

        $license = Database::one(
            'SELECT l.*, p.product_uid, p.name AS product_name, p.status AS product_status
             FROM ' . Database::table('licenses') . ' l
             INNER JOIN ' . Database::table('products') . ' p ON p.id = l.product_id
             WHERE l.key_hash = ? LIMIT 1',
            [LicenseKey::hash($plainKey)]
        );
        self::$lastKeyRef = LicenseKey::last4($plainKey);
        if ($license === null) {
            throw new ApiException(404, 'LICENSE_NOT_FOUND', 'License not found.');
        }
        if ($productUid !== (string) $license['product_uid']) {
            Logger::security('license_product_mismatch', 'warning', null, Http::clientIp(), 'license_id=' . $license['id']);
            throw new ApiException(403, 'PRODUCT_MISMATCH', 'License does not belong to this product.');
        }
        if ($license['product_status'] !== 'active') {
            throw new ApiException(403, 'PRODUCT_INACTIVE', 'This product is currently inactive.');
        }

        $customer = Database::one(
            'SELECT status FROM ' . Database::table('customers') . ' WHERE id = ? LIMIT 1',
            [(int) $license['customer_id']]
        );
        if ($customer !== null && $customer['status'] !== 'active') {
            throw new ApiException(403, 'CUSTOMER_SUSPENDED', 'The customer account for this license is suspended.');
        }
        return $license;
    }

    private static function resolveDomain(array $body): string
    {
        $domain = Domains::normalize((string) ($body['domain'] ?? ''));
        if ($domain === null || !Domains::isAllowedFormat($domain, Settings::get('allow_local_domains', '0') === '1')) {
            throw new ApiException(400, 'DOMAIN_INVALID', 'The supplied domain is invalid or not allowed.');
        }
        return $domain;
    }

    /** Applies lazy expiration and returns the effective license status. */
    private static function effectiveState(array $license): string
    {
        $status = (string) $license['status'];
        if ($status === 'active' && $license['expires_at'] !== null) {
            $expires = strtotime((string) $license['expires_at']);
            if ($expires !== false && $expires < time()) {
                Database::run(
                    "UPDATE " . Database::table('licenses') . " SET status = 'expired' WHERE id = ? AND status = 'active'",
                    [(int) $license['id']]
                );
                $status = 'expired';
            }
        }
        return $status;
    }

    private static function stateException(string $status): ApiException
    {
        return match ($status) {
            'expired'   => new ApiException(403, 'LICENSE_EXPIRED', 'License has expired.'),
            'suspended' => new ApiException(403, 'LICENSE_SUSPENDED', 'License is suspended.'),
            'revoked'   => new ApiException(403, 'LICENSE_REVOKED', 'License has been revoked.'),
            'pending'   => new ApiException(403, 'LICENSE_PENDING', 'License is awaiting approval or payment.'),
            default     => new ApiException(403, 'LICENSE_INACTIVE', 'License is not active.'),
        };
    }

    /** Builds, signs and returns the standard license response. */
    private static function licenseResponse(array $license, ?string $domain, string $message, array $ctx): array
    {
        $current = (int) Database::value(
            'SELECT current_activations FROM ' . Database::table('licenses') . ' WHERE id = ?',
            [(int) $license['id']]
        );
        $effective = self::effectiveState($license);
        return self::buildSignedResponse([
            'product_id'          => (string) $license['product_uid'],
            'domain'              => $domain ?? '',
            'status'              => $effective,
            'expires_at'          => $license['expires_at'],
            'activation_limit'    => (int) $license['max_activations'],
            'current_activations' => $current,
            'timestamp'           => (int) ($ctx['timestamp'] ?? time()),
            'request_id'          => (string) ($ctx['request_id'] ?? ''),
        ], $license, $domain, $message);
    }

    private static function buildSignedResponse(array $payload, array $license, ?string $domain, string $message): array
    {
        return [
            'success'             => $payload['status'] === 'active',
            'status'              => $payload['status'],
            'message'             => $message,
            'product_id'          => $payload['product_id'],
            'product_name'        => (string) ($license['product_name'] ?? ''),
            'license_type'        => (string) $license['license_type'],
            'domain'              => $payload['domain'] !== '' ? $payload['domain'] : null,
            'expires_at'          => $payload['expires_at'],
            'activation_limit'    => $payload['activation_limit'],
            'current_activations' => $payload['current_activations'],
            'timestamp'           => $payload['timestamp'],
            'request_id'          => $payload['request_id'],
            'signature'           => Signature::sign($payload),
        ];
    }

    private static function userAgent(): ?string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }
}
