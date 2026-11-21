<?php
/**
 * AR License Manager v1.0 - SMF default theme templates.
 * All visible text comes from ARLM.<language>.php; markup is plain
 * lightweight HTML that inherits the SMF theme's own classes so the pages
 * look native on any SMF 2.1 theme.
 * Copyright (c) ARNIT CREATION. All rights reserved.
 */

if (!defined('SMF')) {
    die('Hacking attempt...');
}

/** Admin center, sa=home: connection status + product list. */
function template_arlm_admin_home(): void
{
    global $context, $settings, $scripturl, $txt;

    echo '
    <div class="cat_bar">
        <h3 class="catbg">', $txt['arlm_admin_area'], '</h3>
    </div>
    <div class="windowbg">
        <p class="arlm-status ', $context['arlm_connection'] ? 'arlm-ok' : 'arlm-bad', '">', $context['arlm_connection_message'], '</p>';

    if (!$context['arlm_configured']) {
        echo '
        <div class="noticebox arlm-bad">', $txt['arlm_err_notconfigured'], '
            <a href="', $scripturl, '?action=admin;area=arlm;sa=settings">', $txt['arlm_settings'], '</a>
        </div>';
    }

    echo '
        <p>', $txt['arlm_server'], ': <strong>', $context['arlm_server_url'], '</strong></p>
        <p><a class="button" href="', $scripturl, '?action=admin;area=arlm;sa=settings">', $txt['arlm_settings'], '</a>
           <a class="button" href="', $context['arlm_server_url'], '" target="_blank" rel="noopener">', $txt['arlm_open_panel'], '</a></p>';

    if ($context['arlm_products'] !== []) {
        echo '
        <table class="table_grid">
            <thead>
                <tr class="title_bar">
                    <th scope="col">', $txt['arlm_product'], '</th>
                    <th scope="col">', $txt['arlm_product_id'], '</th>
                    <th scope="col">', $txt['arlm_version'], '</th>
                    <th scope="col">', $txt['arlm_license_type'], '</th>
                    <th scope="col">', $txt['arlm_activation_limit'], '</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($context['arlm_products'] as $arlm_p) {
            echo '
                <tr class="windowbg">
                    <td>', (string) $arlm_p['name'], '</td>
                    <td>', (string) $arlm_p['product_uid'], '</td>
                    <td>', (string) $arlm_p['version'], '</td>
                    <td>', (string) $arlm_p['default_license_type'], '</td>
                    <td>', (int) $arlm_p['default_activation_limit'], '</td>
                </tr>';
        }
        echo '
            </tbody>
        </table>';
    }

    echo '
        <p class="smalltext">', $txt['arlm_admin_central_note'], '</p>
    </div>';
}

/** Admin center, sa=settings: connection credentials form. */
function template_arlm_admin_settings(): void
{
    global $context, $scripturl, $txt, $modSettings;

    echo '
    <div class="cat_bar">
        <h3 class="catbg">', $txt['arlm_settings'], '</h3>
    </div>';

    if (!empty($context['arlm_saved'])) {
        echo '
    <div class="infobox">', $txt['arlm_saved'], '</div>';
    }
    if (!empty($context['arlm_error'])) {
        echo '
    <div class="errorbox">', $context['arlm_error'], '</div>';
    }
    if ($context['arlm_test_result'] !== null) {
        echo '
    <div class="infobox">', $context['arlm_test_result'], '</div>';
    }

    echo '
    <div class="windowbg">
        <form method="post" action="', $scripturl, '?action=admin;area=arlm;sa=settings">
            <input type="hidden" name="', $context['arlm_admin_token_var'], '" value="', $context['arlm_admin_token'], '">
            <input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
            <input type="hidden" name="do" value="save">
            <dl class="settings">
                <dt><label for="arlm_server_url">', $txt['arlm_server_url'], '</label></dt>
                <dd><input type="url" id="arlm_server_url" name="arlm_server_url" value="', $context['arlm_server_url'], '" size="45" required></dd>
                <dt><label for="arlm_api_client_id">', $txt['arlm_client_id'], '</label></dt>
                <dd><input type="text" id="arlm_api_client_id" name="arlm_api_client_id" value="', (string) ($modSettings['arlm_api_client_id'] ?? ''), '" size="40"></dd>
                <dt><label for="arlm_api_secret">', $txt['arlm_client_secret'], '</label></dt>
                <dd><input type="password" id="arlm_api_secret" name="arlm_api_secret" value="', (string) ($modSettings['arlm_api_secret'] ?? ''), '" size="70" autocomplete="off"></dd>
                <dt><label for="arlm_sign_public_key">', $txt['arlm_public_key'], '</label></dt>
                <dd><textarea id="arlm_sign_public_key" name="arlm_sign_public_key" rows="3" cols="60">', (string) ($modSettings['arlm_sign_public_key'] ?? ''), '</textarea></dd>
                <dt><label for="arlm_support_email">', $txt['arlm_support_email'], '</label></dt>
                <dd><input type="email" id="arlm_support_email" name="arlm_support_email" value="', (string) ($modSettings['arlm_support_email'] ?? ''), '" size="45"></dd>
            </dl>
            <p class="smalltext">', $txt['arlm_secret_note'], '</p>
            <input type="submit" name="save" value="', $txt['arlm_save'], '" class="button">', '
        </form>
    </div>
    <div class="windowbg">
        <form method="post" action="', $scripturl, '?action=admin;area=arlm;sa=settings">
            <input type="hidden" name="', $context['arlm_admin_token_var'], '" value="', $context['arlm_admin_token'], '">
            <input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
            <input type="hidden" name="do" value="test">
            <p>', $txt['arlm_test_note'], '</p>
            <input type="submit" name="test" value="', $txt['arlm_test_connection'], '" class="button">', '
        </form>
    </div>';
}

