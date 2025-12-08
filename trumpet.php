<?php

declare(strict_types=1);
/**
 * Plugin Name: Trumpet
 * Description: An announcement management plugin.
 * Version: 1.0.16
 * Author: The Bleeding Deacons
 * License: MIT License
 */

namespace Trumpet;

require_once('common.php');
require_once('meetings.php');

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use WP_Post;
use WP_Query;

use DateTime;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Common\DependencyContainer;
use Common\CacheInterface;
use Common\WordPressCache;
use Core\Meetings\MeetingFactoryInterface;
use Core\Meetings\MeetingRepository;
use Core\Meetings\MeetingRepositoryInterface;
use Core\Meetings\TsmlMeetingFactory;

/**
 * Configuration class
 */
// 1

final class TrumpetConfig
{
    public const ANNOUNCEMENTS_CACHE_KEY = 'trumpet_announcements';
    public const CACHE_DURATION = 3600; // 1 hour
    public const OPTION_PREFIX = 'announcement_';
    public const ANNOUNCEMENT_POST_TYPE = 'announcement';
    public const TITLE_FIELD = 'general-group_article-title';
    public const HIDE_FIELD = 'general-group_hide';
    public const END_DATE_FIELD = 'general-group_end-date';
    public const BODY_FIELD = 'announcement-body';
    public const LOCATION_FIELD = 'announcement-location_map';
    public const SHOW_MAP_FIELD = 'announcement-location_show-map';
    public const RELATED_MEETING_FIELD = 'related-meeting';
    public const ANNOUNCEMENT_FIELD_GROUP = 'group_6651ffcb828c2';
    public const OPTION_GROUP = 'trumpet_settings';
    public const OPTION_NAME = 'trumpet_uninstall_settings';
    public const SETTINGS_PAGE = 'trumpet-settings';
    public const START_DISPLAY_FIELD = 'general-group_start_display';

    private function __construct() {}
}

/**
 * Plugin initialization
 */
// 2
class TrumpetPlugin
{
    private static ?DependencyContainer $container = null;

    public static function init(): void
    {
        if (self::$container === null) {
            self::$container = new DependencyContainer();
            $provider = new TrumpetServiceProvider();
            $provider->register(self::$container);

            // Register deactivation hook
            register_deactivation_hook(
                __FILE__,
                [self::class, 'deactivate']
            );
        }

        // Initialize services based on context
        if (is_admin()) {
            self::$container->get(TrumpetAdmin::class);
            new TrumpetSettings(); // Initialize settings            
        }

        self::$container->get(AnnouncementChangeTracker::class); // Initialize the tracker
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


    public static function getContainer(): DependencyContainer
    {
        if (self::$container === null) {
            throw new RuntimeException('Plugin not initialized');
        }
        return self::$container;
    }
}

/**
 * Class TrumpetServiceProvider
 * Registers all announcement-related services
 */
// 4
class TrumpetServiceProvider
{

    public function register(DependencyContainer $container): void
    {

        // Register Cache
        $container->register(CacheInterface::class, function () {
            return new WordPressCache();
        });

        // Register Meeting Factory
        $container->register(MeetingFactoryInterface::class, function () {
            return new TsmlMeetingFactory();
        });

        // Register Meeting Repository
        $container->register(MeetingRepositoryInterface::class, function (DependencyContainer $c) {
            return new MeetingRepository(
                $c->get(MeetingFactoryInterface::class),
                $c->get(CacheInterface::class)
            );
        });

        // Register AnnouncementChangeTracker
        $container->register(AnnouncementChangeTracker::class, function (DependencyContainer $c) {
            return new AnnouncementChangeTracker(
                $c->get(AnnouncementRepositoryInterface::class)
            );
        });

        // Register FrontPage Manager
        $container->register(FrontPageManager::class, function (DependencyContainer $c) {
            return new FrontPageManager(
                $c->get(MeetingRepositoryInterface::class)
            );
        });

        // Register Announcement Repository
        $container->register(AnnouncementRepositoryInterface::class, function (DependencyContainer $c) {
            return new AnnouncementRepository($c->get(CacheInterface::class));
        });

        // Register Announcement Manager
        $container->register(AnnouncementManager::class, function (DependencyContainer $c) {
            return new AnnouncementManager(
                $c->get(AnnouncementRepositoryInterface::class),
                $c->get(MeetingRepositoryInterface::class)
            );
        });

        // Register Admin
        $container->register(TrumpetAdmin::class, function (DependencyContainer $c) {
            return new TrumpetAdmin(
                $c->get(AnnouncementManager::class),
                $c->get(AnnouncementRepositoryInterface::class)
            );
        });

        // Register Deactivator
        $container->register(AnnouncementDeactivator::class, function (DependencyContainer $c) {
            return new AnnouncementDeactivator(
                $c->get(AnnouncementRepositoryInterface::class),
                $c->get(CacheInterface::class)
            );
        });
    }
}

/*
* Represents a single announcement post and contains its own field constants.
*/
// 5
class Announcement
{
    // Local constants for announcement custom fields and post type

    private int $id;
    private string $title;
    private ?array $relatedMeeting;
    private bool $showMap;
    private array $location;
    private ?DateTime $endDate;
    private string $body;
    private ?DateTime $postDate;
    private bool $hidden;
    private ?DateTime $startDisplayDate;
    private string $publicationStatus;

    /**
     * Constructor: Initialize properties from a WordPress post.
     *
     * @param WP_Post $post
     * @throws InvalidArgumentException If post is invalid
     */
    public function __construct(\WP_Post $post)
    {
        if (!$post instanceof \WP_Post) {
            throw new InvalidArgumentException('Expected WP_Post instance');
        }

        $this->id = $post->ID;
        $this->publicationStatus = $post->post_status;
        $this->initializeFields($post);
    }

    /**
     * Get the publication status of this announcement
     *
     * @return string
     */
    public function getPublicationStatus(): string
    {
        return $this->publicationStatus;
    }

    /**
     * Check if the announcement is in review status
     *
     * @return bool
     */
    public function isInReview(): bool
    {
        // Posts with 'pending' status are considered "in review"
        return $this->publicationStatus === 'pending';
    }

