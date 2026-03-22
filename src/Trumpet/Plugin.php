<?php

declare(strict_types=1);

namespace Trumpet;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use Trumpet\Admin\TrumpetAdmin;
use Trumpet\Admin\TrumpetSettings;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepository;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;
use Trumpet\FrontPage\FrontPageManager;
use Psr\Container\ContainerInterface;
use Unity\Core\Interfaces\Container;
use Unity\Core\Interfaces\Cache;
use Unity\Meetings\Interfaces\MeetingRepository;

use RuntimeException;

use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function is_admin;

/**
 * Main Trumpet Plugin Class
 */
class Plugin
{
    use \Trumpet\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'trumpet';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Initialize the plugin
     *
     * @param Container $unityContainer The Unity dependency container
     */
    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        // Register Trumpet services with Unity's container
        self::registerServices($unityContainer);

        // Register deactivation hook
        register_deactivation_hook(
                TRUMPET_PLUGIN_FILE,
                [self::class, 'deactivate']
        );

        self::$initialized = true;

        // Initialize services based on context
        if (is_admin()) {
            // Register menu early (priority 5) so it exists before ACF adds post type submenus
            add_action('admin_menu', [self::class, 'registerTrumpetMenu'], 5);

            $unityContainer->get(TrumpetAdmin::class);
            $unityContainer->get(TrumpetSettings::class);
        }

