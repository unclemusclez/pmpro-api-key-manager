<?php
/*
 * Plugin Name: Paid Memberships Pro - API Key Manager
 * Description: Dynamically manage API keys for FastAPI apps with Paid Memberships Pro
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

// Add custom fields to PMP levels
add_action('pmpro_membership_level_after_other_settings', function () {
    $level_id = intval($_GET['edit'] ?? 0);
    if ($level_id > 0) {
        $app_id = get_pmpro_membership_level_meta($level_id, 'app_id', true);
        $fastapi_url = get_pmpro_membership_level_meta($level_id, 'fastapi_url', true);
        $permissions = get_pmpro_membership_level_meta($level_id, 'permissions', true);
        ?>
        <h3>API Key Settings</h3>
        <table class="form-table">
            <tr>
                <th><label for="app_id">App ID</label></th>
                <td><input type="text" name="app_id" value="<?php echo esc_attr($app_id); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="fastapi_url">FastAPI URL</label></th>
                <td><input type="text" name="fastapi_url" value="<?php echo esc_attr($fastapi_url); ?>" class="regular-text" placeholder="e.g., https://api.example.com"></td>
            </tr>
            <tr>
                <th><label for="permissions">Permissions (JSON)</label></th>
                <td><textarea name="permissions" rows="5" class="large-text"><?php echo esc_textarea($permissions); ?></textarea>
                    <p class="description">Example: {"limits": {"feature": {"hour": 600, "day": 2400}}, "flags": {"flag": true}}</p>
                </td>
            </tr>
        </table>
        <?php
    }
});

add_action('pmpro_save_membership_level', function ($level_id) {
    if (isset($_POST['app_id'])) {
        update_pmpro_membership_level_meta($level_id, 'app_id', sanitize_text_field($_POST['app_id']));
    }
    if (isset($_POST['fastapi_url'])) {
        update_pmpro_membership_level_meta($level_id, 'fastapi_url', sanitize_text_field($_POST['fastapi_url']));
    }
    if (isset($_POST['permissions'])) {
        $permissions = json_decode(stripslashes($_POST['permissions']), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            update_pmpro_membership_level_meta($level_id, 'permissions', $_POST['permissions']);
        } else {
            // Log or display error if JSON is invalid
            error_log("Invalid permissions JSON for level $level_id: " . $_POST['permissions']);
        }
    }
});

// Admin Dashboard
add_action('admin_menu', function () {
    add_options_page('API Key Manager', 'API Key Manager', 'manage_options', 'pmpro-api-keys', function () {
        global $wpdb;
        $levels = pmpro_getAllLevels();
        $keys = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pmpro_api_keys WHERE active = 1", ARRAY_A);
        ?>
        <div class="wrap">
            <h1>API Key Manager Dashboard</h1>
            <h2>Configured Apps and Levels</h2>
            <?php if (empty($levels)): ?>
                <p>No PMP membership levels found. Create some to manage API keys.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Level Name</th>
                            <th>App ID</th>
                            <th>FastAPI URL</th>
                            <th>Permissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($levels as $level): ?>
                            <?php
                            $app_id = get_pmpro_membership_level_meta($level->id, 'app_id', true);
                            $fastapi_url = get_pmpro_membership_level_meta($level->id, 'fastapi_url', true);
                            $permissions = get_pmpro_membership_level_meta($level->id, 'permissions', true);
                            ?>
                            <tr>
                                <td><?php echo esc_html($level->name); ?> (ID: <?php echo $level->id; ?>)</td>
                                <td><?php echo esc_html($app_id ?: 'Not set'); ?></td>
                                <td><?php echo esc_html($fastapi_url ?: 'Not set'); ?></td>
                                <td><pre><?php echo esc_html($permissions ?: 'Not set'); ?></pre></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Active API Keys</h2>
            <?php if (empty($keys)): ?>
                <p>No active API keys found. Assign users to membership levels to generate keys.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>App ID</th>
                            <th>Key ID</th>
                            <th>Tier</th>
                            <th>Permissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $key): ?>
                            <tr>
                                <td><?php echo esc_html($key['user_id']); ?></td>
                                <td><?php echo esc_html($key['app_id']); ?></td>
                                <td><?php echo esc_html($key['key_id']); ?></td>
                                <td><?php echo esc_html($key['tier']); ?></td>
                                <td><pre><?php echo esc_html($key['permissions']); ?></pre></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    });
});

// Key Generation
add_action('pmpro_after_change_membership_level', function ($level_id, $user_id) {
    global $wpdb;
    if (!$level_id || !$user_id) return; // Level 0 = cancellation, skip for now

    $level = pmpro_getLevel($level_id);
    $app_id = get_pmpro_membership_level_meta($level_id, 'app_id', true);
    $fastapi_url = get_pmpro_membership_level_meta($level_id, 'fastapi_url', true);
    $permissions = get_pmpro_membership_level_meta($level_id, 'permissions', true);

    if (!$app_id || !$fastapi_url || !$permissions) return; // Skip if incomplete

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT key_id FROM {$wpdb->prefix}pmpro_api_keys WHERE user_id = %d AND app_id = %s",
        $user_id, $app_id
    ));

    if (!$existing) {
        $key_id = wp_generate_uuid4();
        $response = wp_remote_post("$fastapi_url/keys/create", [
            'body' => json_encode(['key_id' => $key_id, 'tier' => $level->name, 'permissions' => $permissions]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $wpdb->insert("{$wpdb->prefix}pmpro_api_keys", [
                'user_id' => $user_id,
                'app_id' => $app_id,
                'key_id' => $key_id,
                'tier' => $level->name,
                'permissions' => $permissions,
                'active' => 1
            ]);
            wp_mail(get_userdata($user_id)->user_email, "Your {$app_id} API Key", "Key: {$body['api_key']}");
        }
    } else {
        $response = wp_remote_put("$fastapi_url/keys/update", [
            'body' => json_encode(['key_id' => $existing, 'tier' => $level->name, 'permissions' => $permissions]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
            $wpdb->update("{$wpdb->prefix}pmpro_api_keys", [
                'tier' => $level->name,
                'permissions' => $permissions,
                'active' => 1
            ], ['key_id' => $existing]);
        }
    }
});

// Shortcode: Display keys
add_shortcode('pmpro_api_keys', function () {
    if (!is_user_logged_in()) return '<p>Log in to view your API keys.</p>';
    global $wpdb;
    $user_id = get_current_user_id();
    $keys = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pmpro_api_keys WHERE user_id = %d AND active = 1",
        $user_id
    ));
    if (empty($keys)) {
        return '<p>No API keys found. Subscribe to a membership level to generate one.</p>';
    }
    $output = '<ul>';
    foreach ($keys as $key) {
        $output .= "<li>{$key->app_id} ({$key->tier}): [Key ID: {$key->key_id}]</li>";
    }
    $output .= '</ul>';
    return $output;
});