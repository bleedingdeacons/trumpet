<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Tests\TestCase;

/**
 * Tests for Announcement's visibility window.
 *
 * isActive() decides whether an announcement is shown at all, from three
 * inputs: the hide flag, the start-display date and the end date. Both dates
 * are inclusive comparisons against today, which is precisely where an
 * off-by-one turns into an announcement that appears a day late or vanishes a
 * day early — a silent failure nobody reports as a bug.
 *
 * Dates are compared as Y-m-d strings against a fresh DateTime, so "today"
 * means the machine's current date. Tests express dates as offsets from today
 * rather than fixed values, so they do not rot.
 */
class AnnouncementVisibilityTest extends TestCase
{
    /**
     * @test
     */
    public function it_is_active_when_no_dates_are_set(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->assertTrue($announcement->isActive());
    }

    /**
     * @test
     */
    public function the_hide_flag_overrides_everything(): void
    {
        $announcement = $this->makeAnnouncement([
            self::HIDE => true,
            self::START_DISPLAY => $this->daysFromToday(-10),
            self::END_DATE => $this->daysFromToday(10),
        ]);

        $this->assertFalse(
            $announcement->isActive(),
            'A hidden announcement must stay hidden even inside its display window.'
        );
    }

    /**
     * The end date is inclusive: an announcement ending today is still shown
     * today, and only drops out tomorrow.
     *
     * @test
     */
    public function an_announcement_ending_today_is_still_active(): void
    {
        $announcement = $this->makeAnnouncement([
            self::END_DATE => $this->daysFromToday(0),
        ]);

        $this->assertTrue($announcement->isActive());
    }

    /**
     * @test
     */
    public function an_announcement_that_ended_yesterday_is_not_active(): void
    {
        $announcement = $this->makeAnnouncement([
            self::END_DATE => $this->daysFromToday(-1),
        ]);

        $this->assertFalse($announcement->isActive());
    }

    /**
     * @test
     */
    public function an_announcement_with_no_end_date_never_expires(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => $this->daysFromToday(-365),
        ]);

        $this->assertTrue($announcement->isActive());
    }

    /**
     * The start-display date is inclusive too: an announcement starting today
     * is shown today, not tomorrow.
     *
     * @test
     */
    public function an_announcement_starting_today_is_ready_to_display(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => $this->daysFromToday(0),
        ]);

        $this->assertTrue($announcement->isReadyToDisplay());
        $this->assertTrue($announcement->isActive());
    }

    /**
     * @test
     */
    public function an_announcement_starting_tomorrow_is_not_yet_ready(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => $this->daysFromToday(1),
        ]);

        $this->assertFalse($announcement->isReadyToDisplay());
        $this->assertFalse(
            $announcement->isActive(),
            'Not-yet-started announcements must not be active, even with no end date.'
        );
    }

    /**
     * @test
     */
    public function an_announcement_with_no_start_date_is_ready_immediately(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->assertTrue($announcement->isReadyToDisplay());
    }

    /**
     * A window that has not opened yet takes precedence over an end date that
     * has not passed — both must hold for the announcement to be active.
     *
     * @test
     */
    public function it_is_inactive_outside_a_future_window(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => $this->daysFromToday(5),
            self::END_DATE => $this->daysFromToday(10),
        ]);

        $this->assertFalse($announcement->isActive());
    }

    /**
     * @test
     */
    public function it_is_active_inside_the_window(): void
    {
        $announcement = $this->makeAnnouncement([
            self::START_DISPLAY => $this->daysFromToday(-5),
            self::END_DATE => $this->daysFromToday(5),
        ]);

        $this->assertTrue($announcement->isActive());
    }
}
