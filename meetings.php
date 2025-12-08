<?php
declare(strict_types=1);

namespace Core\Meetings;

require_once('common.php');

use Exception;
use InvalidArgumentException;
use RuntimeException;
use Common\CacheInterface;

/**
 * Class Contact
 *
 * Represents a single contact.
 */
class Contact
{
    // Use private properties with getters instead of public properties
    private string $name;
    private string $email;
    private string $phone;

    /**
     * Constructor.
     *
     * @param string $name The contact's name.
     * @param string $email The contact's email address.
     * @param string $phone The contact's phone number.
     */
    public function __construct(string $name = '', string $email = '', string $phone = '')
    {
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
    }

    /**
     * Get contact name.
     *
     * @return string Contact name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get contact email.
     *
     * @return string Contact email.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Get contact phone.
     *
     * @return string Contact phone.
     */
    public function getPhone(): string
    {
        return $this->phone;
    }
}

/**
 * Interface MeetingInterface
 *
 * Defines the contract for Meeting objects.
 */
interface MeetingInterface
{
    /**
     * Get meeting ID.
     *
     * @return int Meeting ID.
     */
    public function getId(): int;

    /**
     * Get meeting name.
     *
     * @return string Meeting name.
     */
    public function getName(): string;

    /**
     * Get meeting slug.
     *
     * @return string Meeting slug.
     */
    public function getSlug(): string;

    /**
     * Get meeting location.
     *
     * @return string Meeting location.
     */
    public function getLocation(): string;

    /**
     * Get meeting URL.
     *
     * @return string Meeting URL.
     */
    public function getUrl(): string;

    /**
     * Get meeting day.
     *
     * @return int Meeting day.
     */
    public function getDay(): int;

    /**
     * Get meeting day of week.
     *
     * @return string Day of week.
     */
    public function getDayOfWeek(): string;

    /**
     * Get meeting start time.
     *
     * @return string Meeting start time.
     */
    public function getTime(): string;

    /**
     * Get meeting end time.
     *
     * @return string Meeting end time.
     */
    public function getEndTime(): string;

    /**
     * Get meeting types.
     *
     * @return array Meeting types.
     */
    public function getTypes(): array;

    /**
     * Get meeting state.
     *
     * @return string Meeting state.
     */
    public function getState(): string;

    /**
     * Check if meeting is online.
     *
     * @return bool Whether meeting is online.
     */
    public function isOnline(): bool;

    /**
     * Get meeting contacts.
     *
     * @return array Array of Contact objects.
     */
    public function getContacts(): array;

    /**
     * Get all post meta data.
     *
     * @return array Post meta data.
     */
    public function getMeta(): array;

    /**
     * Get online meeting link.
     *
     * @return string Online meeting link.
     */
    public function getOnlineLink(): string;

    /**
     * Get online meeting notes.
     *
     * @return string Online meeting notes.
     */
    public function getOnlineNotes(): string;
    
}

/**
 * Class Meeting
 *
 * Implementation of MeetingInterface.
 */
class Meeting implements MeetingInterface
{
    private int $id;
    private string $name;
    private string $slug;
    private string $location;
    private string $url;
    private int $day;
    private string $dayofweek;
    private string $time;
    private string $end_time;
    private array $types;
    private string $state;
    private bool $online;
    private array $contacts;
    private array $meta;
    private string $onlineLink;
    private string $onlineNotes;