    /**
     * Initialize fields from the post
     *
     * @param WP_Post $post
     */
    private function initializeFields(\WP_Post $post): void
    {
        // Get title (no HTML needed)
        $this->title = $this->sanitizeField(
            get_field(TrumpetConfig::TITLE_FIELD, $this->id),
            'string'
        ) ?? '';

        // Get body content (preserve HTML)
        $this->body = $this->sanitizeField(
            get_field(TrumpetConfig::BODY_FIELD, $this->id),
            'html'
        ) ?? '';

        // Get related meetings
        $this->relatedMeeting = get_field(TrumpetConfig::RELATED_MEETING_FIELD, $this->id) ?: null;

        // Get show map flag
        $this->showMap = (bool)get_field(TrumpetConfig::SHOW_MAP_FIELD, $this->id);

        // Get and sanitize location
        $this->location = self::sanitizeLocation(
            get_field(TrumpetConfig::LOCATION_FIELD, $this->id) ?: []
        );

        // Get and parse dates
        $this->endDate = self::parseDate(
            get_field(TrumpetConfig::END_DATE_FIELD, $this->id)
        );
        $this->postDate = self::parseDate(
            get_the_time('d/m/Y', $this->id)
        );

        // Get hidden status
        $this->hidden = (bool)get_field(TrumpetConfig::HIDE_FIELD, $this->id);

        // Get and parse start display date
        $this->startDisplayDate = self::parseDate(
            get_field(TrumpetConfig::START_DISPLAY_FIELD, $this->id)
        );
    }

    /**
     * Sanitizes location data
     *
     * @param array $location
     * @return array
     */
    private static function sanitizeLocation(array $location): array
    {
        return [
            'lat' => filter_var(
                $location['lat'] ?? '',
                FILTER_SANITIZE_NUMBER_FLOAT,
                FILTER_FLAG_ALLOW_FRACTION
            ),
            'lng' => filter_var(
                $location['lng'] ?? '',
                FILTER_SANITIZE_NUMBER_FLOAT,
                FILTER_FLAG_ALLOW_FRACTION
            ),
            'address' => sanitize_text_field($location['address'] ?? ''),
        ];
    }

