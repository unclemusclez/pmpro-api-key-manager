<?php
/**
 * Plugin Name: Paid Memberships Pro - API Key Manager
 * Description: Manage API keys for FastAPI apps with Paid Memberships Pro
 * Version: 0.0.1
 * Plugin URI: https://github.com/unclemusclez/pmpro-api-key-manager
 * Author: Devin J. Dawson
 * Author URI: https://waterpistol.co
 * Text Domain: pmpro-mosparo-integration
 * Domain Path: /languages
 * License: GPL v3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

// Activation: Create keys table
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'pmpro_api_keys';
    $sql = "CREATE TABLE $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        app_id VARCHAR(50) NOT NULL,
        key_id VARCHAR(36) NOT NULL,
        tier VARCHAR(50) NOT NULL,
        permissions TEXT NOT NULL,
        active TINYINT(1) DEFAULT 1,
        UNIQUE (key_id)
    ) " . $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

add_action('admin_menu', function () {
    add_options_page('API Key Manager', 'API Key Manager', 'manage_options', 'pmpro-api-keys', function () {
        if (isset($_POST['save_configs'])) {
            $configs = [];
            foreach ($_POST['apps'] as $app_id => $app) {
                $configs[$app_id] = [
                    'url' => sanitize_text_field($app['url']),
                    'levels' => []
                ];
                foreach ($app['levels'] as $level_id => $level) {
                    $perms = [
                        'limits' => [],
                        'flags' => []
                    ];
                    foreach ($level['limits'] as $flag => $limits) {
                        $perms['limits'][$flag] = [
                            'hour' => intval($limits['hour']),
                            'day' => intval($limits['day'])
                        ];
                    }
                    foreach ($level['flags'] as $flag => $value) {
                        $perms['flags'][$flag] = boolval($value);
                    }
                    $configs[$app_id]['levels'][$level_id] = ['permissions' => $perms];
                }
            }
            update_option('pmpro_api_configs', $configs);
        }
        // Render form with inputs for app URL, levels, limits (hour/day), and flags
    });
});

// Key Generation: Updated to send detailed permissions
add_action('pmpro_after_change_membership_level', function ($level_id, $user_id) {
    global $wpdb;
    $configs = get_option('pmpro_api_configs', []);
    $level = pmpro_getLevel($level_id);
    foreach ($configs as $app_id => $config) {
        if (isset($config['levels'][$level_id])) {
            $existing = $wpdb->get_var("SELECT key_id FROM {$wpdb->prefix}pmpro_api_keys WHERE user_id = $user_id AND app_id = '$app_id'");
            $permissions = json_encode($config['levels'][$level_id]['permissions']);
            if (!$existing) {
                $key_id = wp_generate_uuid4();
                $response = wp_remote_post("{$config['url']}/keys/create", [
                    'body' => json_encode(['key_id' => $key_id, 'tier' => $level->name, 'permissions' => $permissions]),
                    'headers' => ['Content-Type' => 'application/json']
                ]);
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $wpdb->insert("{$wpdb->prefix}pmpro_api_keys", [
                        'user_id' => $user_id, 'app_id' => $app_id, 'key_id' => $key_id,
                        'tier' => $level->name, 'permissions' => $permissions
                    ]);
                    wp_mail(get_userdata($user_id)->user_email, "Your {$app_id} API Key", "Key: {$body['api_key']}");
                }
            } else {
                // Update existing key
                $response = wp_remote_put("{$config['url']}/keys/update", [
                    'body' => json_encode(['key_id' => $existing, 'tier' => $level->name, 'permissions' => $permissions]),
                    'headers' => ['Content-Type' => 'application/json']
                ]);
                if (!is_wp_error($response)) {
                    $wpdb->update("{$wpdb->prefix}pmpro_api_keys", [
                        'tier' => $level->name, 'permissions' => $permissions
                    ], ['key_id' => $existing]);
                }
            }
        }
    }
});

// Shortcode: Display keys
add_shortcode('pmpro_api_keys', function () {
    if (!is_user_logged_in()) return '<p>Log in to view your API keys.</p>';
    global $wpdb;
    $user_id = get_current_user_id();
    $keys = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pmpro_api_keys WHERE user_id = $user_id AND active = 1");
    $output = '<ul>';
    foreach ($keys as $key) {
        $output .= "<li>{$key->app_id} ({$key->tier}): [Fetch from FastAPI: {$key->key_id}]</li>";
    }
    $output .= '</ul>';
    return $output;
});