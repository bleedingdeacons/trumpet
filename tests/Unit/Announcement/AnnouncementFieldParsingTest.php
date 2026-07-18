<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Tests\TestCase;

/**
 * Tests for how Announcement parses the raw ACF field values.
 *
 * Dates arrive as d/m/Y strings and locations as a lat/lng/address array;
 * both are converted in the constructor, so a value that fails to parse is
 * indistinguishable downstream from one that was never set.
 */
class AnnouncementFieldParsingTest extends TestCase
{
    /**
     * @test
     */
    public function it_parses_a_uk_format_date(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => '15/03/2026',
        ]);

        $this->assertSame('15/03/2026', $announcement->getFormattedStartDisplayDate());
        $this->assertSame('2026-03-15', $announcement->getStartDisplayDate()?->format('Y-m-d'));
    }

    /**
     * @test
     */
    public function it_formats_a_date_with_a_caller_supplied_format(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => '15/03/2026',
        ]);

        $this->assertSame('2026-03-15', $announcement->getFormattedStartDisplayDate('Y-m-d'));
    }

    /**
     * The field format is d/m/Y. An ISO date does not match it and is dropped
     * rather than reinterpreted — which matters because 03/04 in the two
     * formats are different days, so guessing would silently move an
     * announcement by months.
     *
     * @test
     */
    public function it_rejects_an_iso_date_rather_than_guessing(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => '2026-03-15',
        ]);

        $this->assertNull($announcement->getStartDisplayDate());
        $this->assertSame('', $announcement->getFormattedStartDisplayDate());
    }

    /**
     * @test
     */
    public function it_drops_an_unparseable_date(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => 'not a date',
        ]);

        $this->assertNull($announcement->getStartDisplayDate());
    }

    /**
     * An absent start date means "display immediately", so a date the parser
     * rejects silently becomes one. Pinned because it makes a typo in the
     * admin fail open — the announcement publishes at once instead of waiting.
     *
     * @test
     */
    public function an_unparseable_start_date_makes_the_announcement_display_immediately(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => '2026-12-31',
        ]);

        $this->assertTrue(
            $announcement->isReadyToDisplay(),
            'An unparsed start date is indistinguishable from no start date.'
        );
    }

    /**
     * PHP's createFromFormat overflows impossible dates rather than failing:
     * 31 February becomes 3 March. Characterising current behaviour — this is
     * PHP's, not Trumpet's, but it is worth pinning so a future switch to
     * strict parsing is a deliberate decision with a visibly changed test.
     *
     * @test
     */
    public function an_impossible_date_overflows_into_the_next_month(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => '31/02/2026',
        ]);

        $this->assertSame('2026-03-03', $announcement->getStartDisplayDate()?->format('Y-m-d'));
    }

    /**
     * @test
     */
    public function a_location_with_coordinates_is_valid_when_the_map_is_shown(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278', 'address' => 'London'],
        ]);

        $this->assertTrue($announcement->hasValidLocation());
    }

    /**
     * @test
     */
    public function a_location_is_not_valid_when_the_map_is_switched_off(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => false,
            self::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278'],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }

    /**
     * @test
     */
    public function null_island_is_rejected(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '0', 'lng' => '0'],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }

    /**
     * The defect this method used to have: isset() on lat/lng could never
     * fail, because sanitizeLocation() always writes both keys, defaulting to
     * ''. An announcement with the map switched on and nothing entered
     * reported a valid location.
     *
     * @test
     */
    public function blank_coordinates_are_not_a_valid_location(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => [],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }

    /**
     * @test
     */
    public function an_empty_string_coordinate_is_not_a_valid_location(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '', 'lng' => ''],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }

    /**
     * Half a location is not a location.
     *
     * @test
     */
    public function a_missing_longitude_is_not_a_valid_location(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.5074'],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }

    /**
     * Null island written as a decimal. The original guard compared strings
     * against "0", so '0.0' and '0.00' walked straight through it.
     *
     * @test
     */
    public function zero_written_as_a_decimal_is_still_null_island(): void
    {
        foreach (['0.0', '0.00', '0'] as $zero) {
            $announcement = $this->makeAnnouncement([
                self::SHOW_MAP => true,
                self::LOCATION => ['lat' => $zero, 'lng' => $zero],
            ]);

            $this->assertFalse(
                $announcement->hasValidLocation(),
                "Coordinates of $zero,$zero are null island however they are written."
            );
        }
    }

    /**
     * A single zero is a real place. The Greenwich meridian is longitude 0 and
     * runs through London, so an announcement there must keep its map — only
     * 0,0 together is the failed-geocode sentinel.
     *
     * @test
     */
    public function a_location_on_the_greenwich_meridian_stays_valid(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.4779', 'lng' => '0'],
        ]);

        $this->assertTrue(
            $announcement->hasValidLocation(),
            'Longitude 0 is the meridian, not a missing coordinate.'
        );
    }

    /**
     * @test
     */
    public function a_non_numeric_coordinate_is_not_a_valid_location(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => 'not a number', 'lng' => 'nonsense'],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }

    /**
     * @test
     */
    public function it_reports_the_post_status_it_was_built_from(): void
    {
        $pending = $this->makeAnnouncement([], 'pending');
        $published = $this->makeAnnouncement([], 'publish');

        $this->assertSame('pending', $pending->getPublicationStatus());
        $this->assertTrue($pending->isInReview());
        $this->assertFalse($published->isInReview());
    }
}