    /**
     * Parses date string into DateTime object
     *
     * @param string|null $dateString
     * @return DateTime|null
     */
    private static function parseDate(?string $dateString): ?DateTime
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            $date = DateTime::createFromFormat('d/m/Y', $dateString);
            return $date ?: null;
        } catch (Exception $e) {
            error_log("Date parsing error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sanitizes field value based on type while preserving HTML when needed
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private function sanitizeField($value, string $type)
    {
        switch ($type) {
            case 'string':
                return is_string($value) ? sanitize_text_field($value) : '';

            case 'html':
                return self::sanitizeHtml($value);

            case 'int':
                return filter_var($value, FILTER_SANITIZE_NUMBER_INT);

            case 'float':
                return filter_var(
                    $value,
                    FILTER_SANITIZE_NUMBER_FLOAT,
                    FILTER_FLAG_ALLOW_FRACTION
                );

            default:
                return $value;
        }
    }

    /**
     * Sanitize HTML content while preserving media elements
     *
     * @param mixed $value
     * @return string
     */
    private static function sanitizeHtml($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $allowed_html = array(
            'p' => array(
                'class' => array(),
                'style' => array()
            ),
            'span' => array(
                'class' => array(),
                'style' => array()
            ),
            'div' => array(
                'class' => array(),
                'style' => array()
            ),
            'br' => array(),
            'em' => array(),
            'strong' => array(),
            'a' => array(
                'href' => array(),
                'title' => array(),
                'target' => array(),
                'rel' => array(),
                'class' => array()
            ),
            'img' => array(
                'src' => array(),
                'alt' => array(),
                'class' => array(),
                'style' => array(),
                'width' => array(),
                'height' => array(),
                'loading' => array()
            ),
            'iframe' => array(
                'src' => array(),
                'width' => array(),
                'height' => array(),
                'frameborder' => array(),
                'allowfullscreen' => array(),
                'allow' => array(),
                'style' => array(),
                'class' => array()
            ),
            'video' => array(
                'src' => array(),
                'controls' => array(),
                'width' => array(),
                'height' => array(),
                'class' => array(),
                'style' => array(),
                'poster' => array(),
                'autoplay' => array(),
                'muted' => array(),
                'playsinline' => array()
            ),
            'source' => array(
                'src' => array(),
                'type' => array()
            )
        );

        return wp_kses($value, $allowed_html);
    }

    /**
     * Check if the content contains HTML that should be preserved
     *
     * @param string|mixed $value
     * @return bool
     */
    private static function shouldPreserveHtml($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Check for common HTML tags
        return strpos($value, '<') !== false &&
            (strpos($value, '<img') !== false ||
                strpos($value, '<p') !== false ||
                strpos($value, '<a') !== false);
    }

    /**
     * Check if the announcement is currently active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        if ($this->hidden) {
            return false;
        }

        // Check if we've reached the start display date
        if (!$this->isReadyToDisplay()) {
            return false;
        }

        if (!$this->endDate) {
            return true;
        }

        return $this->endDate >= new DateTime();
    }

    /**
     * Check if the announcement has a valid location
     *
     * @return bool
     */
    public function hasValidLocation(): bool
    {
        return $this->showMap &&
            isset($this->location['lat']) &&
            isset($this->location['lng']) &&
            $this->location['lat'] !== "0" &&
            $this->location['lng'] !== "0";
    }

    /**
     * Get the start display date
     *
     * @return DateTime|null
     */
    public function getStartDisplayDate(): ?DateTime
    {
        return $this->startDisplayDate;
    }

    /**
     * Get the formatted start display date
     *
     * @param string $format
     * @return string
     */
    public function getFormattedStartDisplayDate(string $format = 'd/m/Y'): string
    {
        return $this->startDisplayDate ? $this->startDisplayDate->format($format) : '';
    }

    /**
     * Check if the announcement should be displayed yet based on start date
     *
     * @return bool
     */
    public function isReadyToDisplay(): bool
    {
        if (!$this->startDisplayDate) {
            return true; // If no start date set, always ready to display
        }

        return $this->startDisplayDate <= new DateTime();
    }



    /**
     * Get the formatted end date
     *
     * @param string $format
     * @return string
     */
    public function getFormattedEndDate(string $format = 'd/m/Y'): string
    {
        return $this->endDate ? $this->endDate->format($format) : '';
    }

    /**
     * Get the formatted post date
     *
     * @param string $format
     * @return string
     */
    public function getFormattedPostDate(string $format = 'd/m/Y'): string
    {
        return $this->postDate ? $this->postDate->format($format) : '';
    }

    /**
     * Get status text for admin display
     *
     * @return string
     */
    public function getStatusText(): string
    {
        // Check for review status first
        if ($this->isInReview()) {
            return 'Review';
        }

        if ($this->hidden) {
            return 'Hidden';
        }

        if (!$this->isReadyToDisplay()) {
            return 'Pending';
        }

        if (!$this->endDate) {
            return 'Active';
        }

        return $this->isActive() ? 'Active' : 'Expired';
    }

    /**
     * Get the announcement ID
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the announcement title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Get the related meeting
     *
     * @return array|null
     */
    public function getRelatedMeeting(): ?array
    {
        return $this->relatedMeeting;
    }

    /**
     * Check if map should be shown
     *
     * @return bool
     */
    public function getShowMap(): bool
    {
        return $this->showMap;
    }

    /**
     * Get the location data
     *
     * @return array
     */
    public function getLocation(): array
    {
        return $this->location;
    }

    /**
     * Get the end date
     *
     * @return DateTime|null
     */
    public function getEndDate(): ?DateTime
    {
        return $this->endDate;
    }

    /**
     * Get the announcement body content
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get the post date
     *
     * @return DateTime|null
     */
    public function getPostDate(): ?DateTime
    {
        return $this->postDate;
    }

    /**
     * Check if the announcement is hidden
     *
     * @return bool
     */
    public function isHidden(): bool
    {
        return $this->hidden;
    }
}

/**
 * Interface AnnouncementRepositoryInterface
 * 
 * Defines the contract for announcement repository implementations
 */
// 6
/**
 * Interface AnnouncementRepositoryInterface
 * 
 * Defines the contract for announcement repository implementations
 */
interface AnnouncementRepositoryInterface
{
    public function findAll(): array;
    public function findById(int $id): ?Announcement;
    public function findActive(): array;
    public function save(Announcement $announcement): bool;
    public function delete(int $id): bool;
    public function update(Announcement $announcement): bool;
    public function clearCache(): void;

    /**
     * Check if an announcement has been changed
     * 
     * @param Announcement $original The original announcement
     * @param Announcement $updated The updated announcement
     * @return bool True if the announcement has changed, false otherwise
     */
    public function hasAnnouncementChanged(Announcement $original, Announcement $updated): bool;
}

/**
 * Class AnnouncementRepository
 * 
 * Handles all database operations for announcements
 */
// 7
class AnnouncementRepository implements AnnouncementRepositoryInterface
{
    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var int
     */
    private int $cacheDuration;

    /**
     * Constructor with improved event handling
     * 
     * @param CacheInterface $cache Cache implementation
     * @param int $cacheDuration Cache duration in seconds (defaults to 3600 seconds)
     */
    public function __construct(CacheInterface $cache, int $cacheDuration = 3600)
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

        // Handle deletions and trashing - we need to check post type in the handler
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
    public function handlePostStatusTransition(string $new_status, string $old_status, \WP_Post $post): void
    {
        // Only process our announcement post type
        if ($post->post_type !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        // If status actually changed, trigger appropriate actions
        if ($new_status !== $old_status) {
            // Clear cache when status changes
            $this->clearCache();

            // Get announcement objects for tracking changes
            try {
                $announcement = new Announcement($post);

                // Fire specific action for status change
                do_action('announcement_status_changed', $announcement, $old_status, $new_status);

                // When moving from pending (review) to publish, fire additional action
                if ($old_status === 'pending' && $new_status === 'publish') {
                    do_action('announcement_approved', $announcement);
                }

                // When moving to pending (review status), fire review action
                if ($new_status === 'pending') {
                    do_action('announcement_in_review', $announcement);
                }
            } catch (Exception $e) {
                error_log('Error handling status transition: ' . $e->getMessage());
            }
        }
    }

    /**
     * Clear the announcements cache
     * 
     * @param int $post_id The post ID that was updated
     * @return void
     */
    public function clearCache($post_id = null): void
    {
        // If a post ID was specified, check if it's an announcement post
        if ($post_id !== null) {
            $post_type = get_post_type($post_id);

            // Only clear cache if it's our announcement post type or null (hooks can pass null)
            if ($post_type !== null && $post_type !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                return;
            }
        }

        // Clear the cache
        $this->cache->delete(TrumpetConfig::ANNOUNCEMENTS_CACHE_KEY);

        // Log cache clearing for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Announcement cache cleared due to update on post ID: %s',
                $post_id ?? 'unknown'
            ));
        }
    }
    /**
     * Find all announcements
     * 
     * @return Announcement[]
     * @throws AnnouncementException
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
                function ($post) {
                    return new Announcement($post);
                },
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
     * Find announcement by ID
     * 
     * @param int $id
     * @return Announcement|null
     * @throws AnnouncementException
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
     * Find all active announcements
     * 
     * @return Announcement[]
     * @throws AnnouncementException
     */
    public function findActive(): array
    {
        try {
            $all = $this->findAll();
            $today = new DateTime();

            return array_filter($all, function (Announcement $announcement) use ($today) {
                return !$announcement->isHidden() &&
                    $announcement->isReadyToDisplay() &&
                    (!$announcement->getEndDate() || $announcement->getEndDate() >= $today);
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
     * Save new announcement
     * 
     * @param Announcement $announcement
     * @return bool
     * @throws AnnouncementException
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
     * Update existing announcement
     * 
     * @param Announcement $announcement
     * @return bool
     * @throws AnnouncementException
     */
    public function update(Announcement $announcement): bool
    {
        try {
            // Get the original announcement for comparison
            $originalAnnouncement = $this->findById($announcement->getId());

            if (!$originalAnnouncement) {
                throw new Exception("Original announcement not found");
            }

            // Fire 'before_update_announcement' action
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

            // Check if announcement has been changed
            if ($this->hasAnnouncementChanged($originalAnnouncement, $announcement)) {
                // Fire 'announcement_changed' action with both the original and updated announcement
                do_action('announcement_changed', $announcement, $originalAnnouncement);
            }

            // Fire 'after_update_announcement' action
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
     * Check if an announcement has been changed
     * 
     * @param Announcement $original The original announcement
     * @param Announcement $updated The updated announcement
     * @return bool True if the announcement has changed, false otherwise
     */
    public function hasAnnouncementChanged(Announcement $original, Announcement $updated): bool
    {
        // First check publication status changes
        if ($original->getPublicationStatus() !== $updated->getPublicationStatus()) {
            return true;
        }

        // Compare basic properties
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

        // Compare dates
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

        // Compare locations
        $originalLocation = $original->getLocation();
        $updatedLocation = $updated->getLocation();

        if (($originalLocation['lat'] ?? '') !== ($updatedLocation['lat'] ?? '') ||
            ($originalLocation['lng'] ?? '') !== ($updatedLocation['lng'] ?? '') ||
            ($originalLocation['address'] ?? '') !== ($updatedLocation['address'] ?? '')
        ) {
            return true;
        }

        // Compare related meetings
        $originalMeeting = $original->getRelatedMeeting();
        $updatedMeeting = $updated->getRelatedMeeting();

        if (($originalMeeting === null && $updatedMeeting !== null) ||
            ($originalMeeting !== null && $updatedMeeting === null)
        ) {
            return true;
        }

        if ($originalMeeting && $updatedMeeting) {
            // If arrays are different lengths, they're different
            if (count($originalMeeting) !== count($updatedMeeting)) {
                return true;
            }

            // Sort and serialize for comparison
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

        // No significant changes detected
        return false;
    }


    /**
     * Delete announcement
     * 
     * @param int $id
     * @return bool
     * @throws AnnouncementException
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
     * @param int $postId
     * @param Announcement $announcement
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

/**
 * Class FrontPageManager
 *
 * Registers a shortcode to display list of today's meetings.
 */
class FrontPageManager
{

    private MeetingRepositoryInterface $repository;

    /**
     * Constructor.
     * Registers the shortcode.
     */
    public function __construct(MeetingRepositoryInterface $repository)
    {
        $this->repository = $repository;
        add_shortcode('todays_meetings', [$this, 'render']);
    }

    /**
     * Render today's meetings.
     *
     * @return string HTML output.
     */
    public function render()
    {
        try {
            // Get current day (0 for Sunday, 1 for Monday, etc.)
            $current_day = intval(current_time('w'));
            $meetings    = $this->repository->findAll(['day' => $current_day]); // just get todays meetings
            $list        = '';

            foreach ($meetings as $meeting) {

                // Determine meeting location.
                $location = ($meeting->isOnline())  ? 'Online' : $meeting->getLocation();

                // Build the list item.
                $list .= '<li class="meeting">';
                $list .= '<div class="time">' . esc_html($meeting->getTime()) . ' - ';
                $list .= '<a href="' . esc_url($meeting->getUrl()) . '">' . esc_html($meeting->getName()) . '</a>';
                $list .= '</div>';
                $list .= '<div class="attendance-option">' . esc_html($location) . '</div>';
                $list .= '</li>';
            }

            // If no meetings found for today, return a friendly message.
            if (empty($list)) {
                $list = '<li>No meetings scheduled for today.</li>';
            }

            return '<h1>Today\'s Meetings</h1><ul>' . $list . '</ul>';
        } catch (Exception $e) {
            // Log the error and display a user-friendly error message.
            error_log('Error rendering todays_meetings shortcode: ' . $e->getMessage());
            return '<p>Sorry, an error occurred while retrieving today\'s meetings.</p>';
        }
    }
}

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
     * @var Announcement|null
     */
    private static ?Announcement $originalAnnouncement = null;

    /**
     * Repository for announcements
     * @var AnnouncementRepositoryInterface
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
     * @return void
     */
    public function captureOriginalAnnouncement($post_id): void
    {
        // Only run on our announcement post type
        if (get_post_type($post_id) !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        // Store the original announcement data
        try {
            self::$originalAnnouncement = $this->repository->findById($post_id);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Original announcement captured for post ID: ' . $post_id);
            }
        } catch (Exception $e) {
            error_log('Error capturing original announcement: ' . $e->getMessage());
        }
    }

    /**
     * Check for changes after ACF has saved all fields
     * 
     * @param int $post_id The post ID being saved
     * @return void
     */
    public function checkForChanges($post_id): void
    {
        // Only run on our announcement post type
        if (get_post_type($post_id) !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        // If we don't have the original, we can't compare
        if (!self::$originalAnnouncement) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('No original announcement captured for comparison, post ID: ' . $post_id);
            }
            return;
        }

        try {
            // Get updated announcement
            $updatedAnnouncement = $this->repository->findById($post_id);

            // Skip if something went wrong getting the updated announcement
            if (!$updatedAnnouncement) {
                error_log('Could not fetch updated announcement for post ID: ' . $post_id);
                return;
            }

            // Check if announcement has changed using repository method
            if ($this->repository->hasAnnouncementChanged(self::$originalAnnouncement, $updatedAnnouncement)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Changes detected in announcement ID: ' . $post_id . ', firing announcement_changed hook');
                }

                // Update the post title to match the announcement title
                $post = get_post($post_id);
                if ($post && $post->post_title !== $updatedAnnouncement->getTitle()) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_title' => $updatedAnnouncement->getTitle()
                    ]);
                }

                // Fire the announcement_changed hook
                do_action('announcement_changed', $updatedAnnouncement, self::$originalAnnouncement);
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('No changes detected in announcement ID: ' . $post_id);
                }
            }

            // Clear the original for next time
            self::$originalAnnouncement = null;
        } catch (Exception $e) {
            error_log('Error checking for announcement changes: ' . $e->getMessage());
        }
    }
}

/**
 * Class AnnouncementManager
 * 
 * Class for managing announcements
 */
// 8
class AnnouncementManager
{

