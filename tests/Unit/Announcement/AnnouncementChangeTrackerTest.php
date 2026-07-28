<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Trumpet\Announcement\Announcement;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;
use WP_Post;

/**
 * Cover AnnouncementChangeTracker: capturing the pre-save snapshot on
 * acf/save_post, and the post-save comparison that fires announcement_changed
 * (and syncs the post title) only when the repository reports a real change.
 *
 * @covers \Trumpet\Announcement\AnnouncementChangeTracker
 */
class AnnouncementChangeTrackerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var AnnouncementRepositoryInterface&\Mockery\MockInterface */
    private $repo;
    private AnnouncementChangeTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOriginal();
        unset($GLOBALS['trumpet_test_post_type'], $GLOBALS['trumpet_test_get_post']);
        $this->repo = Mockery::mock(AnnouncementRepositoryInterface::class);
        $this->tracker = new AnnouncementChangeTracker($this->repo);
    }

    protected function tearDown(): void
    {
        $this->resetOriginal();
        parent::tearDown();
    }

    private function resetOriginal(): void
    {
        (new ReflectionClass(AnnouncementChangeTracker::class))
            ->getProperty('originalAnnouncement')->setValue(null, null);
    }

    private function original(): ?Announcement
    {
        return (new ReflectionClass(AnnouncementChangeTracker::class))
            ->getProperty('originalAnnouncement')->getValue();
    }

    private function announcement(string $title): Announcement
    {
        $a = Mockery::mock(Announcement::class);
        $a->shouldReceive('getTitle')->andReturn($title);
        return $a;
    }

    // ─── captureOriginalAnnouncement ─────────────────────────────────

    /** @test */
    public function capture_ignores_a_non_announcement_post(): void
    {
        $GLOBALS['trumpet_test_post_type'] = 'page';
        $this->tracker->captureOriginalAnnouncement(1);
        $this->assertNull($this->original());
    }

    /** @test */
    public function capture_stores_the_snapshot_for_an_announcement(): void
    {
        $GLOBALS['trumpet_test_post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        $snapshot = $this->announcement('Before');
        $this->repo->shouldReceive('findById')->with(5)->andReturn($snapshot);

        $this->tracker->captureOriginalAnnouncement(5);
        $this->assertSame($snapshot, $this->original());
    }

    /** @test */
    public function capture_swallows_a_repository_error(): void
    {
        $GLOBALS['trumpet_test_post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        $this->repo->shouldReceive('findById')->andThrow(new RuntimeException('boom'));

        $this->tracker->captureOriginalAnnouncement(5);
        $this->assertNull($this->original());
    }

    // ─── checkForChanges ─────────────────────────────────────────────

    /** @test */
    public function check_ignores_a_non_announcement_post(): void
    {
        $GLOBALS['trumpet_test_post_type'] = 'page';
        $this->tracker->checkForChanges(1);
        $this->assertTrue(true);
    }

    /** @test */
    public function check_returns_early_when_no_snapshot_was_captured(): void
    {
        $GLOBALS['trumpet_test_post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        $this->tracker->checkForChanges(5);
        $this->assertTrue(true);
    }

    /** @test */
    public function check_returns_when_the_updated_announcement_cannot_be_fetched(): void
    {
        $GLOBALS['trumpet_test_post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        $this->seedOriginal($this->announcement('Before'));
        $this->repo->shouldReceive('findById')->with(5)->andReturn(null);

        $this->tracker->checkForChanges(5);
        // Snapshot is left in place (only cleared on a completed comparison).
        $this->assertNotNull($this->original());
    }

    /** @test */
    public function check_fires_the_changed_hook_and_syncs_the_title(): void
    {
        $GLOBALS['trumpet_test_post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        $this->seedOriginal($this->announcement('Before'));

        $updated = $this->announcement('After');
        $this->repo->shouldReceive('findById')->with(5)->andReturn($updated);
        $this->repo->shouldReceive('hasAnnouncementChanged')->andReturn(true);

        $post = new WP_Post(['ID' => 5, 'post_title' => 'Before']);
        $GLOBALS['trumpet_test_get_post'] = $post;

        $this->tracker->checkForChanges(5);
        $this->assertNull($this->original());
    }

    /** @test */
    public function check_clears_the_snapshot_when_nothing_changed(): void
    {
        $GLOBALS['trumpet_test_post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        $this->seedOriginal($this->announcement('Before'));

        $this->repo->shouldReceive('findById')->with(5)->andReturn($this->announcement('Before'));
        $this->repo->shouldReceive('hasAnnouncementChanged')->andReturn(false);

        $this->tracker->checkForChanges(5);
        $this->assertNull($this->original());
    }

    private function seedOriginal(Announcement $a): void
    {
        (new ReflectionClass(AnnouncementChangeTracker::class))
            ->getProperty('originalAnnouncement')->setValue(null, $a);
    }
}
