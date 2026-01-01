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
class TrumpetPlugin
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
}
