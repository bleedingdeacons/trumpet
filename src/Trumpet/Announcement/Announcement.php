<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DateTime;
use Exception;
use InvalidArgumentException;
use WP_Post;
use Trumpet\Config\TrumpetConfig;

/**
 * Represents a single announcement post
 */
class Announcement
{
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
     * @param WP_Post $post WordPress post object
     * @throws InvalidArgumentException If post is invalid
     */
    public function __construct(WP_Post $post)
    {
        if (!$post instanceof WP_Post) {
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
        return $this->publicationStatus === 'pending';
    }

    /**
     * Initialize fields from the post
     *
     * @param WP_Post $post WordPress post object
     */
    private function initializeFields(WP_Post $post): void
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
     * @param array $location Location data
     * @return array Sanitized location
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
     * @param string|null $dateString Date string
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
            \Trumpet\Plugin::logError('Date parsing error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Sanitizes field value based on type
     *
     * @param mixed $value Field value
     * @param string $type Field type
     * @return mixed Sanitized value
     */
    private function sanitizeField(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => is_string($value) ? sanitize_text_field($value) : '',
            'html' => self::sanitizeHtml($value),
            'int' => filter_var($value, FILTER_SANITIZE_NUMBER_INT),
            'float' => filter_var(
                $value,
                FILTER_SANITIZE_NUMBER_FLOAT,
                FILTER_FLAG_ALLOW_FRACTION
            ),
            default => $value,
        };
    }

    /**
     * Sanitize HTML content while preserving media elements
     *
     * @param mixed $value HTML content
     * @return string Sanitized HTML
     */
    private static function sanitizeHtml(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $allowed_html = [
            'p' => ['class' => [], 'style' => []],
            'span' => ['class' => [], 'style' => []],
            'div' => ['class' => [], 'style' => []],
            'br' => [],
            'em' => [],
            'strong' => [],
            'a' => [
                'href' => [],
                'title' => [],
                'target' => [],
                'rel' => [],
                'class' => []
            ],
            'img' => [
                'src' => [],
                'alt' => [],
                'class' => [],
                'style' => [],
                'width' => [],
                'height' => [],
                'loading' => []
            ],
            'iframe' => [
                'src' => [],
                'width' => [],
                'height' => [],
                'frameborder' => [],
                'allowfullscreen' => [],
                'allow' => [],
                'style' => [],
                'class' => []
            ],
            'video' => [
                'src' => [],
                'controls' => [],
                'width' => [],
                'height' => [],
                'class' => [],
                'style' => [],
                'poster' => [],
                'autoplay' => [],
                'muted' => [],
                'playsinline' => []
            ],
            'source' => ['src' => [], 'type' => []]
        ];

        return wp_kses($value, $allowed_html);
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

        if (!$this->isReadyToDisplay()) {
            return false;
        }

        if (!$this->endDate) {
            return true;
        }

        $today = new DateTime();
        return $this->endDate->format('Y-m-d') >= $today->format('Y-m-d');
    }

    /**
     * Check if the announcement has a valid location
     *
     * @return bool
     */
    public function hasValidLocation(): bool
    {
        if (!$this->showMap) {
            return false;
        }

        $lat = $this->location['lat'] ?? '';
        $lng = $this->location['lng'] ?? '';

        // isset() was the original test here and could never fail:
        // sanitizeLocation() always writes both keys, defaulting to ''. An
        // announcement with the map switched on and no coordinates entered
        // therefore reported a valid location. Check for an actual number
        // instead — '' and any non-numeric value are not one.
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }

        // 0,0 is null island: what a failed geocode leaves behind, not
        // somewhere anyone meets. Compared numerically because the original
        // string test ($lat !== "0") missed '0.0' and '0.00', which are the
        // same place written differently.
        //
        // Both must be zero, not either. The Greenwich meridian is longitude
        // 0 and runs through London, so a meeting there has a legitimate
        // lng of 0 — rejecting a single zero would hide its map.
        return (float) $lat !== 0.0 || (float) $lng !== 0.0;
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
     * @param string $format Date format
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
            return true;
        }

        $today = new DateTime();
        return $this->startDisplayDate->format('Y-m-d') <= $today->format('Y-m-d');
    }

    /**
     * Get the formatted end date
     *
     * @param string $format Date format
     * @return string
     */
    public function getFormattedEndDate(string $format = 'd/m/Y'): string
    {
        return $this->endDate ? $this->endDate->format($format) : '';
    }

    /**
     * Get the formatted post date
     *
     * @param string $format Date format
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
     * Get the full publication timestamp (Unix, second precision).
     *
     * Unlike getPostDate(), which is parsed from a d/m/Y string and is only
     * day-accurate, this returns the post's exact publish time. Used by the
     * front end to detect announcements published since the visitor last
     * scrolled the list into view.
     *
     * @return int Unix timestamp, or 0 if unavailable
     */
    public function getPublishedTimestamp(): int
    {
        $timestamp = get_post_timestamp($this->id);

        return $timestamp !== false ? (int) $timestamp : 0;
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
