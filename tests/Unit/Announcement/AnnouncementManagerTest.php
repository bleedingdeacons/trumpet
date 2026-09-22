<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use BleedingDeacons\WpMocks\WpState;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;
use Trumpet\Exception\AnnouncementException;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/*
 * Cover AnnouncementManager: the [list_announcements] and
 * [announcements_indicator] shortcodes, the single-announcement render
 * (title, content, map, related meetings, meta), asset registration, the
 * inline stylesheet, and the empty/error branches.
 */

covers(AnnouncementManager::class);

beforeEach(function () {
    /** @var AnnouncementRepositoryInterface&MockInterface */
    $this->repo = Mockery::mock(AnnouncementRepositoryInterface::class);
    /** @var MeetingRepository&MockInterface */
    $this->meetings = Mockery::mock(MeetingRepository::class);
    $this->manager = new AnnouncementManager($this->repo, $this->meetings);

    $this->richAnnouncement = fn () => $this->makeAnnouncement([
        TestCase::TITLE => 'Big News',
        TestCase::BODY => 'Hello <img src="pic.jpg"> world',
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => ['lat' => '51.45', 'lng' => '-2.58', 'address' => 'Bristol'],
        TrumpetConfig::RELATED_MEETING_FIELD => [7],
        TestCase::END_DATE => '01/01/2027',
    ], 'publish', 100);
});

it('emits the inline stylesheet from addStyles', function () {
    $css = captureOutput(fn () => $this->manager->addStyles());

    expect($css)->toContain('.announcement');
});

it('returns the repository result from getAnnouncements', function () {
    $this->repo->shouldReceive('findAll')->andReturn(['a', 'b']);
    expect($this->manager->getAnnouncements())->toBe(['a', 'b']);
});

it('returns empty from getAnnouncements on a repository error', function () {
    $this->repo->shouldReceive('findAll')->andThrow(new AnnouncementException('boom'));
    expect($this->manager->getAnnouncements())->toBe([]);
});

it('renders active announcements with map and meetings in the list', function () {
    $meeting = Mockery::mock(Meeting::class);
    $meeting->shouldReceive('isOnline')->andReturn(true);
    $meeting->shouldReceive('getUrl')->andReturn('https://meet.example/7');
    $meeting->shouldReceive('getName')->andReturn('Tuesday Group');
    $this->meetings->shouldReceive('findById')->with(7)->andReturn($meeting);

    $this->repo->shouldReceive('findActive')->andReturn([($this->richAnnouncement)()]);

    $html = $this->manager->generateAnnouncementsList();

    expect($html)
        ->toContain('announcements-container')
        ->toContain('Big News')
        ->toContain('acf-map')
        ->toContain('meeting_link')
        ->toContain('Valid until');
});

it('includes the edit link, thumbnail and offline meeting in a single render', function () {
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

    $this->repo->shouldReceive('findActive')->andReturn([($this->richAnnouncement)()]);

    $html = $this->manager->generateAnnouncementsList();

    expect($html)
        ->toContain('announcement-edit-link')
        ->toContain('announcement-featured-image')
        ->toContain('face2face');
});

it('skips a meeting the repository cannot find in a single render', function () {
    $this->meetings->shouldReceive('findById')->with(7)->andReturn(null);
    $this->repo->shouldReceive('findActive')->andReturn([($this->richAnnouncement)()]);

    // Still renders the announcement, just without a meeting link.
    $html = $this->manager->generateAnnouncementsList();
    expect($html)
        ->toContain('Big News')
        ->not->toContain('meeting_link');
});

it('shows the empty message in the list', function () {
    $this->repo->shouldReceive('findActive')->andReturn([]);
    expect($this->manager->generateAnnouncementsList())->toContain('No current announcements');
});

it('returns an error message from the list on exception', function () {
    $this->repo->shouldReceive('findActive')->andThrow(new AnnouncementException('boom'));
    expect($this->manager->generateAnnouncementsList())->toContain('error-message');
});

it('returns the banner from renderNewIndicator', function () {
    $html = $this->manager->renderNewIndicator();
    expect($html)->toContain('announcements-new-banner');
});

it('registers the script in registerAssets', function () {
    expect(fn () => $this->manager->registerAssets())->not->toThrow(\Throwable::class);
});