    private AnnouncementRepositoryInterface $repository;
    private MeetingRepositoryInterface $meetingrepository;

    public function __construct(AnnouncementRepositoryInterface $repository, MeetingRepositoryInterface $meetings)
    {
        $this->repository = $repository;
        $this->meetingrepository = $meetings;

        // Register hooks
        add_shortcode('list_announcements', [$this, 'generateAnnouncementsList']);
        add_action('wp_head', [$this, 'addStyles']);
    }

    /**
     * Get all announcements
     *
     * @return array
     */
    public function getAnnouncements(): array
    {
        try {
            return $this->repository->findAll();
        } catch (AnnouncementException $e) {
            // Handle error appropriately
            return [];
        }
    }

    /**
     * Generate announcements list HTML
     *
     * @param array $atts
     * @param string $content
     * @return string
     */
    public function generateAnnouncementsList(array $atts = [], string $content = ''): string
    {
        try {
            $activeAnnouncements = $this->repository->findActive();

            $output = '<div class="announcements-container">';
            $output .= '<h1>Announcements</h1>';

            if (empty($activeAnnouncements)) {
                $output .= '<p>No current announcements.</p>';
            } else {
                foreach ($activeAnnouncements as $announcement) {
                    $output .= $this->renderSingleAnnouncement($announcement);
                }
            }

            $output .= $this->renderFooter();
            $output .= '</div>';

            return $output;
        } catch (AnnouncementException $e) {
            return '<div class="error-message">Unable to load announcements. Please try again later.</div>';
        }
    }

    /**
     * Sanitizes content while allowing video and iframe embeds.
     *
     * @param string $content
     * @return string
     */
    private function allowVideosInContent(string $content): string
    {
        // Get the default allowed HTML tags
        $allowed_tags = wp_kses_allowed_html('post');

        // Add iframe support for YouTube, Vimeo, etc.
        $allowed_tags['iframe'] = [
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allowfullscreen' => true,
            'title'           => true,
            'allow'           => true,
            'style'           => true,
            'class'           => true
        ];

        // Add video and source tags for self-hosted videos
        $allowed_tags['video'] = [
            'src'      => true,
            'controls' => true,
            'width'    => true,
            'height'   => true,
            'class'    => true,
            'style'    => true,
            'poster'   => true
        ];

        $allowed_tags['source'] = [
            'src'  => true,
            'type' => true
        ];

        // Process shortcodes before sanitizing
        $content = do_shortcode($content);

        // Apply the allowed tags filter
        $filtered_content = wp_kses($content, $allowed_tags);

        // Ensure oEmbed links are processed
        return $this->convertUrlsToEmbed($filtered_content);
    }