    /**
     * Constructor.
     *
     * @param int $id Meeting ID
     * @param string $name Meeting name
     * @param string $slug Meeting slug
     * @param string $location Meeting location
     * @param string $url Meeting URL
     * @param string $day Meeting day
     * @param string $dayofweek Day of the week
     * @param string $time Meeting time
     * @param string $end_time Meeting end time
     * @param array $types Meeting types
     * @param string $state Meeting state
     * @param bool $online Whether meeting is online
     * @param array $contacts Array of Contact objects
     * @param array $meta Meta data
     * @param string $onlineLink Online meeting link
     * @param string $onlineNotes Online meeting notes
     */
    public function __construct(
        int $id,
        string $name,
        string $slug,
        string $location,
        string $url,
        int $day,
        string $dayofweek,
        string $time,
        string $end_time,
        array $types,
        string $state,
        bool $online,
        array $contacts = [],
        array $meta = [],
        string $onlineLink = '',
        string $onlineNotes = ''
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->location = $location;
        $this->url = $url;
        $this->day = $day;
        $this->dayofweek = $dayofweek;
        $this->time = $time;
        $this->end_time = $end_time;
        $this->types = $types;
        $this->state = $state;
        $this->online = $online;
        $this->contacts = $contacts;
        $this->meta = $meta;
        $this->onlineLink = $onlineLink;
        $this->onlineNotes = $onlineNotes;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocation(): string
    {
        return $this->location;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * {@inheritdoc}
     */
    public function getDay(): int
    {
        return $this->day;
    }

    /**
     * {@inheritdoc}
     */
    public function getDayOfWeek(): string
    {
        return $this->dayofweek;
    }

    /**
     * {@inheritdoc}
     */
    public function getTime(): string
    {
        return $this->time;
    }

    /**
     * {@inheritdoc}
     */
    public function getEndTime(): string
    {
        return $this->end_time;
    }

    /**
     * {@inheritdoc}
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * {@inheritdoc}
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function isOnline(): bool
    {
        return $this->online;
    }

    /**
     * {@inheritdoc}
     */
    public function getContacts(): array
    {
        return $this->contacts;
    }

    /**
     * {@inheritdoc}
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * {@inheritdoc}
     */
    public function getOnlineLink(): string
    {
        return $this->onlineLink;
    }

    /**
     * {@inheritdoc}
     */
    public function getOnlineNotes(): string
    {
        return $this->onlineNotes;
    }
}

/**
 * Interface MeetingFactoryInterface
 *
 * Defines the contract for creating Meeting objects.
 */
interface MeetingFactoryInterface
{
    /**
     * Create a Meeting object from source data.
     *
     * @param array $source The meeting source data.
     * @return MeetingInterface|null Meeting object or null if creation fails.
     */
    public function createFromSource(array $source): ?MeetingInterface;
}

/**
 * Class TsmlMeetingFactory
 *
 * Implementation of MeetingFactoryInterface that creates Meeting objects
 * using the existing extraction logic.
 */
class TsmlMeetingFactory implements MeetingFactoryInterface
{
    // Define constants for hardcoded values
    private const MAX_CONTACTS = 3;
    private const DAYS_OF_WEEK = [
        '1' => 'Monday',
        '2' => 'Tuesday',
        '3' => 'Wednesday',
        '4' => 'Thursday',
        '5' => 'Friday',
        '6' => 'Saturday',
        '7' => 'Sunday'
    ];

    /**
     * Create a Meeting object from source data.
     *
     * @param array $source The meeting source data.
     * @return MeetingInterface|null Meeting object or null if creation fails.
     * @throws InvalidArgumentException If source data is invalid.
     */
    public function createFromSource(array $source): ?MeetingInterface
    {
        if (empty($source) || !is_array($source)) {
            return null;
        }

        // Validate required fields
        $requiredFields = ['id', 'name', 'slug', 'location'];
        foreach ($requiredFields as $field) {
            if (!isset($source[$field])) {
                $this->logError("Missing required field: {$field}");
                return null;
            }
        }

        try {
            $id = isset($source['id']) ? (int)$source['id'] : 0;
            if ($id <= 0) {
                throw new InvalidArgumentException("Invalid meeting ID: {$id}");
            }

            $name = $source['name'];
            $slug = $source['slug'];
            $location = $source['location'];

            // Check if WordPress functions are available
            if (!function_exists('get_permalink') || !function_exists('get_post_status')) {
                throw new RuntimeException("Required WordPress functions are not available");
            }

            $url = get_permalink($id);            

            $state = get_post_status($id);

            // Extract meeting fields with default values
            $day = (int)$this->getMeetingField($source, 'day', 0);
            $time = $this->getMeetingField($source, 'time', '');
            $end_time = $this->getMeetingField($source, 'end_time', '');

            $dayofweek = '';
            if (!empty($day) && isset(self::DAYS_OF_WEEK[$day])) {
                $dayofweek = self::DAYS_OF_WEEK[$day];
            }

            $online = $this->getMeetingField($source, 'attendance_option') === 'online';
            $types = isset($source['types']) && is_array($source['types']) ? $source['types'] : [];

            // Format meeting types
            if (!empty($types)) {
                $types = $this->formatMeetingTypes($types);
            }

            // Remove as it has no look and we have the online property 
            $key = array_search('ONL', $types);
            if ($key !== false) {
                unset($types[$key]);
            }

            // Check if WordPress function is available
            if (!function_exists('get_post_custom')) {
                throw new RuntimeException("Required WordPress function get_post_custom is not available");
            }

            // Get post meta
            $meta = get_post_custom($id);
            if (!is_array($meta)) {
                $meta = [];
            }

            // Process meta to convert object references to IDs
            $processedMeta = $this->processMeta($meta);

            // Extract contacts
            $contacts = $this->extractContacts($meta);

            // Get online meeting link and notes
            $onlineLink = $this->getMetaField($meta, 'conference_url', '');
            $onlineNotes = $this->getMetaField($meta, 'conference_url_notes', '');

            return new Meeting(
                $id,
                $name,
                $slug,
                $location,
                $url,
                $day,
                $dayofweek,
                $time,
                $end_time,
                $types,
                $state,
                $online,
                $contacts,
                $processedMeta,
                $onlineLink,
                $onlineNotes
            );
        } catch (Exception $e) {
            $this->logError('Error creating Meeting: ' . $e->getMessage(), [
                'class' => __CLASS__,
                'method' => __METHOD__,
                'source' => $source
            ]);
            return null;
        }
    }

    /**
     * Format meeting types by converting type codes to their full names.
     *
     * @param array $types Array of type codes.
     * @return array Array of formatted type names.
     */
    private function formatMeetingTypes(array $types): array
    {
        // This lookup is based on TSML plugin's variables.php
        // Move this to a configuration file or constant in a real-world application
        $typesLookup = [
            // Meeting formats
            '12x12' => '12 Steps & 12 Traditions',
            'ABSI' => 'Accessible for Blind or Seriously Impaired',
            'AL-AN' => 'Concurrent with Al-Anon',
            'AL' => 'Concurrent with Alateen',
            'ASL' => 'American Sign Language',
            'BA' => 'Babysitting Available',
            'B' => 'Big Book',
            'H' => 'Birthday',
            'BRK' => 'Breakfast',
            'C' => 'Closed',
            'CAN' => 'Candlelight',
            'CF' => 'Child-Friendly',
            'DIAL' => 'Dial-In',
            'DR' => 'Daily Reflections',
            'D' => 'Discussion',
            'GL' => 'Gay/Lesbian',
            'GR' => 'Grapevine',
            'ITA' => 'Italian',
            'JA' => 'Japanese',
            'KOR' => 'Korean',
            'L' => 'Literature',
            'LGBTQ' => 'LGBTQ',
            'LIT' => 'Literature',
            'LS' => 'Living Sober',
            'MED' => 'Meditation',
            'M' => 'Men',
            'N' => 'Native American',
            'NDG' => 'Non-Designated Smoking/Vaping',
            'O' => 'Open',
            'OUT' => 'Outdoor',
            'POC' => 'People of Color',
            'POL' => 'Polish',
            'POR' => 'Portuguese',
            'P' => 'Professionals',
            'RUS' => 'Russian',
            'SM' => 'Smoking Permitted',
            'S' => 'Spanish',
            'SP' => 'Speaker',
            'ST' => 'Step Study',
            'TR' => 'Tradition Study',
            'TC' => 'Location Temporarily Closed',
            'T' => 'Transgender',
            'X' => 'Wheelchair Access',
            'XS' => 'Excess Stairs',
            'W' => 'Women',
            'Y' => 'Young People',

            // Common additions from TSML installations
            'BE' => 'Beginner',
            'BT' => 'Basic Text',
            'CB' => 'Came to Believe',
            'CW' => 'Children Welcome',
            'CH' => 'Closed Holidays',
            'CL' => 'Candlelight',
            'ESH' => 'Experience, Strength & Hope',
            'EW' => 'Emotional Wellness',
            'FF' => 'Fragrance Free',
            'FR' => 'French',
            'G' => 'German',
            'HA' => 'Hawaiian',
            'HE' => 'Hebrew',
            'IP' => 'IP Study',
            'JT' => 'Just for Today',
            'NC' => 'No Children',
            'NS' => 'Non-Smoking',
            'QA' => 'Q&A',
            'RF' => 'Rotating Format',
            'SG' => 'Step Working Guide',
            'SH' => 'Spanish/Hispanic',
            'SK' => 'Speaker/Discussion',
            'SS' => 'Social Setting',
            'Ti' => 'Timer',
            'To' => 'Torch',
            'Tr' => 'Tradition',
            'Va' => 'Vape Friendly',
            'VM' => 'Virtual Meeting',
            'OSM' => 'Online/Speaker Meeting'
        ];

        $formattedTypes = [];
        foreach ($types as $typeCode) {
            if (isset($typesLookup[$typeCode])) {
                $formattedTypes[] = $typesLookup[$typeCode];
            } else {
                // If no match found, use the original code
                $formattedTypes[] = $typeCode;
            }
        }

        return $formattedTypes;
    }

    /**
     * Extract contact information from post meta.
     *
     * @param array $meta Post meta data.
     * @return array Array of Contact objects.
     */
    private function extractContacts(array $meta): array
    {
        $contacts = [];

        for ($count = 1; $count <= self::MAX_CONTACTS; $count++) {
            $name = $this->getMetaField($meta, "contact_{$count}_name", '');
            $email = $this->getMetaField($meta, "contact_{$count}_email", '');
            $phone = $this->getMetaField($meta, "contact_{$count}_phone", '');

            if (!empty($name) || !empty($email) || !empty($phone)) {
                $contacts[] = new Contact($name, $email, $phone);
            }
        }

        return $contacts;
    }

    /**
     * Get a meeting field with a default value if not set.
     *
     * @param array $source Source data.
     * @param string $field Field name.
     * @param mixed $default Default value.
     * @return mixed Field value or default.
     */
    private function getMeetingField(array $source, string $field, $default = '')
    {
        return isset($source[$field]) ? $source[$field] : $default;
    }

    /**
     * Get a meta field with a default value if not set.
     *
     * @param array $meta Meta data.
     * @param string $field Field name.
     * @param mixed $default Default value.
     * @return mixed Field value or default.
     */
    private function getMetaField(array $meta, string $field, $default = '')
    {
        return isset($meta[$field][0]) ? $meta[$field][0] : $default;
    }

    /**
     * Process meta to convert any object references to IDs.
     *
     * @param array $meta Raw meta data.
     * @return array Processed meta data.
     */
    private function processMeta(array $meta): array
    {
        $processedMeta = [];

        // Check if WordPress functions are available
        if (!function_exists('is_serialized') || !function_exists('maybe_unserialize')) {
            $this->logError("Required WordPress serialization functions are not available");
            return $meta; // Return unprocessed meta if functions not available
        }

        foreach ($meta as $key => $values) {
            $processedValues = [];

            foreach ($values as $value) {
                // Check if value is serialized data
                if (is_serialized($value)) {
                    try {
                        $unserialized = maybe_unserialize($value);

                        // Process objects to IDs
                        if (is_object($unserialized)) {
                            // If object has an ID property or method, use that
                            if (isset($unserialized->ID)) {
                                $processedValues[] = $unserialized->ID;
                            } elseif (isset($unserialized->id)) {
                                $processedValues[] = $unserialized->id;
                            } elseif (method_exists($unserialized, 'getId')) {
                                $processedValues[] = $unserialized->getId();
                            } elseif (method_exists($unserialized, 'get_id')) {
                                $processedValues[] = $unserialized->get_id();
                            } else {
                                // If no ID is found, store the class name
                                $processedValues[] = get_class($unserialized);
                            }
                        } elseif (is_array($unserialized)) {
                            // Process array recursively for objects
                            $processedValues[] = $this->processNestedValues($unserialized);
                        } else {
                            // Not an object or array, keep as is
                            $processedValues[] = $unserialized;
                        }
                    } catch (Exception $e) {
                        $this->logError('Error unserializing meta data: ' . $e->getMessage(), [
                            'key' => $key,
                            'value' => $value
                        ]);
                        $processedValues[] = $value; // Keep original value on error
                    }
                } else {
                    // Not serialized, keep as is
                    $processedValues[] = $value;
                }
            }

            $processedMeta[$key] = $processedValues;
        }

        return $processedMeta;
    }

    /**
     * Process nested values recursively to convert objects to IDs.
     *
     * @param mixed $data Data to process.
     * @return mixed Processed data.
     */
    private function processNestedValues($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->processNestedValues($value);
            }
            return $result;
        } elseif (is_object($data)) {
            // If object has an ID property or method, use that
            if (isset($data->ID)) {
                return $data->ID;
            } elseif (isset($data->id)) {
                return $data->id;
            } elseif (method_exists($data, 'getId')) {
                return $data->getId();
            } elseif (method_exists($data, 'get_id')) {
                return $data->get_id();
            } else {
                // If no ID is found, store the class name
                return get_class($data);
            }
        } else {
            // Not an object or array, return as is
            return $data;
        }
    }

