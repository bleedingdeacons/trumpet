<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class TsmlMeetingFactory
 *
 * Implementation of MeetingFactoryInterface that creates Meeting objects
 * using the TSML plugin data format.
 */
class TsmlMeetingFactory implements MeetingFactoryInterface
{
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
     * Meeting type codes lookup table
     */
    private const TYPES_LOOKUP = [
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

    /**
     * {@inheritdoc}
     */
    public function createFromSource(mixed $source): ?MeetingInterface
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
            $id = (int)$source['id'];
            if ($id <= 0) {
                throw new InvalidArgumentException("Invalid meeting ID: {$id}");
            }

            $name = $source['name'];
            $slug = $source['slug'];
            $location = $source['location'];

            if (!function_exists('get_permalink') || !function_exists('get_post_status')) {
                throw new RuntimeException("Required WordPress functions are not available");
            }

            $url = get_permalink($id);
            $state = get_post_status($id);

            $day = (int)$this->getMeetingField($source, 'day', 0);
            $time = $this->getMeetingField($source, 'time', '');
            $end_time = $this->getMeetingField($source, 'end_time', '');

            $dayofweek = '';
            if (!empty($day) && isset(self::DAYS_OF_WEEK[$day])) {
                $dayofweek = self::DAYS_OF_WEEK[$day];
            }

            $online = $this->getMeetingField($source, 'attendance_option') === 'online';
            $types = isset($source['types']) && is_array($source['types']) ? $source['types'] : [];

            if (!empty($types)) {
                $types = $this->formatMeetingTypes($types);
            }

            // Remove ONL type as we have the online property
            $key = array_search('ONL', $types);
            if ($key !== false) {
                unset($types[$key]);
            }

            if (!function_exists('get_post_custom')) {
                throw new RuntimeException("Required WordPress function get_post_custom is not available");
            }

            $meta = get_post_custom($id);
            if (!is_array($meta)) {
                $meta = [];
            }

            $processedMeta = $this->processMeta($meta);
            $contacts = $this->extractContacts($meta);
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
        $formattedTypes = [];
        foreach ($types as $typeCode) {
            $formattedTypes[] = self::TYPES_LOOKUP[$typeCode] ?? $typeCode;
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
    private function getMeetingField(array $source, string $field, mixed $default = ''): mixed
    {
        return $source[$field] ?? $default;
    }

    /**
     * Get a meta field with a default value if not set.
     *
     * @param array $meta Meta data.
     * @param string $field Field name.
     * @param mixed $default Default value.
     * @return mixed Field value or default.
     */
    private function getMetaField(array $meta, string $field, mixed $default = ''): mixed
    {
        return $meta[$field][0] ?? $default;
    }

    /**
     * Process meta to convert any object references to IDs.
     *
     * @param array $meta Raw meta data.
     * @return array Processed meta data.
     */
    private function processMeta(array $meta): array
    {
        if (!function_exists('is_serialized') || !function_exists('maybe_unserialize')) {
            $this->logError("Required WordPress serialization functions are not available");
            return $meta;
        }

        $processedMeta = [];

        foreach ($meta as $key => $values) {
            $processedValues = [];

            foreach ($values as $value) {
                if (is_serialized($value)) {
                    try {
                        $unserialized = maybe_unserialize($value);

                        if (is_object($unserialized)) {
                            if (isset($unserialized->ID)) {
                                $processedValues[] = $unserialized->ID;
                            } elseif (isset($unserialized->id)) {
                                $processedValues[] = $unserialized->id;
                            } elseif (method_exists($unserialized, 'getId')) {
                                $processedValues[] = $unserialized->getId();
                            } elseif (method_exists($unserialized, 'get_id')) {
                                $processedValues[] = $unserialized->get_id();
                            } else {
                                $processedValues[] = get_class($unserialized);
                            }
                        } elseif (is_array($unserialized)) {
                            $processedValues[] = $this->processNestedValues($unserialized);
                        } else {
                            $processedValues[] = $unserialized;
                        }
                    } catch (Exception $e) {
                        $this->logError('Error unserializing meta data: ' . $e->getMessage(), [
                            'key' => $key
                        ]);
                        $processedValues[] = $value;
                    }
                } else {
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
    private function processNestedValues(mixed $data): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->processNestedValues($value);
            }
            return $result;
        } elseif (is_object($data)) {
            if (isset($data->ID)) {
                return $data->ID;
            } elseif (isset($data->id)) {
                return $data->id;
            } elseif (method_exists($data, 'getId')) {
                return $data->getId();
            } elseif (method_exists($data, 'get_id')) {
                return $data->get_id();
            } else {
                return get_class($data);
            }
        }

        return $data;
    }

    /**
     * Log an error message with context.
     *
     * @param string $message Error message.
     * @param array $context Additional context data.
     */
    private function logError(string $message, array $context = []): void
    {
        $context['class'] = $context['class'] ?? __CLASS__;
        $contextStr = empty($context) ? '' : ' ' . json_encode($context);
        \Trumpet\Plugin::logError("[Meeting Factory Error] {$message}{$contextStr}");
    }
}
