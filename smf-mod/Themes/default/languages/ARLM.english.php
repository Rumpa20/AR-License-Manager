<?php
/**
 * AR License Manager v1.0 - English language strings.
 * Translations (e.g. Bengali, Hindi) can be added later as
 * ARLM.bengali.php / ARLM.hindi.php with the same keys; no template text
 * is hardcoded, so dropping in a new file is enough.
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */

$arlm_strings = array(
    'arlm_admin_area'        => 'AR License Manager',
    'arlm_dashboard'         => 'License Dashboard',
    'arlm_settings'          => 'License Server Settings',
    'arlm_server'            => 'License server',
    'arlm_server_url'        => 'License Server URL (https)',
    'arlm_client_id'         => 'API Client ID',
    'arlm_client_secret'     => 'API Client Secret',
    'arlm_public_key'        => 'Server public signing key (Ed25519, optional)',
    'arlm_support_email'     => 'Support email for license holders',
    'arlm_secret_note'       => 'The client secret is stored server-side in the SMF settings table and is required to talk to the license server. Copy it once when you create the API client in the central AR License Manager panel (License Server > API Clients).',
    'arlm_test_connection'   => 'Test connection',
    'arlm_test_note'         => 'Sends a real request to the license server to verify the credentials are working.',
    'arlm_test_ok'           => 'Connection OK: the server listed %d products.',
    'arlm_connection_ok'     => 'Connection OK: the server listed %d products.',
    'arlm_connection_fail'   => 'Connection failed:',
    'arlm_saved'             => 'Settings saved.',
    'arlm_open_panel'        => 'Open central admin panel',
    'arlm_product'           => 'Product',
    'arlm_product_id'        => 'Product ID',
    'arlm_version'           => 'Version',
    'arlm_license_type'      => 'License type',
    'arlm_activation_limit'  => 'Activation limit',
    'arlm_admin_central_note'=> 'License generation, orders, API clients and logs are managed in the central AR License Manager panel (Link above). This page always shows live, real data from your license server - it displays nothing if the server cannot be reached.',
    'arlm_portal_title'      => 'My Licenses',
    'arlm_no_licenses'       => 'You do not have any licenses associated with your account email yet. If you purchased a product, contact support with your purchase reference.',
    'arlm_license_id'        => 'License ID',
    'arlm_license_type'      => 'License type',
    'arlm_expires'           => 'Expires',
    'arlm_never'             => 'Never',
    'arlm_activations'       => 'Activations',
    'arlm_domain'            => 'Domain',
    'arlm_status'            => 'Status',
    'arlm_activated'         => 'Activated at',
    'arlm_last_seen'         => 'Last seen',
    'arlm_actions'           => 'Actions',
    'arlm_release_domain'    => 'Deactivate domain',
    'arlm_domain_released'   => 'The domain was deactivated. You can now activate this license on a different domain.',
    'arlm_confirm_release'   => 'Deactivate this domain for this license?',
    'arlm_support_note'      => 'Need help with this license? Contact: %s',
    'arlm_err_notconfigured' => 'The AR License Manager connection is not configured yet. Enter the server URL and API client credentials in the admin area (License Server Settings).',
    'arlm_err_https'         => 'The license server URL must start with https:// - plain HTTP is not allowed.',
    'arlm_err_request'       => 'The request was incomplete. Reload the page and try again.',
    'arlm_err_no_email'      => 'Your account has no email address, so your licenses cannot be looked up. Add an email to your forum account first.',
    'arlm_err_http'          => 'The license server could not be reached or returned an unexpected answer.',
    'arlm_err_badjson'       => 'The license server returned a non-JSON response. Check the Server URL in the admin settings.',
);

global $txt;

foreach ($arlm_strings as $arlm_key => $arlm_value) {
    $txt[$arlm_key] = $arlm_value;
}