    /**
     * Log an error message with context.
     *
     * @param string $message Error message.
     * @param array $context Additional context data.
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        // Add class and method if not provided
        if (!isset($context['class'])) {
            $context['class'] = __CLASS__;
        }

        if (!isset($context['method'])) {
            $context['method'] = __METHOD__;
        }

        // Format context data for logging
        $contextStr = empty($context) ? '' : ' ' . json_encode($context);

        // Log error with context
        error_log("[Meeting Factory Error] {$message}{$contextStr}");
    }
}

/**
 * Interface MeetingRepositoryInterface
 *
 * Defines the contract for retrieving meetings.
 */
interface MeetingRepositoryInterface
{
    /**
     * Find all meetings.
     *
     * @param array $args Optional arguments to filter meetings.
     * @return array Array of MeetingInterface objects.
     */
    public function findAll(array $args = []): array;

    /**
     * Find a meeting by ID.
     *
     * @param int $id Meeting ID.
     * @return MeetingInterface|null Meeting object or null if not found.
     */
    public function find(int $id): ?MeetingInterface;


}

/**
 * Class MeetingRepository
 *
 * Implementation of MeetingRepositoryInterface that retrieves meetings
 * using the TSML plugin with caching.
 */
class MeetingRepository implements MeetingRepositoryInterface
{
    // Cache configuration
    private int $cacheDuration;
    private const MEETINGS_CACHE_KEY = 'trumpet_meetings';

