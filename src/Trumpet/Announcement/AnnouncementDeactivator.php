<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

use Exception;
use Trumpet\Common\CacheInterface;
use Trumpet\Config\TrumpetConfig;

/**
 * Class AnnouncementDeactivator
 * Handles plugin deactivation tasks
 */
class AnnouncementDeactivator
{
    private AnnouncementRepositoryInterface $repository;
    private CacheInterface $cache;

    /**
     * Constructor
     *
     * @param AnnouncementRepositoryInterface $repository Announcement repository
     * @param CacheInterface $cache Cache implementation
     */
    public function __construct(
        AnnouncementRepositoryInterface $repository,
        CacheInterface $cache
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
    }

    /**
     * Perform deactivation tasks
     */
    public function deactivate(): void
    {
        try {
            $this->clearCache();
            $this->removeScheduledTasks();
            $this->cleanupCustomTables();
            $this->removeCapabilities();
            $this->cleanupOptions();

            error_log('Announcement plugin deactivated successfully');
        } catch (Exception $e) {
            error_log('Error during announcement plugin deactivation: ' . $e->getMessage());
        }
    }

    /**
     * Clear all plugin-related caches
     */
    private function clearCache(): void
    {
        $this->cache->delete(TrumpetConfig::ANNOUNCEMENTS_CACHE_KEY);
        $this->cache->flush();
    }

    /**
     * Remove any scheduled tasks
     */
    private function removeScheduledTasks(): void
    {
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
    }

    /**
     * Cleanup any custom tables if they exist
     */
    private function cleanupCustomTables(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'announcement_meta',
            $wpdb->prefix . 'announcement_logs'
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                $wpdb->query("DROP TABLE IF EXISTS $table");
            }
        }
    }

    /**
     * Check if a table exists
     *
     * @param string $table Table name
     * @return bool
     */
    private function tableExists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        ) === $table;
    }

    /**
     * Remove custom capabilities
     */
    private function removeCapabilities(): void
    {
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
    }

    /**
     * Cleanup plugin options
     */
    private function cleanupOptions(): void
    {
        $options = [
            'announcement_version',
            'announcement_settings',
            'announcement_last_cleanup'
        ];

        foreach ($options as $option) {
            delete_option($option);
        }
    }
}
