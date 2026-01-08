<?php
/**
 * Plugin Name: Trumpet
 * Description: An announcement management plugin.
 * Version: 2.0.2
 * Author: The Bleeding Deacons
 * License: MIT License
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

// Initialize the plugin
add_action('plugins_loaded', [ 'Trumpet\\Plugin', 'init']);

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