    /**
     * @var MeetingFactoryInterface
     */
    private MeetingFactoryInterface $factory;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var array Cache of arguments used for last findAll operation 
     */
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
     * Find all meetings.
     *
     * @param array $args Optional arguments to filter meetings.
     * @return array Array of MeetingInterface objects.
     */
    public function findAll(array $args = []): array
    {
        $argsHash = md5(serialize($args)); // Create hash of args for cache key
        $cacheKey = self::MEETINGS_CACHE_KEY . '_' . $argsHash;
        
        // Check if cache is valid
        $meetings = $this->cache->get($cacheKey);
        if ($meetings !== false) {
            return $meetings;
        }

        // Cache expired or empty, fetch new data
        $meetings = $this->fetchMeetings($args);

        // Update cache
        $this->cache->set($cacheKey, $meetings, '', $this->cacheDuration);
        $this->cachedArgs = $args;

        return $meetings;
    }

    /**
     * Find a meeting by ID.
     *
     * @param int $id Meeting ID.
     * @return MeetingInterface|null Meeting object or null if not found.
     */
    public function find(int $id): ?MeetingInterface
    {
        if ($id <= 0) {
            $this->logError("Invalid meeting ID: {$id}");
            return null;
        }

        // Check if this specific meeting is in cache
        $cacheKey = self::MEETINGS_CACHE_KEY . '_' . $id;
        $meeting = $this->cache->get($cacheKey);
        if ($meeting !== false) {
            return $meeting;
        }

        try {            
            // If direct fetch failed, get all meetings as a fallback
            $allMeetings = $this->fetchMeetings();

            // Find the specific meeting by ID
            foreach ($allMeetings as $meeting) {
                if ($meeting instanceof MeetingInterface && $meeting->getId() === $id) {
                    // Update cache
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
     * Clear the cache.
     *
     * @param int|null $id Optional meeting ID to clear specific cache entry.
     * @return void
     */
    public function clearCache(?int $id = null): void
    {
        if ($id === null) {
            // Clear all meetings cache - we can't use flush() as that would clear other caches too
            $allCacheKeys = $this->getAllCacheKeys();
            foreach ($allCacheKeys as $key) {
                $this->cache->delete($key);
            }
        } else {
            // Clear only the specific meeting cache
            $this->cache->delete(self::MEETINGS_CACHE_KEY . '_' . $id);
        }
    }

    /**
     * Get all potential cache keys for meetings
     * 
     * @return array Array of cache keys
     */
    private function getAllCacheKeys(): array {
        // In a real implementation, you might want to store the list of cache keys
        // This is a simplified version that clears the main cache key and any currently known args
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

        // Sanitize input arguments to prevent injection
        $sanitizedArgs = $this->sanitizeArgs($args);

        $posts = tsml_get_meetings($sanitizedArgs);

        // tsml_get_meetings doesn't return WP_Error objects
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

        // Whitelist of allowed arguments and their sanitization methods
        $allowedArgs = [
            // 'post_id' is not valid for tsml_get_meetings, removed
            'post__in' => function ($val) {
                return is_array($val) ? array_map('intval', $val) : (intval($val) ? [intval($val)] : []);
            },
            'day' => function ($val) {
                return in_array($val, range(0, 6)) ? strval($val) : null;
            },
            'time' => function ($val) {
                return preg_match('/^\d{1,2}:\d{2}$/', $val) ? $val : null;
            },
            'region' => function ($val) {
                return sanitize_text_field($val);
            },
            'type' => function ($val) {
                return sanitize_text_field($val);
            },
            'types' => function ($val) {
                return is_array($val) ? array_map('sanitize_text_field', $val) : [sanitize_text_field($val)];
            },
            'location_id' => function ($val) {
                return intval($val);
            },
            'group_id' => function ($val) {
                return intval($val);
            },
            's' => function ($val) {
                return sanitize_text_field($val);
            }
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
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        // Add class and method if not provided
        if (!isset($context['class'])) {
            $context['class'] = __CLASS__;
        }

        if (!isset($context['method'])) {
            $context['method'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? __METHOD__;
        }

        // Format context data for logging
        $contextStr = empty($context) ? '' : ' ' . json_encode($context);

        // Log error with context
        error_log("[Meeting Repository Error] {$message}{$contextStr}");
    }
}
