<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use DateTime;
use Tests\TestCase;

/**
 * Cover the Announcement accessors and derived-state helpers not exercised by
 * the parsing/visibility/map suites: HTML body sanitisation, the formatted
 * date getters, getPostDate/isHidden, and every branch of getStatusText.
 *
 * @covers \Trumpet\Announcement\Announcement
 */
class AnnouncementAccessorsTest extends TestCase
{
    /** @test */
    public function body_html_passes_through_the_kses_allow_list(): void
    {
        // Exercises sanitizeHtml()'s allowed-tag map for a string body. The test
        // wp_kses stub is a pass-through, so this asserts the media-preserving
        // path runs, not the real stripping behaviour.
        $a = $this->makeAnnouncement([
            self::BODY => '<p class="lead">Hi</p><img src="x.jpg" alt="x">',
        ]);

        $body = $a->getBody();
        $this->assertStringContainsString('<img', $body);
        $this->assertStringContainsString('<p', $body);
    }

    /** @test */
    public function a_non_string_body_sanitises_to_an_empty_string(): void
    {
        $a = $this->makeAnnouncement([self::BODY => ['not', 'a', 'string']]);
        $this->assertSame('', $a->getBody());
    }

    /** @test */
    public function formatted_dates_render_when_set_and_are_blank_when_absent(): void
    {
        $with = $this->makeAnnouncement([
            self::END_DATE => '25/12/2027',
            self::START_DISPLAY => '01/06/2026',
        ]);
        $this->assertSame('25/12/2027', $with->getFormattedEndDate());
        $this->assertSame('01/06/2026', $with->getFormattedStartDisplayDate());

        $without = $this->makeAnnouncement([]);
        $this->assertSame('', $without->getFormattedEndDate());
        $this->assertSame('', $without->getFormattedStartDisplayDate());
    }

    /** @test */
    public function post_date_and_formatted_post_date_come_from_the_publish_time(): void
    {
        // get_the_time defaults to 01/01/2026 in the test bootstrap.
        $a = $this->makeAnnouncement([]);

        $this->assertInstanceOf(DateTime::class, $a->getPostDate());
        $this->assertSame('01/01/2026', $a->getFormattedPostDate());
    }

    /** @test */
    public function is_hidden_reflects_the_hide_field(): void
    {
        $this->assertTrue($this->makeAnnouncement([self::HIDE => true])->isHidden());
        $this->assertFalse($this->makeAnnouncement([self::HIDE => false])->isHidden());
    }

    // ─── getStatusText, branch by branch ─────────────────────────────

    /** @test */
    public function status_text_is_review_for_a_pending_post(): void
    {
        $a = $this->makeAnnouncement([self::TITLE => 'T'], 'pending');
        $this->assertSame('Review', $a->getStatusText());
    }

    /** @test */
    public function status_text_is_hidden_when_hidden(): void
    {
        $a = $this->makeAnnouncement([self::HIDE => true]);
        $this->assertSame('Hidden', $a->getStatusText());
    }

    /** @test */
    public function status_text_is_pending_before_the_start_date(): void
    {
        $a = $this->makeAnnouncement([self::START_DISPLAY => $this->daysFromToday(5)]);
        $this->assertSame('Pending', $a->getStatusText());
    }

    /** @test */
    public function status_text_is_active_with_no_end_date(): void
    {
        $a = $this->makeAnnouncement([self::TITLE => 'T']);
        $this->assertSame('Active', $a->getStatusText());
    }

    /** @test */
    public function status_text_is_active_before_the_end_date(): void
    {
        $a = $this->makeAnnouncement([self::END_DATE => $this->daysFromToday(5)]);
        $this->assertSame('Active', $a->getStatusText());
    }

    /** @test */
    public function status_text_is_expired_after_the_end_date(): void
    {
        $a = $this->makeAnnouncement([self::END_DATE => $this->daysFromToday(-5)]);
        $this->assertSame('Expired', $a->getStatusText());
    }
}
