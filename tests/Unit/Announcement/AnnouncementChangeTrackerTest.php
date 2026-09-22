<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Trumpet\Announcement\Announcement;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;
use WP_Post;

/*
 * Cover AnnouncementChangeTracker: capturing the pre-save snapshot on
 * acf/save_post, and the post-save comparison that fires announcement_changed
 * (and syncs the post title) only when the repository reports a real change.
 */

covers(AnnouncementChangeTracker::class);

function resetTrackedOriginal(): void
{
    (new ReflectionClass(AnnouncementChangeTracker::class))
        ->getProperty('originalAnnouncement')->setValue(null, null);
}

function trackedOriginal(): ?Announcement
{
    return (new ReflectionClass(AnnouncementChangeTracker::class))
        ->getProperty('originalAnnouncement')->getValue();
}

function seedTrackedOriginal(Announcement $a): void
{
    (new ReflectionClass(AnnouncementChangeTracker::class))
        ->getProperty('originalAnnouncement')->setValue(null, $a);
}

function titledAnnouncement(string $title): Announcement
{
    $a = Mockery::mock(Announcement::class);
    $a->shouldReceive('getTitle')->andReturn($title);
    return $a;
}

beforeEach(function () {
    resetTrackedOriginal();
    /** @var AnnouncementRepositoryInterface&MockInterface */
    $this->repo = Mockery::mock(AnnouncementRepositoryInterface::class);
    $this->tracker = new AnnouncementChangeTracker($this->repo);
});

afterEach(function () {
    resetTrackedOriginal();
});

// ─── captureOriginalAnnouncement ─────────────────────────────────
describe('captureOriginalAnnouncement', function () {
    it('ignores a non-announcement post', function () {
        Functions\when('get_post_type')->justReturn('page');
        $this->tracker->captureOriginalAnnouncement(1);
        expect(trackedOriginal())->toBeNull();
    });

    it('stores the snapshot for an announcement', function () {
        Functions\when('get_post_type')->justReturn(TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        $snapshot = titledAnnouncement('Before');
        $this->repo->shouldReceive('findById')->with(5)->andReturn($snapshot);

        $this->tracker->captureOriginalAnnouncement(5);
        expect(trackedOriginal())->toBe($snapshot);
    });

    it('swallows a repository error', function () {
        Functions\when('get_post_type')->justReturn(TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        $this->repo->shouldReceive('findById')->andThrow(new RuntimeException('boom'));

        $this->tracker->captureOriginalAnnouncement(5);
        expect(trackedOriginal())->toBeNull();
    });
});

// ─── checkForChanges ─────────────────────────────────────────────
describe('checkForChanges', function () {
    it('ignores a non-announcement post', function () {
        Functions\when('get_post_type')->justReturn('page');
        // The type check comes first, so the repository is never consulted.
        $this->repo->shouldNotReceive('findById');
        $this->tracker->checkForChanges(1);
    });

    it('returns early when no snapshot was captured', function () {
        Functions\when('get_post_type')->justReturn(TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        // With nothing to compare against, the updated announcement is never
        // fetched.
        $this->repo->shouldNotReceive('findById');
        $this->tracker->checkForChanges(5);
    });

    it('returns when the updated announcement cannot be fetched', function () {
        Functions\when('get_post_type')->justReturn(TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        seedTrackedOriginal(titledAnnouncement('Before'));
        $this->repo->shouldReceive('findById')->with(5)->andReturn(null);

        $this->tracker->checkForChanges(5);
        // Snapshot is left in place (only cleared on a completed comparison).
        expect(trackedOriginal())->not->toBeNull();
    });

    it('fires the changed hook and syncs the title', function () {
        Functions\when('get_post_type')->justReturn(TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        seedTrackedOriginal(titledAnnouncement('Before'));

        $updated = titledAnnouncement('After');
        $this->repo->shouldReceive('findById')->with(5)->andReturn($updated);
        $this->repo->shouldReceive('hasAnnouncementChanged')->andReturn(true);

        // The post has to exist and still carry the old title: that is what
        // sends checkForChanges() down its wp_update_post() branch.
        WpState::$posts[5] = new WP_Post(['ID' => 5, 'post_title' => 'Before']);

        $this->tracker->checkForChanges(5);

        expect(trackedOriginal())->toBeNull()
            ->and(WpState::$updatedPosts[0]['post_title'] ?? null)->toBe('After');
    });

    it('clears the snapshot when nothing changed', function () {
        Functions\when('get_post_type')->justReturn(TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        seedTrackedOriginal(titledAnnouncement('Before'));

        $this->repo->shouldReceive('findById')->with(5)->andReturn(titledAnnouncement('Before'));
        $this->repo->shouldReceive('hasAnnouncementChanged')->andReturn(false);

        $this->tracker->checkForChanges(5);
        expect(trackedOriginal())->toBeNull();
    });
});
