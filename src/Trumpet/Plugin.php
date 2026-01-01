<?php

declare(strict_types=1);

namespace Trumpet;

use Exception;
use RuntimeException;
use Trumpet\Admin\TrumpetAdmin;
use Trumpet\Admin\TrumpetSettings;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementDeactivator;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Common\DependencyContainer;
use Trumpet\FrontPage\FrontPageManager;

/**
 * Plugin initialization class
 */
class Plugin
{
    private static ?DependencyContainer $container = null;

    /**
     * Initialize the plugin
     */
    public static function init(): void
    {
        if (self::$container === null) {
            self::$container = new DependencyContainer();
            $provider = new TrumpetServiceProvider();
            $provider->register(self::$container);

            // Register deactivation hook
            register_deactivation_hook(
                    TRUMPET_PLUGIN_FILE,
                    [self::class, 'deactivate']
            );
        }

        // Initialize services based on context
        if (is_admin()) {
            // Register menu early (priority 5) so it exists before ACF adds post type submenus
            add_action('admin_menu', [self::class, 'registerTrumpetMenu'], 5);
            self::$container->get(TrumpetAdmin::class);
            new TrumpetSettings();
        }

        self::$container->get(AnnouncementChangeTracker::class);
        self::$container->get(AnnouncementManager::class);
        self::$container->get(FrontPageManager::class);
    }

    /**
     * Handle plugin deactivation
     */
    public static function deactivate(): void
    {
        try {
            $deactivator = self::getContainer()->get(AnnouncementDeactivator::class);
            $deactivator->deactivate();
        } catch (Exception $e) {
            error_log('Error during plugin deactivation: ' . $e->getMessage());
        }
    }

    /**
     * Get the dependency container
     *
     * @return DependencyContainer
     * @throws RuntimeException If plugin not initialized
     */
    public static function getContainer(): DependencyContainer
    {
        if (self::$container === null) {
            throw new RuntimeException('Plugin not initialized');
        }
        return self::$container;
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
                30                                            // Position
        );

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
}