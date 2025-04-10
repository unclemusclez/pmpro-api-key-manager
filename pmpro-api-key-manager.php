<?php
/*
 * Plugin Name: Paid Memberships Pro - API Key Manager
 * Description: Manage API keys for FastAPI apps with Paid Memberships Pro
 * Version: 0.0.1
 * Plugin URI: https://github.com/unclemusclez/pmpro-api-key-manager
 * Author: Devin J. Dawson
 * Author URI: https://waterpistol.co
 * Text Domain: pmpro-api-key-manager
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

// Admin Settings Page
add_action('admin_menu', function () {
    add_options_page('API Key Manager', 'API Key Manager', 'manage_options', 'pm zugpro-api-keys', function () {
        // Save settings if form submitted
        if (isset($_POST['save_configs']) && check_admin_referer('pmpro_api_keys_save', 'pmpro_api_keys_nonce')) {
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
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        // Render the form
        $configs = get_option('pmpro_api_configs', []);
        $levels = function_exists('pmpro_getAllLevels') ? pmpro_getAllLevels() : [];
        ?>
        <div class="wrap">
            <h1>API Key Manager Settings</h1>
            <form method="post" action="">
                <?php wp_nonce_field('pmpro_api_keys_save', 'pmpro_api_keys_nonce'); ?>
                <h2>FastAPI Applications</h2>
                <?php foreach ($configs as $app_id => $app_data): ?>
                    <div style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px;">
                        <p><strong>App ID:</strong> <?php echo esc_html($app_id); ?></p>
                        <label>FastAPI URL: <input type="text" name="apps[<?php echo esc_attr($app_id); ?>][url]" value="<?php echo esc_attr($app_data['url']); ?>" style="width: 300px;"></label>
                        <h3>Membership Levels</h3>
                        <?php foreach ($levels as $level): ?>
                            <div style="margin-left: 20px;">
                                <p><strong><?php echo esc_html($level->name); ?> (ID: <?php echo $level->id; ?>)</strong></p>
                                <label>Hourly Limit (endpoint1): <input type="number" name="apps[<?php echo esc_attr($app_id); ?>][levels][<?php echo $level->id; ?>][limits][endpoint1][hour]" value="<?php echo esc_attr($app_data['levels'][$level->id]['permissions']['limits']['endpoint1']['hour'] ?? ''); ?>"></label><br>
                                <label>Daily Limit (endpoint1): <input type="number" name="apps[<?php echo esc_attr($app_id); ?>][levels][<?php echo $level->id; ?>][limits][endpoint1][day]" value="<?php echo esc_attr($app_data['levels'][$level->id]['permissions']['limits']['endpoint1']['day'] ?? ''); ?>"></label><br>
                                <label>Feature Flag (feature1): <input type="checkbox" name="apps[<?php echo esc_attr($app_id); ?>][levels][<?php echo $level->id; ?>][flags][feature1]" <?php checked($app_data['levels'][$level->id]['permissions']['flags']['feature1'] ?? false); ?> value="1"></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <h3>Add New App</h3>
                <label>App ID: <input type="text" name="apps[new_app][url]" placeholder="e.g., https://api.example.com" style="width: 300px;"></label>
                <p>(Save to configure levels for the new app.)</p>
                <p><input type="submit" name="save_configs" class="button-primary" value="Save Settings"></p>
            </form>
        </div>
        <?php
    });
});

// Key Generation
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
    if (empty($keys)) {
        return '<p>No API keys found. Subscribe to a membership level to generate one.</p>';
    }
    $output = '<ul>';
    foreach ($keys as $key) {
        $output .= "<li>{$key->app_id} ({$key->tier}): [Fetch from FastAPI: {$key->key_id}]</li>";
    }
    $output .= '</ul>';
    return $output;
});