<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use Trumpet\Config\TrumpetConfig;

/**
 * Class AnnouncementChangeTracker
 *
 * Tracks changes to announcements via ACF and fires the announcement_changed hook
 * when actual changes are detected.
 */
class AnnouncementChangeTracker
{
    /**
     * Store the original announcement before changes
     */
    private static ?Announcement $originalAnnouncement = null;

    /**
     * Repository for announcements
     */
    private AnnouncementRepositoryInterface $repository;

    /**
     * Constructor
     *
     * @param AnnouncementRepositoryInterface $repository Repository for accessing announcements
     */
    public function __construct(AnnouncementRepositoryInterface $repository)
    {
        $this->repository = $repository;

        // Register hooks for ACF save process
        add_action('acf/save_post', [$this, 'captureOriginalAnnouncement'], 1);
        add_action('acf/save_post', [$this, 'checkForChanges'], 20);
    }

    /**
     * Capture the original announcement before ACF makes changes
     *
     * @param int $post_id The post ID being saved
     */
    public function captureOriginalAnnouncement(int $post_id): void
    {
        if (get_post_type($post_id) !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        try {
            self::$originalAnnouncement = $this->repository->findById($post_id);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                \Trumpet\Plugin::logError('Original announcement captured for post ID: ' . $post_id);
            }
        } catch (Exception $e) {
            \Trumpet\Plugin::logError('Error capturing original announcement: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Check for changes after ACF has saved all fields
     *
     * @param int $post_id The post ID being saved
     */
    public function checkForChanges(int $post_id): void
    {
        if (get_post_type($post_id) !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        if (!self::$originalAnnouncement) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                \Trumpet\Plugin::logError('No original announcement captured for comparison, post ID: ' . $post_id);
            }
            return;
        }

        try {
            $updatedAnnouncement = $this->repository->findById($post_id);

            if (!$updatedAnnouncement) {
                \Trumpet\Plugin::logError('Could not fetch updated announcement for post ID: ' . $post_id);
                return;
            }

            if ($this->repository->hasAnnouncementChanged(self::$originalAnnouncement, $updatedAnnouncement)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    \Trumpet\Plugin::logError('Changes detected in announcement ID: ' . $post_id . ', firing announcement_changed hook');
                }

                $post = get_post($post_id);
                if ($post && $post->post_title !== $updatedAnnouncement->getTitle()) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_title' => $updatedAnnouncement->getTitle()
                    ]);
                }

                do_action('announcement_changed', $updatedAnnouncement, self::$originalAnnouncement);
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    \Trumpet\Plugin::logError('No changes detected in announcement ID: ' . $post_id);
                }
            }

            self::$originalAnnouncement = null;
        } catch (Exception $e) {
            \Trumpet\Plugin::logError('Error checking for announcement changes: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
