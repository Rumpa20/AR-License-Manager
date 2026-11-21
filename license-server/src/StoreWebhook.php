<?php
/**
 * AR License Manager - Store webhook core (Cashfree store integration).
 *
 * Workflow enforced here:
 *  1. The store (PHP + MySQL e-commerce app) verifies the Cashfree payment
 *     SERVER-SIDE. Browser payment-success redirects are NEVER trusted by
 *     the store and never reach this endpoint.
 *  2. The store sends ONE signed purchase notification (HMAC-SHA256 over
 *     the raw body with the store_shared_secret from Settings).
 *  3. This handler validates signature + timestamp, then fulfills the
 *     order inside a single transaction: order upsert (idempotent by
 *     UNIQUE order_ref), license generation (hash-only storage), order
 *     marked fulfilled. Duplicate notifications return the original outcome
 *     and never create a second license.
 *
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */
declare(strict_types=1);

namespace ARLM;

final class StoreWebhook
{
    public const CLOCK_SKEW = 300;

    private const LICENSE_TYPES = [
        'single_domain', 'multi_domain', 'unlimited_domain',
        'monthly', 'annual', 'lifetime', 'trial', 'custom',
    ];

    /**
     * @param string $rawBody exact raw request body (signature target)
     * @param array<string, string> $headers normalized header => value (lowercase names)
     * @return array{status: int, response: array<string, mixed>}
     */
    public static function handle(string $rawBody, array $headers, string $ip): array
    {
        $secret = Settings::get('store_shared_secret');
        if ($secret === '') {
            throw new ApiException(503, 'STORE_NOT_CONFIGURED', 'Store integration is not configured.');
        }

        $timestamp = (int) ($headers['x-arlm-store-timestamp'] ?? 0);
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::CLOCK_SKEW) {
            Logger::security('store_bad_timestamp', 'warning', null, $ip);
            throw new ApiException(400, 'BAD_TIMESTAMP', 'Request timestamp is missing or outside the allowed clock skew.');
        }

        $signature = (string) ($headers['x-arlm-store-signature'] ?? '');
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if ($signature === '' || strlen($signature) > 256 || !hash_equals($expected, strtolower($signature))) {
            Logger::security('store_bad_signature', 'critical', null, $ip, substr((string) $headers['x-arlm-store-store'] ?? '', 0, 64));
            throw new ApiException(401, 'BAD_SIGNATURE', 'Webhook signature verification failed.');
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            throw new ApiException(400, 'BAD_REQUEST', 'Body must be a JSON object.');
        }

