<?php
/**
 * AR License Manager - REST API v1 entry point.
 *
 * Routes: POST /activate  POST /validate  POST /deactivate  POST /check
 *         GET  /products
 *
 * URL forms accepted:
 *   https://host/arlm/api/v1/activate                      (mod_rewrite)
 *   https://host/arlm/api/v1/index.php?endpoint=activate   (fallback)
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use ARLM\ApiAuth;
use ARLM\ApiException;
use ARLM\Http;
use ARLM\LicenseEngine;
use ARLM\RateLimiter;
use ARLM\Settings;
use ARLM\Logger;

header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$start = microtime(true);
$endpoint = preg_replace('/[^a-z_]/', '', (string) ($_GET['endpoint'] ?? ''));
if ($endpoint === '') {
    Http::jsonOut(404, ['success' => false, 'error_code' => 'UNKNOWN_ENDPOINT', 'message' => 'Missing endpoint. Use /api/v1/{activate|validate|deactivate|check|products|portal_licenses|portal_deactivate}.']);
}

Http::requireHttps((bool) \ARLM\Config::get('allow_http_debug', false));

$isPost = $endpoint !== 'products';
$method = Http::method();
if ($method !== ($isPost ? 'POST' : 'GET')) {
    Http::jsonOut(405, ['success' => false, 'error_code' => 'METHOD_NOT_ALLOWED', 'message' => 'Use ' . ($isPost ? 'POST' : 'GET') . ' for this endpoint.']);
}

$status = 200;
$errorCode = null;
$client = null;
$ctx = null;
$response = null;

try {
    $client = ApiAuth::authenticate();

    if (!LicenseEngine::hasPermission($client, $endpoint)) {
        throw new ApiException(403, 'PERMISSION_DENIED', 'This API client lacks permission for this endpoint.');
    }

    if (Settings::get('maintenance_mode', '0') === '1') {
        throw new ApiException(503, 'MAINTENANCE_MODE', 'The license server is in maintenance mode. Try again later.');
    }

    if (!$isPost) {
        RateLimiter::check((int) $client['id'], (int) $client['rate_limit_m']);
    }

    if ($isPost) {
        $body = Http::jsonBody();
        $ctx = ApiAuth::authenticatePost($body);

        $stored = ApiAuth::replayedResponse((int) $client['id'], $ctx['request_id']);
        if ($stored !== null) {
            Http::jsonOut(200, $stored);
        }

        $response = match ($endpoint) {
            'activate'   => LicenseEngine::activate($body, $ctx),
            'validate'   => LicenseEngine::validate($body, $ctx),
            'deactivate' => LicenseEngine::deactivate($body, $ctx),
            'check'      => LicenseEngine::check($body, $ctx),
            'portal_licenses'  => LicenseEngine::portalLicenses($body, $ctx),
            'portal_deactivate' => LicenseEngine::portalDeactivate($body, $ctx),
            default      => throw new ApiException(404, 'UNKNOWN_ENDPOINT', 'Unknown API endpoint.'),
        };
    } else {
        $response = LicenseEngine::products();
    }
} catch (ApiException $e) {
    $status = $e->statusCode;
    $errorCode = $e->errorCode;
    $response = ['success' => false, 'error_code' => $e->errorCode, 'message' => $e->getMessage()];
} catch (\Throwable $e) {
    $status = 500;
    $errorCode = 'SERVER_ERROR';
    error_log('ARLM API error on ' . $endpoint . ': ' . $e->getMessage());
    $response = ['success' => false, 'error_code' => 'SERVER_ERROR', 'message' => 'Unexpected server error.'];
}

$json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
Logger::api(
    $client === null ? null : (int) $client['id'],
    $endpoint,
    $method,
    [
        'request_id'    => $ctx['request_id'] ?? null,
        'license_ref'   => LicenseEngine::licenseRef(),
        'ip'            => Http::clientIp(),
        'status_code'   => $status,
        'error_code'    => $errorCode,
        'duration_ms'   => (int) round((microtime(true) - $start) * 1000),
        'response_json' => $json === false ? null : $json,
    ]
);

Http::jsonOut($status, $response);
