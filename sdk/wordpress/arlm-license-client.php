<?php
/**
 * Plugin Name: AR License Manager Client
 * Description: Activates and validates AR License Manager licenses for your own plugin/theme products. Wraps the dependency-free ARLMClient PHP SDK.
 * Version: 1.0.0
 * Author: ARNIT CREATION
 * Requires PHP: 8.1
 */

/*
 * Copyright (c) ARNIT CREATION. All rights reserved.
 *
 * HOW TO USE IN YOUR OWN WORDPRESS PLUGIN:
 *   1. Install this plugin and open Settings > AR License Manager.
 *   2. Enter your AR License Manager server URL and an API Client ID +
 *      Secret created in the central admin panel (Admin > API Clients).
 *      IMPORTANT: never embed a private API secret in a plugin you
 *      distribute publicly; instead run license checks server-side in
 *      YOUR OWN backend, or ship a read-only client per customer.
 *   3. Call from your code:
 *
 *   if (function_exists('arlm_wp_check_license') && arlm_wp_check_license('PRODUCT-001')) {
 *       // license is valid for this site
 *   } else {
 *       // disable premium features, show activation screen
 *   }
 *
 *   arlm_wp_activate_license('PRODUCT-001', 'ARLM-XXXX-XXXX-XXXX-XXXX');
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ARLMClient.php';

/** @internal */
function arlm_wp_client(): ARLMClient
{
    $options = get_option('arlm_wp_settings', []);
    return new ARLMClient(
        isset($options['server_url']) ? (string) $options['server_url'] : '',
        isset($options['client_id']) ? (string) $options['client_id'] : '',
        isset($options['secret']) ? (string) $options['secret'] : '',
        isset($options['public_key']) ? (string) $options['public_key'] : '',
        20,
        false
    );
}

/** Stores the license key (hashed by the SERVER; this saves it locally). */
function arlm_wp_activate_license(string $productId, string $licenseKey, ?string $domain = null): array
{
    $options = get_option('arlm_wp_settings', []);
    $domain = $domain !== null ? $domain : (wp_parse_url(home_url(), PHP_URL_HOST) ?: '');
    try {
        $client = arlm_wp_client();
        $response = $client->activate($licenseKey, $productId, (string) $domain);
        $client->assertVerified($response);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
    if (!empty($response['success'])) {
        $options['license_keys'][$productId] = trim($licenseKey);
        $options['license_status'][$productId] = (string) $response['status'];
        update_option('arlm_wp_settings', $options);
    }
    return ['success' => (bool) ($response['success'] ?? false), 'message' => (string) ($response['message'] ?? '')];
}

/** Re-validates a stored license against the license server. */
function arlm_wp_check_license(string $productId): bool
{
    $options = get_option('arlm_wp_settings', []);
    $cached = $options['license_status'][$productId] ?? '';
    if ($cached !== 'active' && get_transient('arlm_wp_fail_' . $productId) !== false) {
        return false;
    }
    $key = (string) ($options['license_keys'][$productId] ?? '');
    if ($key === '') {
        return false;
    }
    try {
        $client = arlm_wp_client();
        $response = $client->validate($key, $productId);
        $client->assertVerified($response);
    } catch (Throwable $e) {
        set_transient('arlm_wp_fail_' . $productId, 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }
    if (!empty($response['success']) && ($response['status'] ?? '') === 'active') {
        $options['license_status'][$productId] = 'active';
        update_option('arlm_wp_settings', $options);
        return true;
    }
    $options['license_status'][$productId] = (string) ($response['status'] ?? 'invalid');
    update_option('arlm_wp_settings', $options);
    return false;
}

/* --------------------------- Admin settings page -------------------------- */

add_action('admin_menu', static function () {
    add_options_page('AR License Manager', 'AR License Manager', 'manage_options', 'arlm-wp', static function () {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['arlm_wp_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['arlm_wp_nonce'])), 'arlm_wp_save')) {
            update_option('arlm_wp_settings', [
                'server_url' => esc_url_raw(wp_unslash($_POST['server_url'] ?? '')),
                'client_id'  => sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
                'secret'     => sanitize_text_field(wp_unslash($_POST['secret'] ?? '')),
                'public_key' => sanitize_textarea_field(wp_unslash($_POST['public_key'] ?? '')),
            ]);
            echo '<div class="updated"><p>Saved.</p></div>';
        }
        $o = get_option('arlm_wp_settings', []);
        ?>
        <div class="wrap">
            <h1>AR License Manager</h1>
            <form method="post">
                <?php wp_nonce_field('arlm_wp_save', 'arlm_wp_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Server URL</th>
                        <td><input type="url" name="server_url" value="<?php echo esc_attr($o['server_url'] ?? 'https://www.abruar.online/arlm'); ?>" class="regular-text" required></td></tr>
                    <tr><th scope="row">API Client ID</th>
                        <td><input type="text" name="client_id" value="<?php echo esc_attr($o['client_id'] ?? ''); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">API Client Secret</th>
                        <td><input type="password" name="secret" value="<?php echo esc_attr($o['secret'] ?? ''); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr><th scope="row">Public signing key (base64url)</th>
                        <td><textarea name="public_key" rows="3" class="large-text code"><?php echo esc_textarea($o['public_key'] ?? ''); ?></textarea></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    });
});