/** Customer portal: index.php?action=licenses. */
function template_arlm_portal_main(): void
{
    global $context, $scripturl, $txt, $user_info;

    echo '
    <div class="cat_bar">
        <h3 class="catbg">', $txt['arlm_portal_title'], '</h3>
    </div>';

    if ($context['arlm_done'] !== null) {
        echo '
    <div class="infobox">', $context['arlm_done'], '</div>';
    }
    if ($context['arlm_error'] !== null) {
        echo '
    <div class="errorbox">', $context['arlm_error'], '</div>';
    }

    if ($context['arlm_licenses'] === []) {
        echo '
    <div class="windowbg">
        <p>', $txt['arlm_no_licenses'], '</p>
    </div>';
        return;
    }

    foreach ($context['arlm_licenses'] as $arlm_l) {
        $arlm_status = (string) $arlm_l['status'];
        echo '
    <div class="windowbg arlm-license">
        <h4>', (string) $arlm_l['product_name'], ' <span class="arlm-badge arlm-st-', $arlm_status, '">', $arlm_status, '</span></h4>
        <dl class="settings">
            <dt>', $txt['arlm_license_id'], '</dt><dd>#', (int) $arlm_l['license_id'], '</dd>
            <dt>', $txt['arlm_license_type'], '</dt><dd>', (string) $arlm_l['license_type'], '</dd>
            <dt>', $txt['arlm_expires'], '</dt><dd>', $arlm_l['expires_at'] === null ? (string) $txt['arlm_never'] : (string) $arlm_l['expires_at'], '</dd>
            <dt>', $txt['arlm_activations'], '</dt><dd>', (int) $arlm_l['current_activations'], ' / ', (int) $arlm_l['max_activations'], '</dd>
        </dl>';

        echo '
        <table class="table_grid">
            <thead>
                <tr class="title_bar">
                    <th scope="col">', $txt['arlm_domain'], '</th>
                    <th scope="col">', $txt['arlm_status'], '</th>
                    <th scope="col">', $txt['arlm_activated'], '</th>
                    <th scope="col">', $txt['arlm_last_seen'], '</th>
                    <th scope="col">', $txt['arlm_actions'], '</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($arlm_l['domains'] as $arlm_d) {
            echo '
                <tr class="windowbg">
                    <td>', (string) $arlm_d['domain'], '</td>
                    <td>', (string) $arlm_d['status'], '</td>
                    <td>', (string) $arlm_d['activated_at'], '</td>
                    <td>', (string) ($arlm_d['last_seen_at'] ?? '-'), '</td>';
            if ($arlm_d['status'] === 'active') {
                echo '
                    <td>
                        <form method="post" action="', $scripturl, '?action=licenses" onsubmit="return confirm(\'', $txt['arlm_confirm_release'], '\');">
                            <input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
                            <input type="hidden" name="do" value="deactivate">
                            <input type="hidden" name="license_id" value="', (int) $arlm_l['license_id'], '">
                            <input type="hidden" name="domain" value="', (string) $arlm_d['domain'], '">
                            <input type="submit" value="', $txt['arlm_release_domain'], '" class="button">', '
                        </form>
                    </td>';
            } else {
                echo '
                    <td>-</td>';
            }
            echo '
                </tr>';
        }
        echo '
            </tbody>
        </table>
        <p class="smalltext">', sprintf($txt['arlm_support_note'], $context['arlm_support_note']), '</p>
    </div>';
    }
}