        $unityContainer->get(AnnouncementChangeTracker::class);
        $unityContainer->get(AnnouncementManager::class);
        $unityContainer->get(FrontPageManager::class);
    }

    /**
     * Handle plugin deactivation
     *
     * Clears caches, removes scheduled tasks, cleans up custom tables,
     * removes custom capabilities, and cleans up plugin options.
     */
    public static function deactivate(): void
    {
        try {
            if (self::$container === null) {
                throw new RuntimeException('Trumpet Plugin not initialized');
            }

            /** @var Cache $cache */
            $cache = self::$container->get(Cache::class);

            // Clear all plugin-related caches
            $cache->delete(TrumpetConfig::ANNOUNCEMENTS_CACHE_KEY);
            $cache->flush();

            // Remove scheduled tasks
            $hooks = [
                    'announcement_cleanup_task',
                    'announcement_notification_task'
            ];

            foreach ($hooks as $hook) {
                $timestamp = wp_next_scheduled($hook);
                if ($timestamp) {
                    wp_unschedule_event($timestamp, $hook);
                }
            }

            // Cleanup custom tables if they exist
            global $wpdb;

            $tables = [
                    $wpdb->prefix . 'announcement_meta',
                    $wpdb->prefix . 'announcement_logs'
            ];

            foreach ($tables as $table) {
                if ($wpdb->get_var(
                                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
                        ) === $table) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be parameterised with prepare(); esc_sql used as defence-in-depth
                    $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
                }
            }

            // Remove custom capabilities
            $roles = ['administrator', 'editor'];
            $capabilities = [
                    'manage_announcements',
                    'publish_announcements',
                    'edit_announcements',
                    'delete_announcements'
            ];

            foreach ($roles as $roleName) {
                $role = get_role($roleName);
                if ($role) {
                    foreach ($capabilities as $capability) {
                        $role->remove_cap($capability);
                    }
                }
            }

            // Cleanup plugin options
            $options = [
                    'announcement_version',
                    'announcement_settings',
                    'announcement_last_cleanup'
            ];

            foreach ($options as $option) {
                delete_option($option);
            }

            \Trumpet\Plugin::logError('Announcement plugin deactivated successfully');
        } catch (Exception $e) {
            \Trumpet\Plugin::logError('Error during plugin deactivation: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Register the Trumpet parent admin menu
     */
    public static function registerTrumpetMenu(): void
    {
        add_menu_page(
                'Trumpet Announcements',                      // Page title
                'Trumpet',                                    // Menu title
                'read',                                       // Capability
                'trumpet',                                    // Menu slug
                '__return_null',                              // No callback needed
                'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGhlaWdodD0iMjRweCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiIHdpZHRoPSIyNHB4IiBmaWxsPSIjMWYxZjFmIj48cGF0aCBkPSJNNzIwLTQ0MHYtODBoMTYwdjgwSDcyMFptNDggMjgwLTEyOC05NiA0OC02NCAxMjggOTYtNDggNjRabS04MC00ODAtNDgtNjQgMTI4LTk2IDQ4IDY0LTEyOCA5NlpNMjAwLTIwMHYtMTYwaC00MHEtMzMgMC01Ni41LTIzLjVUODAtNDQwdi04MHEwLTMzIDIzLjUtNTYuNVQxNjAtNjAwaDE2MGwyMDAtMTIwdjQ4MEwzMjAtMzYwaC00MHYxNjBoLTgwWm0yNDAtMTgydi0xOTZsLTk4IDU4SDE2MHY4MGgxODJsOTggNThabTEyMCAzNnYtMjY4cTI3IDI0IDQzLjUgNTguNVQ2MjAtNDgwcTAgNDEtMTYuNSA3NS41VDU2MC0zNDZaTTMwMC00ODBaIi8+PC9zdmc+',
                2                                             // Position (below Dashboard)
        );

        // Add All Announcements submenu
        add_submenu_page(
                'trumpet',                                    // Parent slug
                'All Announcements',                          // Page title
                'All Announcements',                          // Menu title
                'read',                                       // Capability
                'edit.php?post_type=announcement'             // Menu slug (links to post type)
        );

        // Add New Announcement submenu
        add_submenu_page(
                'trumpet',                                    // Parent slug
                'Add New Announcement',                       // Page title
                'Add New Announcement',                       // Menu title
                'edit_posts',                                 // Capability
                'post-new.php?post_type=announcement'         // Menu slug (links to new post)
        );

        // Add Help submenu (opens in new tab)
        add_submenu_page(
                'trumpet',                                    // Parent slug
                'Help',                                       // Page title
                'Help',                                       // Menu title
                'read',                                       // Capability
                'trumpet-help',                               // Menu slug
                [self::class, 'renderHelpPage']               // Callback function
        );

        // Make the Help link open in a new tab and redirect to the HTML file
        add_action('admin_head', function() use ($submenu) {
            $plugin_url = plugin_dir_url(TRUMPET_PLUGIN_FILE);
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Find the Help menu item and modify it
                    $('#adminmenu a[href*="trumpet-help"]').attr('href', '<?php echo esc_js($plugin_url); ?>assets/docs/trumpet.html').attr('target', '_blank');
                });
            </script>
            <?php
        });

        // Remove the auto-created "Trumpet" submenu that duplicates the parent
        add_action('admin_menu', function () {
            global $submenu;
            if (isset($submenu['trumpet'])) {
                foreach ($submenu['trumpet'] as $key => $item) {
                    if (isset($item[2]) && $item[2] === 'trumpet') {
                        unset($submenu['trumpet'][$key]);
                        break;
                    }
                }
            }
        }, 999);
    }

    /**
     * Render the menu page (placeholder)
     */
    public static function renderMenuPage(): void
    {
        // Empty callback - submenus will handle content
    }

    /**
     * Render the Help page - redirects to HTML file in new tab
     */
    public static function renderHelpPage(): void
    {
        $helpUrl = plugin_dir_url(TRUMPET_PLUGIN_FILE) . 'assets/docs/trumpet.html';

        // Redirect to the HTML file
        echo '<script type="text/javascript">';
        echo 'window.open("' . esc_js($helpUrl) . '", "_blank");';
        echo 'window.history.back();';
        echo '</script>';

        echo '<div class="wrap">';
        echo '<h1>Trumpet Help</h1>';
        echo '<p>Opening help documentation in a new tab...</p>';
        echo '<p>If the help page did not open, <a href="' . esc_url($helpUrl) . '" target="_blank">click here</a>.</p>';
        echo '</div>';
    }

    /**
     * Register all Trumpet services in Unity's container
     *
     * @param Container $container The Unity dependency container
     * @return void
     */
    private static function registerServices(Container $container): void
    {
        // Register Announcement Repository
        $container->register(AnnouncementRepositoryInterface::class, function (ContainerInterface $c) {
            return new AnnouncementRepository($c->get(Cache::class));
        });

        // Register AnnouncementChangeTracker
        $container->register(AnnouncementChangeTracker::class, function (ContainerInterface $c) {
            return new AnnouncementChangeTracker(
                    $c->get(AnnouncementRepositoryInterface::class)
            );
        });

        // Register FrontPage Manager
        $container->register(FrontPageManager::class, function (ContainerInterface $c) {
            return new FrontPageManager(
                    $c->get(MeetingRepository::class)
            );
        });

        // Register Announcement Manager
        $container->register(AnnouncementManager::class, function (ContainerInterface $c) {
            return new AnnouncementManager(
                    $c->get(AnnouncementRepositoryInterface::class),
                    $c->get(MeetingRepository::class)
            );
        });

        // Register Trumpet Admin
        $container->register(TrumpetAdmin::class, function (ContainerInterface $c) {
            return new TrumpetAdmin(
                    $c->get(AnnouncementManager::class),
                    $c->get(AnnouncementRepositoryInterface::class)
            );
        });

        // Register Trumpet Settings
        $container->register(TrumpetSettings::class, function (ContainerInterface $c) {
            return new TrumpetSettings();
        });
    }

    /**
     * Get the dependency container
     *
     * @return ContainerInterface
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Trumpet Plugin not initialized');
        }
        return self::$container;
    }
}