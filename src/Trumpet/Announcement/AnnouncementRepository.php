<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DateTime;
use Exception;
use WP_Post;
use Unity\Core\Interfaces\Cache;
use Trumpet\Config\TrumpetConfig;
use Trumpet\Exception\AnnouncementException;

/**
 * Class AnnouncementRepository
 *
 * Handles all database operations for announcements
 */
class AnnouncementRepository implements AnnouncementRepositoryInterface
{
    private Cache $cache;
    private int $cacheDuration;

    /**
     * Constructor
     *
     * @param Cache $cache Cache implementation
     * @param int $cacheDuration Cache duration in seconds
     */
    public function __construct(Cache $cache, int $cacheDuration = 3600)
    {
        $this->cache = $cache;
        $this->cacheDuration = $cacheDuration;

        // Handle ACF updates
        add_action('acf/save_post', [$this, 'clearCache']);

        add_action('transition_post_status', [$this, 'handlePostStatusTransition'], 10, 3);

        // Handle WordPress core updates specifically for our post type
        add_action('save_post_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE, [$this, 'clearCache']);
        add_action('edit_post_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE, [$this, 'clearCache']);
        add_action('publish_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE, [$this, 'clearCache']);

        // Handle deletions and trashing
        add_action('delete_post', [$this, 'clearCache']);
        add_action('trash_post', [$this, 'clearCache']);
        add_action('untrash_post', [$this, 'clearCache']);

        // Also listen for ACF field group updates related to announcements
        if (TrumpetConfig::ANNOUNCEMENT_FIELD_GROUP) {
            add_action('acf/update_field_group', function ($field_group) {
                if ($field_group['key'] === TrumpetConfig::ANNOUNCEMENT_FIELD_GROUP) {
                    $this->clearCache();
                }
            });
        }
    }

    /**
     * Handle post status transitions for announcements
     *
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function handlePostStatusTransition(string $new_status, string $old_status, WP_Post $post): void
    {
        if ($post->post_type !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        if ($new_status !== $old_status) {
            $this->clearCache();

            try {
                $announcement = new Announcement($post);

                do_action('announcement_status_changed', $announcement, $old_status, $new_status);

                if ($old_status === 'pending' && $new_status === 'publish') {
                    do_action('announcement_approved', $announcement);
                }

                if ($new_status === 'pending') {
                    do_action('announcement_in_review', $announcement);
                }
            } catch (Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('Error handling status transition: ' . $e->getMessage());
            }
        }
    }

    /**
     * Clear the announcements cache
     *
     * @param int|null $post_id The post ID that was updated
     */
    public function clearCache($post_id = null): void
    {
        if ($post_id !== null) {
            $post_type = get_post_type($post_id);

            if ($post_type !== null && $post_type !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                return;
            }
        }

        $this->cache->delete(TrumpetConfig::ANNOUNCEMENTS_CACHE_KEY);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Announcement cache cleared due to update on post ID: %s',
                $post_id ?? 'unknown'
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(): array
    {
        try {
            $cached = $this->cache->get(TrumpetConfig::ANNOUNCEMENTS_CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }

            $posts = get_posts([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'posts_per_page' => -1,
                'orderby' => 'date',
                'order' => 'DESC',
                'post_status' => 'publish'
            ]);

            $announcements = array_map(
                fn($post) => new Announcement($post),
                $posts
            );

            $this->cache->set(TrumpetConfig::ANNOUNCEMENTS_CACHE_KEY, $announcements, '', $this->cacheDuration);
            return $announcements;
        } catch (Exception $e) {
            throw new AnnouncementException(
                "Error fetching announcements: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?Announcement
    {
        try {
            $post = get_post($id);
            if (!$post || $post->post_type !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                return null;
            }

            return new Announcement($post);
        } catch (Exception $e) {
            throw new AnnouncementException(
                "Error fetching announcement: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findActive(): array
    {
        try {
            $all = $this->findAll();
            $today = new DateTime();

            return array_filter($all, function (Announcement $announcement) use ($today) {
                $endDate = $announcement->getEndDate();
                return !$announcement->isHidden() &&
                       $announcement->isReadyToDisplay() &&
                       (!$endDate || $endDate->format('Y-m-d') >= $today->format('Y-m-d'));
            });
        } catch (Exception $e) {
            throw new AnnouncementException(
                "Error fetching active announcements: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(Announcement $announcement): bool
    {
        try {
            $postData = [
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_title' => $announcement->getTitle(),
                'post_status' => 'publish'
            ];

            $postId = wp_insert_post($postData, true);
            if (is_wp_error($postId)) {
                throw new Exception($postId->get_error_message());
            }

            $this->updateCustomFields($postId, $announcement);
            $this->clearCache();

            return true;
        } catch (Exception $e) {
            throw new AnnouncementException(
                "Error saving announcement: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(Announcement $announcement): bool
    {
        try {
            $originalAnnouncement = $this->findById($announcement->getId());

            if (!$originalAnnouncement) {
                throw new Exception("Original announcement not found");
            }

            do_action('before_update_announcement', $announcement, $originalAnnouncement);

            $postData = [
                'ID' => $announcement->getId(),
                'post_title' => $announcement->getTitle(),
            ];

            $updated = wp_update_post($postData, true);
            if (is_wp_error($updated)) {
                throw new Exception($updated->get_error_message());
            }

            $this->updateCustomFields($announcement->getId(), $announcement);
            $this->clearCache();

            if ($this->hasAnnouncementChanged($originalAnnouncement, $announcement)) {
                do_action('announcement_changed', $announcement, $originalAnnouncement);
            }

            do_action('after_update_announcement', $announcement, $originalAnnouncement);

            return true;
        } catch (Exception $e) {
            throw new AnnouncementException(
                "Error updating announcement: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasAnnouncementChanged(Announcement $original, Announcement $updated): bool
    {
        if ($original->getPublicationStatus() !== $updated->getPublicationStatus()) {
            return true;
        }

        if ($original->getTitle() !== $updated->getTitle()) {
            return true;
        }

        if ($original->getBody() !== $updated->getBody()) {
            return true;
        }

        if ($original->isHidden() !== $updated->isHidden()) {
            return true;
        }

        if ($original->getShowMap() !== $updated->getShowMap()) {
            return true;
        }

        $originalEndDate = $original->getEndDate();
        $updatedEndDate = $updated->getEndDate();

        if (($originalEndDate === null && $updatedEndDate !== null) ||
            ($originalEndDate !== null && $updatedEndDate === null)
        ) {
            return true;
        }

        if (
            $originalEndDate && $updatedEndDate &&
            $originalEndDate->format('Y-m-d') !== $updatedEndDate->format('Y-m-d')
        ) {
            return true;
        }

        $originalLocation = $original->getLocation();
        $updatedLocation = $updated->getLocation();

        if (($originalLocation['lat'] ?? '') !== ($updatedLocation['lat'] ?? '') ||
            ($originalLocation['lng'] ?? '') !== ($updatedLocation['lng'] ?? '') ||
            ($originalLocation['address'] ?? '') !== ($updatedLocation['address'] ?? '')
        ) {
            return true;
        }

        $originalMeeting = $original->getRelatedMeeting();
        $updatedMeeting = $updated->getRelatedMeeting();

        if (($originalMeeting === null && $updatedMeeting !== null) ||
            ($originalMeeting !== null && $updatedMeeting === null)
        ) {
            return true;
        }

        if ($originalMeeting && $updatedMeeting) {
            if (count($originalMeeting) !== count($updatedMeeting)) {
                return true;
            }

            sort($originalMeeting);
            sort($updatedMeeting);

            if (serialize($originalMeeting) !== serialize($updatedMeeting)) {
                return true;
            }
        }

        $originalStartDate = $original->getStartDisplayDate();
        $updatedStartDate = $updated->getStartDisplayDate();

        if (($originalStartDate === null && $updatedStartDate !== null) ||
            ($originalStartDate !== null && $updatedStartDate === null)
        ) {
            return true;
        }

        if (
            $originalStartDate && $updatedStartDate &&
            $originalStartDate->format('Y-m-d') !== $updatedStartDate->format('Y-m-d')
        ) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        try {
            $result = wp_delete_post($id, true);
            if (!$result) {
                throw new Exception("Failed to delete announcement");
            }

            $this->clearCache();
            return true;
        } catch (Exception $e) {
            throw new AnnouncementException(
                "Error deleting announcement: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Update custom fields for an announcement
     *
     * @param int $postId Post ID
     * @param Announcement $announcement Announcement to update
     */
    private function updateCustomFields(int $postId, Announcement $announcement): void
    {
        update_field(TrumpetConfig::TITLE_FIELD, $announcement->getTitle(), $postId);
        update_field(TrumpetConfig::HIDE_FIELD, $announcement->isHidden(), $postId);
        update_field(TrumpetConfig::BODY_FIELD, $announcement->getBody(), $postId);

        if ($announcement->getEndDate()) {
            update_field(
                TrumpetConfig::END_DATE_FIELD,
                $announcement->getFormattedEndDate(),
                $postId
            );
        }

        if ($announcement->hasValidLocation()) {
            update_field(TrumpetConfig::LOCATION_FIELD, $announcement->getLocation(), $postId);
            update_field(TrumpetConfig::SHOW_MAP_FIELD, $announcement->getShowMap(), $postId);
        }

        if ($announcement->getRelatedMeeting()) {
            update_field(
                TrumpetConfig::RELATED_MEETING_FIELD,
                $announcement->getRelatedMeeting(),
                $postId
            );
        }

        if ($announcement->getStartDisplayDate()) {
            update_field(
                TrumpetConfig::START_DISPLAY_FIELD,
                $announcement->getFormattedStartDisplayDate(),
                $postId
            );
        }
    }
}
