<?php
/**
 * AR License Manager - SMF 2.1 SDK example for YOUR OWN modifications.
 *
 * Copy ARLMClient.php (sdk/php) into $sourcedir alongside this file, or
 * fold the class into your own Sources file. Plain SMF 2.1 style code.
 *
 * Security notes:
 *  - The license key is entered once by the forum admin during activation
 *    and stored in SMF settings so periodic re-validation can use it. It is
 *    YOUR customer's key on THEIR forum; the central server still only ever
 *    stores the hash.
 *  - Never embed API client secrets in software you distribute.
 *
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */

if (!defined('SMF')) {
    die('Hacking attempt...');
}

require_once __DIR__ . '/ARLMClient.php';

/** @internal Builds a client from SMF settings (arlm_sdk_*). */
function arlmSdkClient(): ARLMClient
{
    global $modSettings;

    return new ARLMClient(
        (string) ($modSettings['arlm_sdk_server_url'] ?? ''),
        (string) ($modSettings['arlm_sdk_client_id'] ?? ''),
        (string) ($modSettings['arlm_sdk_secret'] ?? ''),
        (string) ($modSettings['arlm_sdk_public_key'] ?? ''),
        20,
        false
    );
}

/** One-Time setup: run once from your mod's install code. */
function arlmSdkInstall(): void
{
    updateSettings(array(
        'arlm_sdk_server_url' => 'https://arnitcreation.online/arlm',
        'arlm_sdk_client_id'  => '',
        'arlm_sdk_secret'     => '',
        'arlm_sdk_public_key' => '',
    ));
}

/**
 * Activates this forum's domain on a license. Call from an admin screen of
 * your mod where the customer pastes their key. Returns the signed response.
 */
function arlmSdkActivate(string $licenseKey, string $productUid): array
{
    global $boardurl;

    $client = arlmSdkClient();
    $host = parse_url((string) $boardurl, PHP_URL_HOST) ?: '';
    $response = $client->activate($licenseKey, $productUid, (string) $host);
    $client->assertVerified($response);

    if (!empty($response['success'])) {
        updateSettings(array(
            'arlm_sdk_key_' . $productUid      => trim($licenseKey),
            'arlm_sdk_status_' . $productUid   => (string) $response['status'],
            'arlm_sdk_expires_' . $productUid  => (string) ($response['expires_at'] ?? ''),
        ));
    }
    return $response;
}

/** Removes the stored license (e.g. on mod uninstall or key change). */
function arlmSdkClear(string $productUid): void
{
    removeSettings(array(
        'arlm_sdk_key_' . $productUid,
        'arlm_sdk_status_' . $productUid,
        'arlm_sdk_expires_' . $productUid,
    ));
}

/**
 * Cached validation used by your premium features. Re-checks against the
 * license server at most once per $ttlSeconds; falls back to the locally
 * cached status (with expiry) when the server is unreachable.
 */
function arlmSdkIsLicensed(string $productUid, int $ttlSeconds = 3600): bool
{
    global $modSettings, $boardurl;

    $cacheKey = 'arlm_sdk_valid_' . $productUid;
    $cached = cache_get_data($cacheKey, $ttlSeconds);
    if ($cached !== null) {
        return (bool) $cached;
    }

    $key = (string) ($modSettings['arlm_sdk_key_' . $productUid] ?? '');
    $status = (string) ($modSettings['arlm_sdk_status_' . $productUid] ?? '');
    $expires = (string) ($modSettings['arlm_sdk_expires_' . $productUid] ?? '');
    if ($key === '' || $status !== 'active' || ($expires !== '' && strtotime($expires) < time())) {
        cache_put_data($cacheKey, false, $ttlSeconds);
        return false;
    }

    $client = arlmSdkClient();
    $host = parse_url((string) $boardurl, PHP_URL_HOST) ?: '';
    try {
        $response = $client->check($key, $productUid, (string) $host);
        $client->assertVerified($response);
        $valid = !empty($response['success']) && ($response['status'] ?? '') === 'active';
        updateSettings(array(
            'arlm_sdk_status_' . $productUid  => (string) ($response['status'] ?? $status),
            'arlm_sdk_expires_' . $productUid => (string) ($response['expires_at'] ?? $expires),
        ));
    } catch (Throwable) {
        // Server unreachable: trust the locally stored status and expiry.
        cache_put_data($cacheKey, $status === 'active', min($ttlSeconds, 300));
        return $status === 'active';
    }
    cache_put_data($cacheKey, $valid, $ttlSeconds);
    return $valid;
}