    /**
     * Helper function to convert URLs to embeds
     *
     * @param string $content
     * @return string
     */
    private function convertUrlsToEmbed(string $content): string
    {
        global $wp_embed;

        // Convert URLs to embed codes
        $content = $wp_embed->autoembed($content);

        // Run the embed shortcode
        return do_shortcode($content);
    }

    /**
     * @param array $location
     * @return string
     */
    private function renderMap(array $location): string
    {
        return sprintf(
            '<div class="address">%s</div>
            <div class="acf-map" data-zoom="16">
                <div class="marker" data-lat="%s" data-lng="%s"></div>
            </div>',
            esc_html($location['address'] ?? ''),
            esc_attr($location['lat']),
            esc_attr($location['lng'])
        );
    }

    /**
     * @param array $list
     * @return string
     */
    private function renderRelatedMeetings(array $list): string
    {
        $output = '<div class="meeting_list">';

        foreach ($list as $item) {

            $meeting = $this->meetingrepository->find($item);

            // Check meeting types and update icon if online
            if ( ! empty( $meeting ) ) {
                if ($meeting->isOnline()) {
                    $type = '<span class="online dashicons dashicons-admin-site-alt3"></span>';
                } else {
                    $type = '<span class="face2face dashicons dashicons-groups"></span>';
                }
            }

            $output .= sprintf(
                '<div class="meeting_link">
					<a class="link_light" href="%s">%s %s</a>
				</div>',
                esc_url($meeting->getUrl()),
                esc_html($meeting->getName()),
                $type
            );
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * @return string
     */
    private  function renderFooter(): string
    {
        return '<div class="announcements-footer">
            <p>To submit an announcement, please email 
               <a href="mailto:support@aa-bristol.org">support@aa-bristol.org</a>
            </p>
        </div>';
    }


    /**
     * Add necessary styles for announcements
     */
    public function addStyles(): void
    {
?>
        <style>
            /* General announcement container */
            .announcement {
                margin-bottom: 2em;
                padding: 1em;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .announcement h2 {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .announcement-edit-link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #0073aa;
                text-decoration: none;
                font-size: 0.8em;
                opacity: 0.7;
                transition: all 0.2s ease;
            }

            .announcement-edit-link:hover {
                opacity: 1;
                color: #00a0d2;
            }

            .announcement-edit-link .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            .announcement-featured-image {
                margin-bottom: 1em;
            }

            .announcement-featured-image img {
                max-width: 100%;
                height: auto;
                display: block;
            }

            .announcement-content {
                overflow: hidden;
                width: 100%;
            }

            .announcement-content img {
                max-width: 100%;
                height: auto;
            }

            .announcement-content figure {
                margin: 1em 0;
            }

            /* Make iframes and videos responsive */
            .announcement-content iframe,
            .announcement-content video {
                max-width: 100%;
                height: auto;
                aspect-ratio: 16/9;
            }

            /* Responsive video container */
            .video-container {
                position: relative;
                padding-bottom: 56.25%;
                /* 16:9 aspect ratio */
                height: 0;
                overflow: hidden;
            }

            .video-container iframe,
            .video-container video {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
            }

            .announcement-meta {
                margin-top: 1em;
                padding-top: 1em;
                border-top: 1px solid #eee;
                font-size: 0.9em;
                color: #666;
            }

            .announcement-image {
                display: block;
                /* Makes the image a block-level element */
                margin: 0 auto;
                /* Automatically adjusts left/right margins to center the block */
                max-width: 100%;
                /* Ensures the image is responsive */
                height: auto;
                /* Maintains the image's aspect ratio */

            }

            .announcement-image .img-fluid {
                display: block !important;
                /* Required for margin: 0 auto to work */
                margin: auto !important;
                /* Centers horizontally */
                max-width: 100%;
                /* Ensures responsiveness */
                height: auto;
                /* Maintains aspect ratio */
            }


            /* Responsive adjustments */
            @media (max-width: 768px) {
                .announcement {
                    padding: 0.5em;
                }

                .announcement-featured-image {
                    margin-left: -0.5em;
                    margin-right: -0.5em;
                }
            }
        </style>
    <?php
    }


    /**
     * Log errors with context
     *
     * @param string $context
     * @param Exception $e
     */
    private function logError(string $context, Exception $e): void
    {
        error_log(sprintf(
            '[Announcement Plugin] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /**
     * Render a single announcement with proper media handling
     *
     * @param Announcement $announcement
     * @return string
     */
    private function renderSingleAnnouncement(Announcement $announcement): string
    {
        $output = '<div class="announcement">';

        // Title with edit link for admins
        $title_output = sprintf('<h2>%s</h2>', esc_html($announcement->getTitle()));

        // Add edit link for admins
        if (current_user_can('edit_post', $announcement->getId())) {
            $edit_link = get_edit_post_link($announcement->getId());
            $title_output = sprintf(
                '<h2>%s <a href="%s" class="announcement-edit-link" title="Edit this announcement"><span class="dashicons dashicons-edit"></span></a></h2>',
                esc_html($announcement->getTitle()),
                esc_url($edit_link)
            );
        }

        $output .= $title_output;

        // Featured Image
        if ($thumbnail_id = get_post_thumbnail_id($announcement->getId())) {
            $image_data = wp_get_attachment_image_src($thumbnail_id, 'large');
            if ($image_data) {
                $output .= sprintf(
                    '<div class="announcement-featured-image">
                    <img src="%s" alt="%s" width="%s" height="%s" loading="lazy">
                </div>',
                    esc_url($image_data[0]),
                    esc_attr($announcement->getTitle()),
                    esc_attr($image_data[1]),
                    esc_attr($image_data[2])
                );
            }
        }

        // Content with proper media handling
        $content = $announcement->getBody();

        // Process shortcodes
        $content = do_shortcode($content);

        // Process embedded content
        global $wp_embed;
        $content = $wp_embed->autoembed($content);
        $content = $wp_embed->run_shortcode($content);

        // Process images in content
        $content = $this->processContentImages($content);

        // Apply WordPress filters
        $content = apply_filters('the_content', $content);

        $output .= sprintf(
            '<div class="announcement-content">%s</div>',
            $content
        );

        // Location/Map
        if ($announcement->getShowMap()) {
            $output .= $this->renderMap($announcement->getLocation());
        }

        // Related Meetings
        if (!empty($announcement->getRelatedMeeting())) {
            $output .= $this->renderRelatedMeetings($announcement->getRelatedMeeting());
        }

        // Meta information
        $output .= $this->renderAnnouncementMeta($announcement);

        $output .= '</div>';

        return $output;
    }

    /**
     * Process images in content to ensure proper rendering
     *
     * @param string $content
     * @return string
     */
    private function processContentImages(string $content): string
    {
        // Find all img tags
        preg_match_all('/<img[^>]+>/', $content, $matches);

        foreach ($matches[0] as $img) {
            // Get the original img tag
            $orig_img = $img;

            // Add responsive classes
            $img = str_replace('<img', '<img class="img-fluid"', $img);

            // Add loading="lazy" if not present
            if (strpos($img, 'loading=') === false) {
                $img = str_replace('<img', '<img loading="lazy"', $img);
            }

            // Wrap in figure tag if not already wrapped
            if (strpos($content, '<figure') === false) {
                $img = sprintf('<figure class="announcement-image">%s</figure>', $img);
            }

            // Replace original with enhanced version
            $content = str_replace($orig_img, $img, $content);
        }

        return $content;
    }

    /**
     * Render announcement meta information
     *
     * @param Announcement $announcement
     * @return string
     */
    private function renderAnnouncementMeta(Announcement $announcement): string
    {
        $output = '<div class="announcement-meta">';

        // Date information
        if ($announcement->getEndDate()) {
            $output .= sprintf(
                '<div class="announcement-date">Valid until: %s</div>',
                esc_html($announcement->getFormattedEndDate('F j, Y'))
            );
        }

        // Additional meta information can be added here

        $output .= '</div>';

        return $output;
    }
}

/**
 * Class TrumpetAdmin
 * 
 *  Class for handling all admin-related functionality for announcements
 */
//  9
class TrumpetAdmin
{

    /**
     * @var AnnouncementManager
     */
    private AnnouncementManager $manager;

    private AnnouncementRepositoryInterface $repository;

    /**
     * Constructor
     */
    public function __construct(AnnouncementManager $manager, AnnouncementRepositoryInterface $repository)
    {
        if (!is_admin()) {
            return;
        }

        $this->manager = $manager;
        $this->repository = $repository;

        // Register admin hooks
        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        // Admin columns
        add_filter(
            'manage_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE . '_posts_columns',
            [$this, 'addCustomColumns']
        );
        add_action(
            'manage_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE . '_posts_custom_column',
            [$this, 'displayCustomColumnContent'],
            10,
            2
        );
        add_filter(
            'manage_edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE . '_sortable_columns',
            [$this, 'sortableCustomColumns']
        );
        add_action('pre_get_posts', [$this, 'customSortColumns']);

        // Admin notices
        add_action('admin_notices', [$this, 'displayAdminNotices']);

        // Admin styles
        add_action('admin_head', [$this, 'addAdminStyles']);

        // Update status meta when post is saved
        add_action('save_post_' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE, [$this, 'updateStatusOnSave'], 10, 3);

        // Also update when ACF fields are saved
        add_action('acf/save_post', [$this, 'updateStatusOnAcfSave'], 20);

        // Remove Quick Edit (status does not update on the table and the code to handle this is very long)
        add_filter('post_row_actions', [$this,'remove_quick_edit'], 10, 2);
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
    /**
     * Disable Quick Edit on admin table
     * @param mixed $actions 
     * @param mixed $post 
     * @return mixed 
     */
    function remove_quick_edit($actions, $post) {
        // Check if this is your custom post type
        if ($post->post_type === TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            // Remove the Quick Edit link
            unset($actions['inline hide-if-no-js']);
        }
        return $actions;
    }


    public function addAdminStyles(): void
    {
    ?>
        <style>
            .status-active {
                color: #46b450;
            }

            .status-expired {
                color: #dc3232;
            }

            .status-hidden {
                color: #ffb900;
            }

            .status-pending {
                color: #007cba;
            }

            .status-review {
                color: #f56e28;
                /* Orange color for review status */
            }

            .status-invalid {
                color: #dc3232;
            }

            .status-no-date {
                color: #999;
            }

            .column-announcement_status {
                width: 10%;
            }

            .column-announcement_hidden {
                width: 8%;
            }

            .column-announcement_end_date {
                width: 15%;
            }
        </style>
    <?php
    }

    /**
     * Add custom columns to the admin list
     *
     * @param array $columns
     * @return array
     */
    public static function addCustomColumns(array $columns): array
    {
        $dateColumn = $columns['date'] ?? '';
        unset($columns['date']);

        $columns['announcement_status'] = __('Status', 'text-domain');
        $columns['announcement_start_date'] = __('Start Date', 'text-domain');
        $columns['announcement_end_date'] = __('End Date', 'text-domain');
        if ($dateColumn) {
            $columns['date'] = $dateColumn;
        }

        return $columns;
    }

    /**
     * Display custom column content
     *
     * @param string $column
     * @param int $post_id
     */
    public function displayCustomColumnContent(string $column, int $post_id): void
    {
        try {
            switch ($column) {
                case 'announcement_end_date':
                    $end_date = get_field(TrumpetConfig::END_DATE_FIELD, $post_id);
                    echo esc_html($end_date);
                    break;
                case 'announcement_status':
                    self::displayAnnouncementStatus($post_id);
                    break;
                case 'announcement_start_date':
                    $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);

                    // Store normalized start date for sorting
                    $this->updateStartDateSortMeta($post_id, $start_date);

                    // Check if announcement is pending (has future start date)
                    if (!empty($start_date)) {
                        $start_date_obj = DateTime::createFromFormat('d/m/Y', $start_date);
                        $current_date = new DateTime();

                        if ($start_date_obj && $start_date_obj > $current_date) {
                            // It's pending, show the date
                            echo esc_html($start_date);
                        } else {
                            // Not pending anymore, show N/A
                            echo 'Started';
                        }
                    } else {
                        // No start date set
                        echo '<span class="status-no-date">—</span>';
                    }
                    break;
            }
        } catch (Exception $e) {
            $this->logError("Error displaying column content", $e);
            add_action('admin_notices', function () use ($e) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html("Error displaying column content: " . $e->getMessage())
                );
            });
        }
    }

    /**
     * Display announcement status in the admin table
     *
     * @param int $post_id
     * @return void
     */
    private static function displayAnnouncementStatus(int $post_id): void
    {
        $status = self::getAnnouncementStatus($post_id);

        switch ($status) {
            case 'Review':
                echo '<span class="status-review">Review</span>';
                break;
            case 'Hidden':
                echo '<span class="status-hidden">Hidden</span>';
                break;
            case 'Pending':
                echo '<span class="status-pending">Pending</span>';
                break;
            case 'No End Date':
                echo '<span class="status-no-date">No End Date</span>';
                break;
            case 'Invalid Date':
                echo '<span class="status-invalid">Invalid Date</span>';
                break;
            case 'Expired':
                echo '<span class="status-expired">Expired</span>';
                break;
            case 'Active':
                echo '<span class="status-active">Active</span>';
                break;
            default:
                echo esc_html($status);
        }
    }

    /**
     * Get the status of an announcement and update its sorting meta
     *
     * @param int $post_id
     * @return string The status text
     */
    private static function getAnnouncementStatus(int $post_id): string
    {
        $end_date = get_field(TrumpetConfig::END_DATE_FIELD, $post_id);
        $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
        $hidden = get_field(TrumpetConfig::HIDE_FIELD, $post_id);
        $post = get_post($post_id);
        $status = '';
        $sort_value = '';

        // Check for review status (pending publication)
        if ($post && $post->post_status === 'pending') {
            $status = 'Review';
            $sort_value = 'review';
        } else if ($hidden) {
            $status = 'Hidden';
            $sort_value = 'hidden';
        } else {
            $current_date = new DateTime();

            // Check if start date is in the future
            if (!empty($start_date)) {
                $start_date_obj = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($start_date_obj && $start_date_obj > $current_date) {
                    $status = 'Pending';
                    $sort_value = 'pending';
                }
            }

            // Only check other statuses if not already pending
            if (empty($status)) {
                if (!$end_date) {
                    $status = 'No End Date';
                    $sort_value = 'no_end_date';
                } else {
                    $date_obj = DateTime::createFromFormat('d/m/Y', $end_date);
                    if (!$date_obj) {
                        $status = 'Invalid Date';
                        $sort_value = 'invalid';
                    } elseif ($date_obj < $current_date) {
                        $status = 'Expired';
                        $sort_value = 'expired';
                    } else {
                        $status = 'Active';
                        $sort_value = 'active';
                    }
                }
            }
        }

        // Store the status value for sorting purposes
        update_post_meta($post_id, '_announcement_status_sort', $sort_value);

        return $status;
    }

    /**
     * Make custom columns sortable
     *
     * @param array $columns
     * @return array
     */
    public function sortableCustomColumns(array $columns): array
    {
        $columns['announcement_end_date'] = 'end_date';
        $columns['announcement_status'] = 'announcement_status';
        $columns['announcement_start_date'] = 'start_date';
        return $columns;
    }

    /**
     * Handle custom column sorting
     *
     * @param WP_Query $query
     */
    public function customSortColumns(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'end_date':
                $query->set('meta_key', TrumpetConfig::END_DATE_FIELD);
                $query->set('orderby', 'meta_value');
                break;

            case 'start_date':
                // Use our special meta key for start date sorting
                $query->set('meta_key', '_announcement_start_date_sort');
                $query->set('orderby', 'meta_value');

                // Make sure sort meta is updated for all posts
                $screen = get_current_screen();
                if ($screen && $screen->id === 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                    $this->updateAllStartDateSortMeta();
                }
                break;

            case 'announcement_status':
                // Sort by the status meta field we created
                $query->set('meta_key', '_announcement_status_sort');
                $query->set('orderby', 'meta_value');

                // Force announcement posts with pending status to top when sorting by status
                $screen = get_current_screen();
                if ($screen && $screen->id === 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
                    // Update status meta values for all announcements to ensure sort values are current
                    $this->updateAllAnnouncementStatusMeta();
                }
                break;
        }
    }



    /**
     * Update status meta values for all announcements to ensure proper sorting
     */
    private function updateAllAnnouncementStatusMeta(): void
    {
        try {
            $announcements = get_posts([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ]);

            foreach ($announcements as $announcement) {
                // Call getAnnouncementStatus which will update the meta
                self::getAnnouncementStatus($announcement->ID);
            }
        } catch (Exception $e) {
            $this->logError("Error updating announcement status meta values", $e);
        }
    }

    /**
     * Display admin notices
     */
    public function displayAdminNotices(): void
    {
        global $pagenow, $post_type;

        if ($pagenow === 'edit.php' && $post_type === TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            $expired = $this->getExpiredAnnouncementsCount();
            if ($expired > 0) {
                printf(
                    '<div class="notice notice-warning"><p>%s</p></div>',
                    sprintf(
                        esc_html(_n(
                            'There is %d expired announcement.',
                            'There are %d expired announcements.',
                            $expired,
                            'text-domain'
                        )),
                        $expired
                    )
                );
            }

            $review = $this->getReviewAnnouncementsCount();
            if ($review > 0) {
                printf(
                    '<div class="notice notice-warning" style="border-left-color: #f56e28;"><p>%s</p></div>',
                    sprintf(
                        esc_html(_n(
                            'There is %d announcement awaiting review.',
                            'There are %d announcements awaiting review.',
                            $review,
                            'text-domain'
                        )),
                        $review
                    )
                );
            }

            $pending = $this->getPendingAnnouncementsCount();
            if ($pending > 0) {
                printf(
                    '<div class="notice notice-info"><p>%s</p></div>',
                    sprintf(
                        esc_html(_n(
                            'There is %d pending announcement.',
                            'There are %d pending announcements.',
                            $pending,
                            'text-domain'
                        )),
                        $pending
                    )
                );
            }
        }
    }

    /**
     * Update status meta when a post is saved
     *
     * @param int $post_id The post ID.
     * @param WP_Post $post The post object.
     * @param bool $update Whether this is an existing post being updated.
     */
    public function updateStatusOnSave(int $post_id, \WP_Post $post, bool $update): void
    {
        // Skip auto-saves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip revisions
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Skip if this is not a published post
        if ($post->post_status !== 'publish') {
            return;
        }

        // Update the status meta
        self::getAnnouncementStatus($post_id);

        // Update the start date sort meta
        $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
        $this->updateStartDateSortMeta($post_id, $start_date);
    }

    /**
     * Update status meta when ACF fields are saved
     *
     * @param int $post_id The post ID being saved
     */
    public function updateStatusOnAcfSave(int $post_id): void
    {
        // Verify this is an announcement post type
        if (get_post_type($post_id) !== TrumpetConfig::ANNOUNCEMENT_POST_TYPE) {
            return;
        }

        // Skip auto-saves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip revisions
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Update the status meta
        self::getAnnouncementStatus($post_id);

        // Update the start date sort meta
        $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $post_id);
        $this->updateStartDateSortMeta($post_id, $start_date);
    }

    /**
     * Update start date sort meta values for all announcements
     */
    private function updateAllStartDateSortMeta(): void
    {
        try {
            $announcements = get_posts([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ]);

            foreach ($announcements as $announcement) {
                $start_date = get_field(TrumpetConfig::START_DISPLAY_FIELD, $announcement->ID);
                $this->updateStartDateSortMeta($announcement->ID, $start_date);
            }
        } catch (Exception $e) {
            $this->logError("Error updating start date sort meta values", $e);
        }
    }

    /**
     * Update start date sort meta for a specific post
     * 
     * @param int $post_id The post ID
     * @param string|null $start_date The start date string in d/m/Y format
     */
    private function updateStartDateSortMeta(int $post_id, ?string $start_date): void
    {
        // Default sort value for empty dates (will sort to the bottom)
        $sort_value = '9999-99-99'; // Far future date for empty values

        if (!empty($start_date)) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $start_date);
            if ($date_obj) {
                // Format for proper sorting (YYYY-MM-DD)
                $sort_value = $date_obj->format('Y-m-d');
            }
        }

        // Update meta value for sorting
        update_post_meta($post_id, '_announcement_start_date_sort', $sort_value);
    }

