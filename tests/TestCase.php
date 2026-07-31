<?php

declare(strict_types=1);

namespace Tests;

use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;
use BleedingDeacons\WpMocks\WpState;
use Trumpet\Announcement\Announcement;
use Trumpet\Config\TrumpetConfig;
use WP_Post;

/**
 * Base TestCase.
 *
 * Extends the shared wp-mocks base — Brain Monkey lifecycle, Mockery
 * integration, and a WpState reset between tests — and adds a builder for the
 * WP_Post + ACF field combination an Announcement is constructed from.
 */
abstract class TestCase extends WpMocksTestCase
{
    protected const POST_ID = 101;

    protected function setUp(): void
    {
        parent::setUp();

        // parent::setUp() clears WpState, so the ACF fields start empty.
        //
        // "Now" is pinned to the start of 2026 because the announcement
        // renderer reads the post date through get_the_time()/get_post_timestamp(),
        // both of which derive from WpState::$now. The old local stubs returned
        // 01/01/2026 and the matching timestamp outright; keeping the same
        // instant keeps the rendered output — and the tests asserting on it —
        // unchanged. WpState::reset() puts $now back, so it is set per test.
        WpState::$now = '2026-01-01 00:00:00';
    }

    /**
     * Register ACF field values for the announcement under test.
     *
     * @param array<string, mixed> $fields
     */
    protected function setFields(array $fields, int $postId = self::POST_ID): void
    {
        // Replaces rather than merges, which is what the old
        // $GLOBALS['trumpet_test_fields'][$postId] = $fields did. A test that
        // builds two announcements at the same id — one populated, one empty —
        // depends on the second genuinely having no fields.
        $prefix = $postId . '|';
        foreach (array_keys(WpState::$fields) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset(WpState::$fields[$key]);
            }
        }

        foreach ($fields as $selector => $value) {
            update_field($selector, $value, $postId);
        }
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
     * An offset from today as d/m/Y â€” the format Trumpet's date fields use.
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
