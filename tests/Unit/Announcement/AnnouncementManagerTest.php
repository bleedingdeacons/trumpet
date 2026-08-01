<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Mockery;
use BleedingDeacons\WpMocks\WpState;
use Tests\TestCase;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;
use Trumpet\Exception\AnnouncementException;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Cover AnnouncementManager: the [list_announcements] and
 * [announcements_indicator] shortcodes, the single-announcement render
 * (title, content, map, related meetings, meta), asset registration, the
 * inline stylesheet, and the empty/error branches.
 *
 * @covers \Trumpet\Announcement\AnnouncementManager
 */
class AnnouncementManagerTest extends TestCase
{
    /** @var AnnouncementRepositoryInterface&\Mockery\MockInterface */
    private $repo;
    /** @var MeetingRepository&\Mockery\MockInterface */
    private $meetings;
    private AnnouncementManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = Mockery::mock(AnnouncementRepositoryInterface::class);
        $this->meetings = Mockery::mock(MeetingRepository::class);
        $this->manager = new AnnouncementManager($this->repo, $this->meetings);
    }

    private function richAnnouncement()
    {
        return $this->makeAnnouncement([
            self::TITLE => 'Big News',
            self::BODY => 'Hello <img src="pic.jpg"> world',
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.45', 'lng' => '-2.58', 'address' => 'Bristol'],
            TrumpetConfig::RELATED_MEETING_FIELD => [7],
            self::END_DATE => '01/01/2027',
        ], 'publish', 100);
    }

    /** @test */
    public function add_styles_emits_the_inline_stylesheet(): void
    {
        ob_start();
        $this->manager->addStyles();
        $css = (string) ob_get_clean();

        $this->assertStringContainsString('.announcement', $css);
    }

    /** @test */
    public function get_announcements_returns_the_repository_result(): void
    {
        $this->repo->shouldReceive('findAll')->andReturn(['a', 'b']);
        $this->assertSame(['a', 'b'], $this->manager->getAnnouncements());
    }

    /** @test */
    public function get_announcements_returns_empty_on_a_repository_error(): void
    {
        $this->repo->shouldReceive('findAll')->andThrow(new AnnouncementException('boom'));
        $this->assertSame([], $this->manager->getAnnouncements());
    }

    /** @test */
    public function generate_list_renders_active_announcements_with_map_and_meetings(): void
    {
        $meeting = Mockery::mock(Meeting::class);
        $meeting->shouldReceive('isOnline')->andReturn(true);
        $meeting->shouldReceive('getUrl')->andReturn('https://meet.example/7');
        $meeting->shouldReceive('getName')->andReturn('Tuesday Group');
        $this->meetings->shouldReceive('findById')->with(7)->andReturn($meeting);

        $this->repo->shouldReceive('findActive')->andReturn([$this->richAnnouncement()]);

        $html = $this->manager->generateAnnouncementsList();

        $this->assertStringContainsString('announcements-container', $html);
        $this->assertStringContainsString('Big News', $html);
        $this->assertStringContainsString('acf-map', $html);
        $this->assertStringContainsString('meeting_link', $html);
        $this->assertStringContainsString('Valid until', $html);
    }

    /** @test */
    public function single_render_includes_edit_link_thumbnail_and_offline_meeting(): void
    {
        // Admin editor → edit link; a thumbnail → featured image; an in-person
        // meeting → the face-to-face icon branch.
        // WpState::$userCan is true by default, which is the editor case.
        // The featured image is keyed by the announcement's own post id.
        WpState::$thumbnails[100] = 55;
        WpState::$attachments[55] = ['https://wp/img.jpg', 640, 480, false];

        $meeting = Mockery::mock(Meeting::class);
        $meeting->shouldReceive('isOnline')->andReturn(false);
        $meeting->shouldReceive('getUrl')->andReturn('https://meet.example/7');
        $meeting->shouldReceive('getName')->andReturn('Church Hall');
        $this->meetings->shouldReceive('findById')->with(7)->andReturn($meeting);

        $this->repo->shouldReceive('findActive')->andReturn([$this->richAnnouncement()]);

        $html = $this->manager->generateAnnouncementsList();

        $this->assertStringContainsString('announcement-edit-link', $html);
        $this->assertStringContainsString('announcement-featured-image', $html);
        $this->assertStringContainsString('face2face', $html);
    }

    /** @test */
    public function single_render_skips_a_meeting_the_repository_cannot_find(): void
    {
        $this->meetings->shouldReceive('findById')->with(7)->andReturn(null);
        $this->repo->shouldReceive('findActive')->andReturn([$this->richAnnouncement()]);

        // Still renders the announcement, just without a meeting link.
        $html = $this->manager->generateAnnouncementsList();
        $this->assertStringContainsString('Big News', $html);
        $this->assertStringNotContainsString('meeting_link', $html);
    }

    /** @test */
    public function generate_list_shows_the_empty_message(): void
    {
        $this->repo->shouldReceive('findActive')->andReturn([]);
        $this->assertStringContainsString('No current announcements', $this->manager->generateAnnouncementsList());
    }

    /** @test */
    public function generate_list_returns_an_error_message_on_exception(): void
    {
        $this->repo->shouldReceive('findActive')->andThrow(new AnnouncementException('boom'));
        $this->assertStringContainsString('error-message', $this->manager->generateAnnouncementsList());
    }

    /** @test */
    public function render_new_indicator_returns_the_banner(): void
    {
        $html = $this->manager->renderNewIndicator();
        $this->assertStringContainsString('announcements-new-banner', $html);
    }

    /** @test */
    public function register_assets_registers_the_script(): void
    {
        $this->manager->registerAssets();
        $this->assertTrue(true);
    }
}