        $orderRef = substr(trim((string) ($body['order_ref'] ?? '')), 0, 64);
        $productUid = substr(trim((string) ($body['product_uid'] ?? '')), 0, 32);
        $customerInput = $body['customer'] ?? [];
        $email = filter_var(trim((string) ($customerInput['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
        if ($orderRef === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $orderRef) || $productUid === '' || $email === '') {
            throw new ApiException(400, 'BAD_REQUEST', 'order_ref, product_uid and customer.email are required.');
        }

        $licenseInput = $body['license'] ?? [];
        $licenseType = in_array((string) ($licenseInput['type'] ?? ''), self::LICENSE_TYPES, true)
            ? (string) $licenseInput['type']
            : Settings::get('default_license_type', 'annual');
        $activationLimit = (int) ($licenseInput['activation_limit'] ?? 0);
        if ($activationLimit < 0) {
            $activationLimit = 0;
        }
        $durationDays = ($licenseInput['duration_days'] ?? null) === null ? null : max(1, min(36500, (int) $licenseInput['duration_days']));
        $currency = strlen((string) ($body['currency'] ?? 'INR')) === 3 ? strtoupper(substr((string) ($body['currency'] ?? 'INR'), 0, 3)) : 'INR';
        $amount = max(0.0, (float) ($body['amount'] ?? 0.0));
        $txnId = substr(trim((string) ($body['txn_id'] ?? '')), 0, 128);
        $storeName = substr(trim((string) ($body['store_name'] ?? 'arnit_store')) ?: 'arnit_store', 0, 64);
        $customerName = mb_substr(trim((string) ($customerInput['name'] ?? '')), 0, 191);
        $smfMemberId = ($customerInput['smf_member_id'] ?? null) === null ? null : max(0, (int) $customerInput['smf_member_id']);

        Database::begin();
        try {
            $order = Database::one(
                'SELECT id, status, license_id FROM ' . Database::table('orders') . ' WHERE order_ref = ? FOR UPDATE',
                [$orderRef]
            );
            if ($order !== null && (int) $order['license_id'] > 0 && ($order['status'] === 'fulfilled' || $order['status'] === 'paid')) {
                Database::commit();
                Logger::audit('api', null, 'store_webhook_replay', 'order', (int) $order['id'], $orderRef);
                return [
                    'status'   => 200,
                    'response' => [
                        'success'           => true,
                        'message'           => 'Order already processed. No duplicate license was created.',
                        'order_ref'         => $orderRef,
                        'license_id'        => (int) $order['license_id'],
                        'already_fulfilled' => true,
                    ],
                ];
            }
            if ($order !== null && in_array($order['status'], ['failed', 'refunded', 'blocked'], true)) {
                Database::rollBack();
                throw new ApiException(409, 'ORDER_NOT_FULFILLABLE', 'This order is ' . $order['status'] . ' and cannot be fulfilled.');
            }

            $product = Database::one(
                "SELECT id, name FROM " . Database::table('products') . " WHERE product_uid = ? AND status = 'active'",
                [$productUid]
            );
            if ($product === null) {
                Database::rollBack();
                Logger::security('store_unknown_product', 'warning', null, $ip, $productUid . ' / ' . $orderRef);
                throw new ApiException(422, 'PRODUCT_NOT_FOUND', 'Unknown or inactive product_uid.');
            }

            $customer = Database::one('SELECT id, status FROM ' . Database::table('customers') . ' WHERE email = ?', [$email]);
            if ($customer === null) {
                Database::run(
                    'INSERT INTO ' . Database::table('customers') . ' (smf_member_id, email, name, source, status) VALUES (?, ?, ?, ?, ?)',
                    [$smfMemberId, $email, $customerName !== '' ? $customerName : null, 'external', 'active']
                );
                $customerId = (int) Database::pdo()->lastInsertId();
                Logger::audit('system', null, 'customer_created_from_order', 'customer', $customerId, $email);
            } else {
                $customerId = (int) $customer['id'];
                if ($customer['status'] === 'blocked') {
                    Database::rollBack();
                    Logger::security('store_blocked_customer', 'warning', null, $ip, $email . ' / ' . $orderRef);
                    throw new ApiException(403, 'CUSTOMER_BLOCKED', 'The customer account is blocked; order not fulfilled.');
                }
            }

            if ($order === null) {
                Database::run(
                    'INSERT INTO ' . Database::table('orders') . '
                     (order_ref, txn_id, store_name, product_id, customer_id, amount, currency, status, paid_at, payload)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                    [$orderRef, $txnId === '' ? null : $txnId, $storeName, (int) $product['id'], $customerId, $amount, $currency, 'paid', $rawBody]
                );
                $orderId = (int) Database::pdo()->lastInsertId();
            } else {
                $orderId = (int) $order['id'];
                Database::run(
                    'UPDATE ' . Database::table('orders') . " SET status = 'paid', paid_at = NOW(), payload = ?,
                     product_id = ?, customer_id = ? WHERE id = ?",
                    [$rawBody, (int) $product['id'], $customerId, $orderId]
                );
            }

            $licenseId = 0;
            $plainKey = '';
            $existingLicense = (int) Database::value(
                'SELECT license_id FROM ' . Database::table('orders') . ' WHERE id = ? AND license_id IS NOT NULL',
                [$orderId]
            );
            if ($existingLicense > 0) {
                $licenseId = $existingLicense;
            } else {
                [$licenseId, $plainKey] = self::createLicense(
                    (int) $product['id'],
                    $customerId,
                    $licenseType,
                    $activationLimit > 0 ? $activationLimit : 0,
                    $durationDays
                );
            }

            Database::run(
                "UPDATE " . Database::table('orders') . " SET status = 'fulfilled', fulfilled_at = NOW(), license_id = ? WHERE id = ?",
                [$licenseId, $orderId]
            );
            Database::commit();
        } catch (ApiException $e) {
            Database::rollBack();
            throw $e;
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::security('store_fulfillment_error', 'critical', null, $ip, $orderRef);
            throw new ApiException(500, 'FULFILLMENT_FAILED', 'Order fulfillment failed.');
        }

        $expires = Database::value('SELECT expires_at FROM ' . Database::table('licenses') . ' WHERE id = ?', [$licenseId]);
        Logger::audit('api', null, 'order_fulfilled', 'order', $orderId, $orderRef . ' license=' . $licenseId);

        if ($plainKey !== '') {
            $to = $email;
            if (filter_var(trim((string) ($licenseInput['notify_email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
                $to = (string) $licenseInput['notify_email'];
            }
            self::sendDeliveryMail($to, (string) $product['name'], $plainKey, $orderRef, $expires);
        }

        return [
            'status'   => 200,
            'response' => [
                'success'           => true,
                'message'           => 'Order fulfilled and license generated.',
                'order_ref'         => $orderRef,
                'order_id'          => $orderId,
                'license_id'        => $licenseId,
                'product_id'        => $productUid,
                'customer'          => $email,
                'expires_at'        => $expires,
                'already_fulfilled' => false,
                'license_key'       => $plainKey,
                'timestamp'         => time(),
            ],
        ];
    }

    /** Creates the license row inside the caller's transaction; returns [id, plaintext key]. */
    private static function createLicense(int $productId, int $customerId, string $type, int $limit, ?int $durationDays): array
    {
        if ($limit === 0) {
            $limit = max(1, (int) Settings::get('default_activation_limit', '1'));
        }
        if ($durationDays === null && $type !== 'lifetime') {
            $fromSettings = (int) Settings::get('default_duration_days', '365');
            $durationDays = $fromSettings > 0 ? $fromSettings : null;
        }
        $expires = $type === 'lifetime' || $durationDays === null
            ? null
            : date('Y-m-d 23:59:59', strtotime('+' . $durationDays . ' days'));
        $plain = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plain = LicenseKey::generate(Settings::get('key_prefix', 'ARLM'));
            try {
                Database::run(
                    'INSERT INTO ' . Database::table('licenses') . '
                     (key_hash, key_last4, product_id, customer_id, license_type, max_activations, current_activations, status, expires_at, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NULL, ?)',
                    [
                        LicenseKey::hash($plain), LicenseKey::last4($plain), $productId, $customerId,
                        $type, $limit, 'active', $expires, 'store-webhook',
                    ]
                );
                break;
            } catch (\PDOException $e) {
                if (str_contains((string) $e->getMessage(), 'Duplicate') && $attempt < 4) {
                    continue;
                }
                throw $e;
            }
        }
        $licenseId = (int) Database::pdo()->lastInsertId();
        return [$licenseId, $plain];
    }

    private static function sendDeliveryMail(string $to, string $productName, string $plainKey, string $orderRef, mixed $expires): void
    {
        $serverUrl = Settings::get('server_url');
        $from = Settings::get('notify_from') !== ''
            ? Settings::get('notify_from')
            : 'no-reply@' . preg_replace('#^https?://(www\.)?#i', '', $serverUrl);
        $body = "Thank you for your purchase.\n\n"
            . 'Order: ' . $orderRef . "\n"
            . 'Product: ' . $productName . "\n"
            . 'License key: ' . $plainKey . "\n"
            . 'Expires: ' . ($expires === null ? 'never' : (string) $expires) . "\n\n"
            . 'Keep this key safe; it can be re-issued only by ARNIT CREATION support.' . "\n\n"
            . Settings::get('brand', 'ARNIT CREATION');
        @mail($to, 'Your license for ' . $productName, $body, 'From: ' . $from);
    }
}
