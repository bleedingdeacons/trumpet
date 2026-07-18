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
     * CHARACTERISATION TEST — this pins a defect, not intended behaviour.
     *
     * hasValidLocation() checks isset($this->location['lat']), but
     * sanitizeLocation() always writes a 'lat' and 'lng' key, defaulting to ''
     * when the field is absent. isset() is therefore always true, and '' fails
     * only the !== "0" guard, which it passes. So an announcement with the map
     * switched on and no coordinates at all reports a valid location.
     *
     * The guard catches an explicit 0/0 but not the far more likely case of a
     * blank location field.
     *
     * Asserted as-is so the suite is green and the behaviour is visible rather
     * than assumed. Changing it alters what renders on live sites, so it is
     * left as a separate decision; when it is fixed, this test should flip to
     * assertFalse and be renamed.
     *
     * @test
     */
    public function characterises_bug_blank_coordinates_are_reported_as_valid(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => [],
        ]);

        $this->assertTrue(
            $announcement->hasValidLocation(),
            'Current (incorrect) behaviour: blank coordinates pass validation.'
        );
    }

    /**
     * The same defect from the other direction: '0.0' is a different string
     * from '0', so the null-island guard misses it.
     *
     * @test
     */
    public function characterises_bug_zero_written_as_a_decimal_evades_the_null_island_guard(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '0.0', 'lng' => '0.0'],
        ]);

        $this->assertTrue(
            $announcement->hasValidLocation(),
            "Current (incorrect) behaviour: '0.0' is not caught by the !== '0' check."
        );
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