    /**
     * Get count of expired announcements
     *
     * @return int
     */
    private function getExpiredAnnouncementsCount(): int
    {
        try {
            $announcements = $this->manager->getAnnouncements();
            $today = new DateTime();
            return count(array_filter($announcements, function ($announcement) use ($today) {
                return $announcement->getEndDate() && $announcement->getEndDate() < $today;
            }));
        } catch (Exception $e) {
            $this->logError("Error counting expired announcements", $e);
            return 0;
        }
    }

    /**
     * Get count of pending announcements (scheduled for future display)
     *
     * @return int Number of pending announcements
     */
    private function getPendingAnnouncementsCount(): int
    {
        try {
            $announcements = $this->manager->getAnnouncements();
            $today = new DateTime();

            return count(array_filter($announcements, function ($announcement) use ($today) {
                // Announcement is pending if:
                // 1. It has a start display date
                // 2. The start display date is in the future
                // 3. It's not hidden
                return !$announcement->isHidden() &&
                    $announcement->getStartDisplayDate() &&
                    $announcement->getStartDisplayDate() > $today;
            }));
        } catch (Exception $e) {
            $this->logError("Error counting pending announcements", $e);
            return 0;
        }
    }

    /**
     * Get count of announcements in review status
     *
     * @return int Number of announcements in review
     */
    private function getReviewAnnouncementsCount(): int
    {
        try {
            // We need to query directly for pending status posts
            $query = new \WP_Query([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_status' => 'pending',
                'posts_per_page' => -1,
                'fields' => 'ids' // We only need the count
            ]);

            return $query->found_posts;
        } catch (Exception $e) {
            $this->logError("Error counting announcements in review", $e);
            return 0;
        }
    }

