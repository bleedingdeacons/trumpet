<?php
/**
 * Plugin Name: Trumpet
 * Description: An announcement management plugin.
 * Version: 2.0.5
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
// Get version from plugin header to maintain single source of truth
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$trumpet_plugin_data = get_plugin_data(__FILE__, false, false);
define('TRUMPET_VERSION', $trumpet_plugin_data['Version']);
define('TRUMPET_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TRUMPET_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TRUMPET_PLUGIN_FILE', __FILE__);

// Load Composer autoloader if available, otherwise use custom autoloader
if (file_exists(TRUMPET_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once TRUMPET_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    // Custom PSR-4 autoloader for when Composer is not available
    spl_autoload_register(function ($class) {
        // Project-specific namespace prefix
        $prefix = 'Trumpet\\';

        // Base directory for the namespace prefix
        $base_dir = TRUMPET_PLUGIN_DIR . 'src/Trumpet/';

        // Check if the class uses our namespace prefix
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        // Get the relative class name
        $relative_class = substr($class, $len);

        // Replace namespace separators with directory separators
        // and append with .php
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        // If the file exists, require it
        if (file_exists($file)) {
            require $file;
        }
    });
}

// Initialize the plugin after Unity is loaded
add_action('unity/loaded', function($unityContainer) {
    try {
        if (!class_exists('Trumpet\Plugin')) {
            throw new \Exception('Trumpet\Plugin class not found. Check that Plugin.php exists in the src/Trumpet/ directory.');
        }

        \Trumpet\Plugin::init($unityContainer);

        do_action('trumpet/loaded', $unityContainer);

    } catch (\Exception $e) {
        error_log('Trumpet Plugin Initialization Error: ' . $e->getMessage());
        error_log('Trumpet Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    '<strong>Trumpet Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }

        return;

    } catch (\Throwable $e) {
        error_log('Trumpet Plugin Fatal Error: ' . $e->getMessage());
        error_log('Trumpet Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Trumpet Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }

        return;
    }
}, 10);

// Show admin notice if Unity plugin is not active
add_action('admin_notices', function() {
    if (!function_exists('unity') && !did_action('unity/loaded')) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Trumpet:</strong> This plugin requires the Unity plugin to be installed and activated.</p></div>';
    }
});

// Register activation/deactivation hooks
register_deactivation_hook(__FILE__, [ 'Trumpet\\Plugin', 'deactivate']);
register_uninstall_hook(__FILE__, 'trumpet_plugin_uninstall');

/**
 * Uninstall handler - called when plugin is deleted
 */
function trumpet_plugin_uninstall(): void
{
    try {
        // Get uninstall settings
        $settings = \Trumpet\Admin\TrumpetSettings::getUninstallSettings();
        $preserve_data = $settings['preserve_data'] ?? true;

        if (!$preserve_data) {
            // Remove all announcement posts
            $posts = get_posts([
                'post_type' => \Trumpet\Config\TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'numberposts' => -1,
                'post_status' => 'any'
            ]);

            foreach ($posts as $post) {
                wp_delete_post($post->ID, true);
            }
        }

        // Always clean up plugin-specific options
        delete_option('announcement_version');
        delete_option('announcement_settings');
        delete_option(\Trumpet\Config\TrumpetConfig::OPTION_NAME);

        // Clear any remaining caches
        wp_cache_flush();
    } catch (\Exception $e) {
        error_log('Error during plugin uninstall: ' . $e->getMessage());
    }
}