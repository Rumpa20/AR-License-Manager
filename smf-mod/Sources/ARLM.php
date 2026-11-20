<?php
/**
 * AR License Manager v1.0 - SMF 2.1 source file.
 *
 * Provides:
 *  - An "AR License Manager" area in the admin center (dashboard +
 *    connection settings) via the integrate_admin_areas hook.
 *  - A customer license portal at index.php?action=licenses via the
 *    integrate_actions hook. Members only ever see licenses that belong to
 *    their own account email; every state change is validated by the
 *    central license server.
 *
 * This file modifies nothing in SMF core and only talks to the central
 * license server over HTTPS using the client credentials configured by
 * the forum administrator.
 *
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */

if (!defined('SMF')) {
    die('Hacking attempt...');
}

/**
 * integrate_admin_areas hook: adds the AR License Manager area.
 *
 * @param array $admin_areas Admin menu structure (passed by reference)
 */
function arlm_admin_areas(&$admin_areas)
{
    global $txt, $context;

    loadLanguage('ARLM');

    $admin_areas['config']['areas']['arlm'] = array(
        'label'       => $txt['arlm_admin_area'],
        'file'        => '$sourcedir/ARLM.php',
        'function'    => 'arlm_admin_dispatch',
        'icon'        => 'server',
        'permission'  => array('admin_forum'),
        'subsections' => array(
            'home'     => array($txt['arlm_dashboard']),
            'settings' => array($txt['arlm_settings']),
        ),
    );
}

/**
 * integrate_actions hook: registers the customer portal action.
 *
 * @param array $actionArray SMF action map (passed by reference)
 */
function arlm_actions(&$actionArray)
{
    $actionArray['licenses'] = array('ARLM.php', 'arlm_portal');
}

/** Entry point for ?action=admin;area=arlm (requires admin_forum). */
function arlm_admin_dispatch()
{
    global $context;

    isAllowedTo('admin_forum');
    loadLanguage('ARLM');
    loadTemplate('ARLM');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkSession('post');
        validateToken('arlm_admin');
    }
    createToken('arlm_admin');

    $sa = isset($_REQUEST['sa']) ? (string) $_REQUEST['sa'] : 'home';
    if ($sa === 'settings') {
        arlm_admin_settings();
    } else {
        arlm_admin_home();
    }
}

/** Dashboard: connection status, product list, links to the central panel. */
function arlm_admin_home()
{
    global $context, $txt;

    $context['page_title'] = $txt['arlm_dashboard'];
    $context['sub_template'] = 'arlm_admin_home';

    $arlm_result = arlm_api_call('products', 'GET');
    $context['arlm_connection'] = $arlm_result['ok'];
    $context['arlm_connection_message'] = $arlm_result['ok']
        ? sprintf($txt['arlm_connection_ok'], (int) ($arlm_result['data']['count'] ?? 0))
        : $txt['arlm_connection_fail'] . ' ' . $arlm_result['error'];
    $context['arlm_products'] = $arlm_result['ok'] && isset($arlm_result['data']['products'])
        ? $arlm_result['data']['products']
        : array();
    $context['arlm_server_url'] = arlm_cfg('arlm_server_url');
    $context['arlm_configured'] = arlm_cfg('arlm_api_client_id') !== '' && arlm_cfg('arlm_api_secret') !== '';
}

/** Settings page: save credentials, test the connection. */
function arlm_admin_settings()
{
    global $context, $txt;

    $context['page_title'] = $txt['arlm_settings'];
    $context['sub_template'] = 'arlm_admin_settings';
    $context['arlm_saved'] = false;
    $context['arlm_test_result'] = null;
    $context['arlm_error'] = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $arlm_do = isset($_POST['do']) ? (string) $_POST['do'] : '';

        if ($arlm_do === 'save') {
            $arlm_url = filter_var(trim((string) ($_POST['arlm_server_url'] ?? '')), FILTER_VALIDATE_URL) ?: '';
            if ($arlm_url !== '' && strpos($arlm_url, 'https://') !== 0) {
                $context['arlm_error'] = $txt['arlm_err_https'];
            } else {
                updateSettings(array(
                    'arlm_server_url'      => rtrim($arlm_url, '/'),
                    'arlm_api_client_id'   => substr(trim((string) ($_POST['arlm_api_client_id'] ?? '')), 0, 32),
                    'arlm_api_secret'      => trim((string) ($_POST['arlm_api_secret'] ?? '')),
                    'arlm_sign_public_key' => trim((string) ($_POST['arlm_sign_public_key'] ?? '')),
                    'arlm_support_email'   => trim((string) ($_POST['arlm_support_email'] ?? '')),
                ));
                $context['arlm_saved'] = true;
            }
        } elseif ($arlm_do === 'test') {
            $arlm_result = arlm_api_call('products', 'GET');
            if ($arlm_result['ok']) {
                $context['arlm_test_result'] = sprintf($txt['arlm_connection_ok'], (int) ($arlm_result['data']['count'] ?? 0));
            } else {
                $context['arlm_test_result'] = $txt['arlm_connection_fail'] . ' ' . $arlm_result['error'];
            }
        }
    }
}