    /**
     * Log errors with context
     *
     * @param string $context
     * @param Exception $e
     */
    private function logError(string $context, Exception $e): void
    {
        error_log(sprintf(
            '[Announcement Plugin] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}

/** 
 * Class TrumpetSettings
 * Handles plugin settings
 */
// 10
class TrumpetSettings
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'initializeSettings']);
    }

    /**
     * Add settings page to admin menu
     */
    public function addSettingsPage(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'Trumpet Settings',
            'Settings',
            'manage_options',
            TrumpetConfig::SETTINGS_PAGE,
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Initialize settings with default to preserve data
     */
    public function initializeSettings(): void
    {
        register_setting(
            TrumpetConfig::OPTION_GROUP,
            TrumpetConfig::OPTION_NAME,
            [
                'type' => 'array',
                'default' => [
                    'delete_data' => false, // Default to NOT delete data
                ]
            ]
        );

        add_settings_section(
            'uninstall_section',
            'Uninstall Settings',
            [$this, 'renderUninstallSection'],
            TrumpetConfig::SETTINGS_PAGE
        );

        add_settings_field(
            'delete_data',
            'Data Cleanup on Uninstall',
            [$this, 'renderDeleteDataField'],
            TrumpetConfig::SETTINGS_PAGE,
            'uninstall_section'
        );
    }



    /**
     * Render delete data field
     */
    public function renderDeleteDataField(): void
    {
        $options = get_option(TrumpetConfig::OPTION_NAME, ['delete_data' => false]);
        $delete_data = isset($options['delete_data']) ? $options['delete_data'] : false;
    ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo TrumpetConfig::OPTION_NAME; ?>[delete_data]"
                <?php checked($delete_data); ?>>
            Delete all announcement posts when uninstalling the plugin
        </label>
        <p class="description" style="color: #d63638;">
            Warning: If checked, all announcement posts will be permanently deleted when the plugin is uninstalled.
            This action cannot be undone.
        </p>
        <p class="description">
            By default, your announcement posts are preserved when uninstalling the plugin.
        </p>
    <?php
    }

    /**
     * Render the settings page
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
    ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields(TrumpetConfig::OPTION_GROUP);
                do_settings_sections(TrumpetConfig::SETTINGS_PAGE);
                submit_button('Save Settings');
                ?>
            </form>
        </div>
    <?php
    }

    /**
     * Render uninstall section description
     */
    public function renderUninstallSection(): void
    {
        echo '<p>Configure how the plugin should behave when uninstalled.</p>';
    }

    /**
     * Render preserve data field
     */
    public function renderPreserveDataField(): void
    {
        $options = get_option(TrumpetConfig::OPTION_NAME);
        $preserve_data = isset($options['preserve_data']) ? $options['preserve_data'] : true;
    ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo TrumpetConfig::OPTION_NAME; ?>[preserve_data]"
                <?php checked($preserve_data); ?>>
            Keep announcement posts and custom post type when uninstalling the plugin
        </label>
        <p class="description">
            If checked, your announcement posts and data will be preserved when the plugin is uninstalled.
            If unchecked, all announcement posts and related data will be permanently deleted.
        </p>
<?php
    }

    /**
     * Get uninstall settings
     *
     * @return array
     */
    public static function getUninstallSettings(): array
    {
        return get_option(TrumpetConfig::OPTION_NAME, ['preserve_data' => true]);
    }
}

/**
 * Class AnnouncementDeactivator
 * Handles plugin deactivation tasks
 */
// 11
class AnnouncementDeactivator
{
    private AnnouncementRepositoryInterface $repository;
    private CacheInterface $cache;

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

            // Log deactivation
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

/**
 * Custom exception for announcement operations
 */
//  12
class AnnouncementException extends Exception
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        // Log the error
        error_log(sprintf(
            '[Announcement Error] %s in %s:%d',
            $message,
            $this->getFile(),
            $this->getLine()
        ));
    }
}

// Initialize the plugin
add_action('plugins_loaded', [TrumpetPlugin::class, 'init']);


/**
 * Uninstall supported
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    return;
}

/**
 * Uninstall handler - called when plugin is deleted
 */
function announcement_plugin_uninstall(): void
{
    try {
        // Get uninstall settings
        $settings = TrumpetSettings::getUninstallSettings();
        $preserve_data = $settings['preserve_data'] ?? true;

        if (!$preserve_data) {
            // Remove all announcement posts
            $posts = get_posts([
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
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
        delete_option(TrumpetConfig::OPTION_NAME);

        // Clear any remaining caches
        wp_cache_flush();
    } catch (Exception $e) {
        error_log('Error during plugin uninstall: ' . $e->getMessage());
    }
}

register_uninstall_hook(__FILE__, 'announcement_plugin_uninstall');
