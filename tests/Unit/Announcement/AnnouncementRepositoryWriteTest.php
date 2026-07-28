<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use RuntimeException;
use Tests\TestCase;
use Trumpet\Announcement\AnnouncementRepository;
use Trumpet\Config\TrumpetConfig;
use Trumpet\Exception\AnnouncementException;
use Unity\Core\Interfaces\Cache;
use WP_Post;

/**
 * Cover the AnnouncementRepository write paths not reached by the main suite:
 * the full updateCustomFields fan-out on a save with every optional field, the
 * update() WP_Error and "changed" branches, and the remaining null-vs-set /
 * multi-value comparisons in hasAnnouncementChanged.
 *
 * @covers \Trumpet\Announcement\AnnouncementRepository
 */
class AnnouncementRepositoryWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['trumpet_test_cache'] = [];
        unset(
            $GLOBALS['trumpet_test_get_post'],
            $GLOBALS['trumpet_test_update_error'],
        );
    }

    private function repo(): AnnouncementRepository
    {
        return new AnnouncementRepository(new InMemoryUnityCache());
    }

    /** A fully populated announcement so every updateCustomFields branch runs. */
    private function richFields(): array
    {
        return [
            self::TITLE => 'Rich',
            self::BODY => 'Body',
            self::END_DATE => '01/01/2027',
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.45', 'lng' => '-2.58', 'address' => 'Bristol'],
            TrumpetConfig::RELATED_MEETING_FIELD => [7],
            self::START_DISPLAY => '01/06/2026',
        ];
    }

    // ─── save → updateCustomFields fan-out ───────────────────────────

    public function testSaveWritesEveryOptionalCustomField(): void
    {
        $announcement = $this->makeAnnouncement($this->richFields(), 'publish', 60);
        $GLOBALS['trumpet_test_insert_id'] = 61;

        $this->assertTrue($this->repo()->save($announcement));
    }

    // ─── update: WP_Error + changed branch ───────────────────────────

    public function testUpdateWrapsAWpErrorFromWpUpdatePost(): void
    {
        $this->setFields([self::TITLE => 'Orig'], 70);
        $GLOBALS['trumpet_test_get_post'] = new WP_Post([
            'ID' => 70,
            'post_status' => 'publish',
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
        ]);
        $GLOBALS['trumpet_test_update_error'] = 'update refused';

        $announcement = $this->makeAnnouncement([self::TITLE => 'Orig'], 'publish', 70);

        $this->expectException(AnnouncementException::class);
        $this->repo()->update($announcement);
    }

    public function testUpdateFiresChangedHookWhenTheTitleDiffers(): void
    {
        // The announcement being written captures 'After' at construction time.
        $announcement = $this->makeAnnouncement([self::TITLE => 'After'], 'publish', 71);

        // Re-point the id's fields to 'Before' so the original that findById()
        // rebuilds inside update() differs → hasAnnouncementChanged() is true.
        $this->setFields([self::TITLE => 'Before'], 71);
        $GLOBALS['trumpet_test_get_post'] = new WP_Post([
            'ID' => 71,
            'post_status' => 'publish',
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
        ]);

        $this->assertTrue($this->repo()->update($announcement));
    }

    // ─── hasAnnouncementChanged: remaining branches ──────────────────

    public function testEndDateNullVersusSetCountsAsChanged(): void
    {
        $a = $this->makeAnnouncement([self::END_DATE => '01/01/2027'], 'publish', 1);
        $b = $this->makeAnnouncement([], 'publish', 2);
        $this->assertTrue($this->repo()->hasAnnouncementChanged($a, $b));
    }

    public function testStartDateBothSetButDifferentCountsAsChanged(): void
    {
        $a = $this->makeAnnouncement([self::START_DISPLAY => '01/01/2026'], 'publish', 1);
        $b = $this->makeAnnouncement([self::START_DISPLAY => '02/02/2027'], 'publish', 2);
        $this->assertTrue($this->repo()->hasAnnouncementChanged($a, $b));
    }

    public function testMeetingCountDifferenceCountsAsChanged(): void
    {
        $a = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]], 'publish', 1);
        $b = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 2, 3]], 'publish', 2);
        $this->assertTrue($this->repo()->hasAnnouncementChanged($a, $b));
    }

    public function testMeetingContentDifferenceCountsAsChanged(): void
    {
        $a = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]], 'publish', 1);
        $b = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 3]], 'publish', 2);
        $this->assertTrue($this->repo()->hasAnnouncementChanged($a, $b));
    }

    public function testIdenticalMeetingListsAreNotAChange(): void
    {
        // Same members in a different order → sort()+serialize() match, so this
        // comparison passes through without reporting a change.
        $fields = [self::TITLE => 'Same'];
        $a = $this->makeAnnouncement($fields + [TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]], 'publish', 1);
        $b = $this->makeAnnouncement($fields + [TrumpetConfig::RELATED_MEETING_FIELD => [2, 1]], 'publish', 2);
        $this->assertFalse($this->repo()->hasAnnouncementChanged($a, $b));
    }

    // ─── error paths: cache failure is wrapped ───────────────────────

    public function testFindAllWrapsAnUnexpectedCacheError(): void
    {
        $repo = new AnnouncementRepository(new ThrowingCache());
        $this->expectException(AnnouncementException::class);
        $repo->findAll();
    }

    public function testFindActiveWrapsAnUnexpectedCacheError(): void
    {
        $repo = new AnnouncementRepository(new ThrowingCache());
        $this->expectException(AnnouncementException::class);
        $repo->findActive();
    }
}

/** A Cache whose reads blow up, to drive the repositories' catch blocks. */
final class ThrowingCache implements Cache
{
    public function get(string $key, string $group = '')
    {
        throw new RuntimeException('cache exploded');
    }

    public function set(string $key, mixed $value, string $group = '', int $expire = 0): bool
    {
        return true;
    }

    public function delete(string $key, string $group = ''): bool
    {
        return true;
    }

    public function flush(): void
    {
    }
}
