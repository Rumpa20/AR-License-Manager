<?php
/**
 * AR License Manager - Store-side purchase notification adapter.
 *
 * Drop this single file into your existing PHP + MySQL e-commerce store
 * (Cashfree-based). After the store has verified the payment SERVER-SIDE
 * (never from a browser success redirect), call:
 *
 *   require_once __DIR__ . '/arlm-store-notify.php';
 *   $result = arlm_notify_license_server(array(
 *       'order_ref'   => $orderRef,            // your unique order reference
 *       'txn_id'      => $cashfreeTxnId,
 *       'product_uid' => 'PRODUCT-001',        // as configured in the license server
 *       'amount'      => '499.00',
 *       'currency'    => 'INR',
 *       'customer'    => array('email' => $email, 'name' => $name),
 *       'license'     => array(                // all keys optional
 *           'type'            => 'annual',   // single_domain, multi_domain, unlimited_domain,
 *                                            // monthly, annual, lifetime, trial, custom
 *           'activation_limit'=> 1,
 *           'duration_days'   => 365,
 *           'notify_email'    => $email,     // where the license email is sent
 *       ),
 *   ));
 *
 * The notification is signed server-to-server with HMAC-SHA256 using the
 * shared secret configured in the AR License Manager admin panel
 * (Settings > Store integration secret). Duplicate calls for the same
 * order_ref are safe: the license server answers with the original result
 * (already_fulfilled=true) and never issues a second license.
 *
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */
declare(strict_types=1);

if (!function_exists('arlm_notify_license_server')) {

    /**
     * Sends a signed purchase notification to the AR License Manager server.
     *
     * @return array{ok: bool, code: int, error: string|null, data: array|null}
     */
    function arlm_notify_license_server(array $order): array
    {
        $serverUrl = rtrim((string) (getenv('ARLM_SERVER_URL') ?: 'https://arnitcreation.online/arlm'), '/');
        $sharedSecret = (string) (getenv('ARLM_STORE_SHARED_SECRET') ?: '');

        if ($sharedSecret === '') {
            return array('ok' => false, 'code' => 0, 'error' => 'ARLM_STORE_SHARED_SECRET is not configured.', 'data' => null);
        }

        $payload = $order + array('timestamp' => time(), 'store_name' => 'arnit_store');
        $payload['timestamp'] = (int) $payload['timestamp'];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-ARLM-Store-Timestamp: ' . (int) $payload['timestamp'],
            'X-ARLM-Store-Signature: ' . hash_hmac('sha256', $raw, $sharedSecret),
        );

        $ch = curl_init($serverUrl . '/webhooks/store/');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $raw,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'ARLM-Store-Adapter/1.0',
        ));
        $rawResponse = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $rawResponse === false ? curl_error($ch) : null;
        curl_close($ch);

        if ($rawResponse === false) {
            return array('ok' => false, 'code' => $code, 'error' => $err, 'data' => null);
        }
        $data = json_decode((string) $rawResponse, true);
        if (!is_array($data)) {
            return array('ok' => false, 'code' => $code, 'error' => 'Non-JSON response', 'data' => null);
        }
        if ($code !== 200 || empty($data['success'])) {
            return array('ok' => false, 'code' => $code, 'error' => ($data['error_code'] ?? 'ERROR') . ': ' . ($data['message'] ?? ''), 'data' => $data);
        }
        return array('ok' => true, 'code' => $code, 'error' => null, 'data' => $data);
    }
}