/** Customer portal: index.php?action=licenses (members only). */
function arlm_portal()
{
    global $context, $user_info, $txt, $webmaster_email;

    is_not_guest();
    loadLanguage('ARLM');
    loadTemplate('ARLM');

    $context['page_title'] = $txt['arlm_portal_title'];
    $context['sub_template'] = 'arlm_portal_main';
    $context['arlm_licenses'] = array();
    $context['arlm_error'] = null;
    $context['arlm_done'] = null;
    $context['arlm_member_email'] = (string) ($user_info['email'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['do'] ?? '') === 'deactivate') {
        checkSession('post');
        $arlm_license = (int) ($_POST['license_id'] ?? 0);
        $arlm_domain = trim((string) ($_POST['domain'] ?? ''));
        if ($arlm_license > 0 && $arlm_domain !== '') {
            $arlm_result = arlm_api_call('portal_deactivate', 'POST', array(
                'email'      => $context['arlm_member_email'],
                'license_id' => $arlm_license,
                'domain'     => $arlm_domain,
            ));
            $context['arlm_done'] = $arlm_result['ok'] ? $txt['arlm_domain_released'] : null;
            if (!$arlm_result['ok']) {
                $context['arlm_error'] = $arlm_result['error'];
            }
        } else {
            $context['arlm_error'] = $txt['arlm_err_request'];
        }
    }

    if ($context['arlm_member_email'] === '') {
        $context['arlm_error'] = $txt['arlm_err_no_email'];
    } else {
        $arlm_result = arlm_api_call('portal_licenses', 'POST', array(
            'email' => $context['arlm_member_email'],
        ));
        if ($arlm_result['ok'] && isset($arlm_result['data']['licenses'])) {
            $context['arlm_licenses'] = $arlm_result['data']['licenses'];
        } else {
            $context['arlm_error'] = $txt['arlm_connection_fail'] . ' ' . $arlm_result['error'];
        }
    }

    $context['arlm_support_note'] = arlm_cfg('arlm_support_email') !== ''
        ? arlm_cfg('arlm_support_email')
        : (string) ($webmaster_email ?? '');
}

/** Reads an arlm_* setting from $modSettings, trimmed. */
function arlm_cfg(string $key): string
{
    global $modSettings;

    return trim((string) ($modSettings[$key] ?? ''));
}

/**
 * Calls the central license server REST API.
 *
 * @return array{ok: bool, code: int, error: string|null, data: array|null}
 */
function arlm_api_call(string $endpoint, string $method = 'GET', array $body = []): array
{
    global $txt;

    $arlm_server = rtrim(arlm_cfg('arlm_server_url'), '/');
    $arlm_client_id = arlm_cfg('arlm_api_client_id');
    $arlm_secret = arlm_cfg('arlm_api_secret');
    if ($arlm_server === '' || $arlm_client_id === '' || $arlm_secret === '') {
        return array('ok' => false, 'code' => 0, 'error' => $txt['arlm_err_notconfigured'], 'data' => null);
    }
    $arlm_url = $arlm_server . '/api/v1/' . $endpoint;

    $arlm_request_body = null;
    if ($method === 'POST') {
        $body['timestamp'] = time();
        $body['request_id'] = bin2hex(random_bytes(16));
        $arlm_request_body = json_encode($body);
    }

    $arlm_headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'X-ARLM-Client-Id: ' . $arlm_client_id,
        'X-ARLM-Secret: ' . $arlm_secret,
    );

    if (function_exists('curl_init')) {
        $arlm_ch = curl_init($arlm_url);
        curl_setopt_array($arlm_ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $arlm_headers,
            CURLOPT_USERAGENT      => 'ARLM-SMF-Mod/1.0.0',
        ));
        if ($method === 'POST') {
            curl_setopt_array($arlm_ch, array(
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => $arlm_request_body,
            ));
        }
        $arlm_raw = curl_exec($arlm_ch);
        $arlm_code = (int) curl_getinfo($arlm_ch, CURLINFO_HTTP_CODE);
        $arlm_err = $arlm_raw === false ? curl_error($arlm_ch) : null;
        curl_close($arlm_ch);
    } else {
        $arlm_ctx = stream_context_create(array(
            'http' => array(
                'method'          => $method,
                'header'          => implode("\r\n", $arlm_headers),
                'content'         => $arlm_request_body,
                'ignore_errors'   => true,
                'timeout'         => 20,
                'follow_location' => 0,
            ),
            'ssl' => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ),
        ));
        $arlm_raw = @file_get_contents($arlm_url, false, $arlm_ctx);
        $arlm_code = 0;
        $arlm_err = $arlm_raw === false ? ($txt['arlm_err_http'] . ' (curl not available)') : null;
        foreach ($http_response_header ?? array() as $arlm_line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $arlm_line, $arlm_m)) {
                $arlm_code = (int) $arlm_m[1];
            }
        }
    }

    if ($arlm_raw === false) {
        return array('ok' => false, 'code' => (int) $arlm_code, 'error' => (string) $arlm_err, 'data' => null);
    }
    $arlm_data = json_decode((string) $arlm_raw, true);
    if (!is_array($arlm_data)) {
        return array('ok' => false, 'code' => (int) $arlm_code, 'error' => $txt['arlm_err_badjson'], 'data' => null);
    }
    if ($arlm_code !== 200 || empty($arlm_data['success'])) {
        $arlm_errtext = isset($arlm_data['error_code']) ? $arlm_data['error_code'] . ': ' : '';
        $arlm_errtext .= (string) ($arlm_data['message'] ?? $txt['arlm_err_http']);
        return array('ok' => false, 'code' => (int) $arlm_code, 'error' => $arlm_errtext, 'data' => $arlm_data);
    }
    return array('ok' => true, 'code' => (int) $arlm_code, 'error' => null, 'data' => $arlm_data);
}
