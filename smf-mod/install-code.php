<?php
/**
 * AR License Manager - SMF package install code.
 * Runs once during package installation inside the SMF package manager.
 * Creates NO tables and changes NO core files: it only seeds default
 * settings (never overwriting values that already exist) and verifies the
 * PHP 8.1+ / sodium requirements.
 * Copyright (c) ARNIT CREATION.
 */

global $smcFunc;

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    die('AR License Manager 1.0 requires PHP 8.1.0 or newer. This server reports ' . PHP_VERSION . '. Upgrade PHP in your hosting panel, then install the package again.');
}
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_sign_verify_detached')) {
    die('AR License Manager 1.0 requires the PHP sodium extension (Ed25519 signatures). Enable sodium in your hosting control panel, then install the package again.');
}

$arlm_defaults = array(
    'arlm_server_url'      => 'https://arnitcreation.online/arlm',
    'arlm_api_client_id'   => '',
    'arlm_api_secret'      => '',
    'arlm_sign_public_key' => '',
    'arlm_support_email'   => '',
);

$arlm_rows = array();
foreach ($arlm_defaults as $arlm_var => $arlm_value) {
    $arlm_rows[] = array($arlm_var, $arlm_value);
}

$smcFunc['db_insert'](
    'ignore',
    '{db_prefix}settings',
    array('variable' => 'string-64', 'value' => 'string-65534'),
    $arlm_rows,
    array('variable')
);
