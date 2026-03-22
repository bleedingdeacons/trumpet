<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use RuntimeException;
use Trumpet\Common\CacheInterface;

/**
 * Class MeetingRepository
 *
 * Implementation of MeetingRepositoryInterface that retrieves meetings
 * using the TSML plugin with caching.
 */
class MeetingRepository implements MeetingRepositoryInterface
{
    private const MEETINGS_CACHE_KEY = 'trumpet_meetings';

    private int $cacheDuration;
    private MeetingFactoryInterface $factory;
    private CacheInterface $cache;
    private array $cachedArgs = [];

    /**
     * Constructor.
     *
     * @param MeetingFactoryInterface $factory Meeting factory.
     * @param CacheInterface $cache Cache implementation.
     * @param int $cacheDuration Cache duration in seconds (defaults to 60 seconds).
     */
    public function __construct(MeetingFactoryInterface $factory, CacheInterface $cache, int $cacheDuration = 60)
    {
        $this->factory = $factory;
        $this->cache = $cache;
        $this->cacheDuration = $cacheDuration;
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(array $args = []): array
    {
        $argsHash = md5(serialize($args));
        $cacheKey = self::MEETINGS_CACHE_KEY . '_' . $argsHash;

        $meetings = $this->cache->get($cacheKey);
        if ($meetings !== false) {
            return $meetings;
        }

        $meetings = $this->fetchMeetings($args);

        $this->cache->set($cacheKey, $meetings, '', $this->cacheDuration);
        $this->cachedArgs = $args;

        return $meetings;
    }

    /**
     * {@inheritdoc}
     */
    public function find(int $id): ?MeetingInterface
    {
        if ($id <= 0) {
            $this->logError("Invalid meeting ID: {$id}");
            return null;
        }

        $cacheKey = self::MEETINGS_CACHE_KEY . '_' . $id;
        $meeting = $this->cache->get($cacheKey);
        if ($meeting !== false) {
            return $meeting;
        }

        try {
            $allMeetings = $this->fetchMeetings();

            foreach ($allMeetings as $meeting) {
                if ($meeting instanceof MeetingInterface && $meeting->getId() === $id) {
                    $this->cache->set($cacheKey, $meeting, '', $this->cacheDuration);
                    return $meeting;
                }
            }
        } catch (Exception $e) {
            $this->logError("Error finding meeting with ID {$id}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function clearCache(?int $id = null): void
    {
        if ($id === null) {
            $allCacheKeys = $this->getAllCacheKeys();
            foreach ($allCacheKeys as $key) {
                $this->cache->delete($key);
            }
        } else {
            $this->cache->delete(self::MEETINGS_CACHE_KEY . '_' . $id);
        }
    }

    /**
     * Get all potential cache keys for meetings
     *
     * @return array Array of cache keys
     */
    private function getAllCacheKeys(): array
    {
        $keys = [self::MEETINGS_CACHE_KEY];

        if (!empty($this->cachedArgs)) {
            $keys[] = self::MEETINGS_CACHE_KEY . '_' . md5(serialize($this->cachedArgs));
        }

        return $keys;
    }

    /**
     * Fetch meetings from the TSML plugin and convert to Meeting objects.
     *
     * @param array $args Arguments to pass to tsml_get_meetings.
     * @return array Array of MeetingInterface objects.
     */
    private function fetchMeetings(array $args = []): array
    {
        $meetings = [];

        try {
            $posts = $this->fetchMeetingPosts($args);

            if (empty($posts) || !is_array($posts)) {
                return $meetings;
            }

            foreach ($posts as $post) {
                $meeting = $this->factory->createFromSource($post);
                if ($meeting !== null) {
                    $meetings[] = $meeting;
                }
            }
        } catch (Exception $e) {
            $this->logError("Error fetching meetings: " . $e->getMessage(), [
                'args' => $args
            ]);
        }

        return $meetings;
    }

    /**
     * Fetch raw meeting data from the TSML plugin.
     *
     * @param array $args Arguments to pass to tsml_get_meetings.
     * @return array Array of meeting data or empty array if error.
     * @throws RuntimeException If TSML plugin is not available.
     */
    private function fetchMeetingPosts(array $args = []): array
    {
        if (!function_exists('tsml_get_meetings')) {
            throw new RuntimeException('The TSML plugin must be installed and activated');
        }

        $sanitizedArgs = $this->sanitizeArgs($args);
        $posts = tsml_get_meetings($sanitizedArgs);

        if (empty($posts)) {
            $this->logError('No meetings found with the specified criteria.', [
                'args' => $sanitizedArgs
            ]);
            return [];
        }

        if (!is_array($posts)) {
            $this->logError('Unexpected result when retrieving meeting posts.', [
                'args' => $sanitizedArgs,
                'result_type' => gettype($posts)
            ]);
            return [];
        }

        return $posts;
    }

    /**
     * Sanitize arguments for querying meetings.
     *
     * @param array $args Raw arguments.
     * @return array Sanitized arguments.
     */
    private function sanitizeArgs(array $args): array
    {
        $sanitized = [];

        $allowedArgs = [
            'post__in' => fn($val) => is_array($val) ? array_map('intval', $val) : (intval($val) ? [intval($val)] : []),
            'day' => fn($val) => in_array($val, range(0, 6)) ? strval($val) : null,
            'time' => fn($val) => preg_match('/^\d{1,2}:\d{2}$/', $val) ? $val : null,
            'region' => fn($val) => sanitize_text_field($val),
            'type' => fn($val) => sanitize_text_field($val),
            'types' => fn($val) => is_array($val) ? array_map('sanitize_text_field', $val) : [sanitize_text_field($val)],
            'location_id' => fn($val) => intval($val),
            'group_id' => fn($val) => intval($val),
            's' => fn($val) => sanitize_text_field($val)
        ];

        foreach ($args as $key => $value) {
            if (isset($allowedArgs[$key])) {
                $sanitizedValue = $allowedArgs[$key]($value);
                if ($sanitizedValue !== null) {
                    $sanitized[$key] = $sanitizedValue;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Log an error message with context.
     *
     * @param string $message Error message.
     * @param array $context Additional context data.
     */
    private function logError(string $message, array $context = []): void
    {
        if (!isset($context['class'])) {
            $context['class'] = __CLASS__;
        }

        if (!isset($context['method'])) {
            $context['method'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? __METHOD__;
        }

        $contextStr = empty($context) ? '' : ' ' . json_encode($context);
        \Trumpet\Plugin::logError("[Meeting Repository Error] {$message}{$contextStr}");
    }
}
