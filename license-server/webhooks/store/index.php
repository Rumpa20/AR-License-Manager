<?php
/**
 * AR License Manager - Store webhook entry point.
 *
 * URL: https://<host>/arlm/webhooks/store/
 * Auth: HMAC-SHA256 signature over the raw body (X-ARLM-Store-Signature,
 * hex, lowercase or uppercase) using the store_shared_secret from Settings,
 * plus X-ARLM-Store-Timestamp within +/- 300 s. Idempotent by order_ref.
 *
 * The store must verify the Cashfree payment server-side BEFORE sending
 * this notification. No browser redirect data is trusted anywhere in this
 * flow. Payload reference: docs/STORE-INTEGRATION.md.
 *
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use ARLM\ApiException;
use ARLM\Http;
use ARLM\Logger;
use ARLM\StoreWebhook;

header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

Http::requireHttps((bool) \ARLM\Config::get('allow_http_debug', false));
if (Http::method() !== 'POST') {
    Http::jsonOut(405, ['success' => false, 'error_code' => 'METHOD_NOT_ALLOWED', 'message' => 'Use POST for this webhook.']);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > Http::MAX_BODY_BYTES) {
    Http::jsonOut(400, ['success' => false, 'error_code' => 'BAD_REQUEST', 'message' => 'Missing or oversized request body.']);
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = (string) $value;
    }
}
$headers['x-arlm-store-timestamp'] = (string) ($_SERVER['HTTP_X_ARLM_STORE_TIMESTAMP'] ?? '');
$headers['x-arlm-store-signature'] = (string) ($_SERVER['HTTP_X_ARLM_STORE_SIGNATURE'] ?? '');

try {
    $result = StoreWebhook::handle((string) $raw, $headers, Http::clientIp());
    Http::jsonOut($result['status'], $result['response']);
} catch (ApiException $e) {
    Http::jsonOut($e->statusCode, ['success' => false, 'error_code' => $e->errorCode, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    Logger::security('store_webhook_crash', 'critical', null, Http::clientIp());
    Http::jsonOut(500, ['success' => false, 'error_code' => 'SERVER_ERROR', 'message' => 'Unexpected server error.']);
}
