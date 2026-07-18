<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Trumpet\Announcement\Announcement;
use Trumpet\Config\TrumpetConfig;
use WP_Post;

/**
 * Base TestCase.
 *
 * Owns the global stub state defined in bootstrap.php so it cannot leak
 * between tests, and provides a builder for the WP_Post + ACF field
 * combination an Announcement is constructed from.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected const POST_ID = 101;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['trumpet_test_fields'] = [];
        $GLOBALS['trumpet_test_post_times'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['trumpet_test_fields'] = [];
        $GLOBALS['trumpet_test_post_times'] = [];

        parent::tearDown();
    }

    /**
     * Register ACF field values for the announcement under test.
     *
     * @param array<string, mixed> $fields
     */
    protected function setFields(array $fields, int $postId = self::POST_ID): void
    {
        $GLOBALS['trumpet_test_fields'][$postId] = $fields;
    }

    /**
     * Build an Announcement from the given ACF field values.
     *
     * Dates are supplied in the d/m/Y format Trumpet stores them in.
     *
     * @param array<string, mixed> $fields
     */
    protected function makeAnnouncement(
        array $fields = [],
        string $postStatus = 'publish',
        int $postId = self::POST_ID
    ): Announcement {
        $this->setFields($fields, $postId);

        return new Announcement(new WP_Post([
            'ID' => $postId,
            'post_status' => $postStatus,
        ]));
    }

    /**
     * An offset from today as d/m/Y — the format Trumpet's date fields use.
     */
    protected function daysFromToday(int $days): string
    {
        $date = new \DateTimeImmutable();

        return $date->modify(sprintf('%+d days', $days))->format('d/m/Y');
    }

    /**
     * Field-name shorthands, so tests read as behaviour rather than constants.
     */
    protected const HIDE = TrumpetConfig::HIDE_FIELD;
    protected const END_DATE = TrumpetConfig::END_DATE_FIELD;
    protected const START_DISPLAY = TrumpetConfig::START_DISPLAY_FIELD;
    protected const TITLE = TrumpetConfig::TITLE_FIELD;
    protected const BODY = TrumpetConfig::BODY_FIELD;
    protected const LOCATION = TrumpetConfig::LOCATION_FIELD;
    protected const SHOW_MAP = TrumpetConfig::SHOW_MAP_FIELD;
}
