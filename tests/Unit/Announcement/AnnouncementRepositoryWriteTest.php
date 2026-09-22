<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use RuntimeException;
use Tests\TestCase;
use Trumpet\Announcement\AnnouncementRepository;
use Trumpet\Config\TrumpetConfig;
use Trumpet\Exception\AnnouncementException;
use Unity\Core\Interfaces\Cache;
use WP_Post;

/*
 * Cover the AnnouncementRepository write paths not reached by the main suite:
 * the full updateCustomFields fan-out on a save with every optional field, the
 * update() WP_Error and "changed" branches, and the remaining null-vs-set /
 * multi-value comparisons in hasAnnouncementChanged.
 *
 * The repositories here are built on InMemoryUnityCache, declared in
 * AnnouncementRepositoryTest.php in the same namespace.
 */

covers(AnnouncementRepository::class);

// parent::setUp() clears WpState: the object cache and the seeded posts
// get_post() reads. Nothing to unset here.

// Seed a post so findById() inside update() can rebuild the original.
function seedWrittenPost(int $id): void
{
    WpState::$posts[$id] = new WP_Post([
        'ID' => $id,
        'post_status' => 'publish',
        'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
    ]);
    WpState::$postTypes[$id] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
    WpState::$postStatuses[$id] = 'publish';
}

function writeRepository(): AnnouncementRepository
{
    return new AnnouncementRepository(new InMemoryUnityCache());
}

/**
 * A fully populated announcement so every updateCustomFields branch runs.
 *
 * @return array<string, mixed>
 */
function richWriteFields(): array
{
    return [
        TestCase::TITLE => 'Rich',
        TestCase::BODY => 'Body',
        TestCase::END_DATE => '01/01/2027',
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => ['lat' => '51.45', 'lng' => '-2.58', 'address' => 'Bristol'],
        TrumpetConfig::RELATED_MEETING_FIELD => [7],
        TestCase::START_DISPLAY => '01/06/2026',
    ];
}

// ─── save → updateCustomFields fan-out ───────────────────────────
it('writes every optional custom field on save', function () {
    $announcement = $this->makeAnnouncement(richWriteFields(), 'publish', 60);
    WpState::$nextPostId = 61;

    expect(writeRepository()->save($announcement))->toBeTrue();
});

// ─── update: WP_Error + changed branch ───────────────────────────
describe('update', function () {
    it('wraps a WP_Error from wp_update_post', function () {
        $this->setFields([TestCase::TITLE => 'Orig'], 70);
        seedWrittenPost(70);
        // wp-mocks' wp_update_post() always succeeds, so the WP_Error branch
        // is reached by overriding it for this test only.
        Functions\when('wp_update_post')->justReturn(new \WP_Error('update_failed', 'update refused'));

        $announcement = $this->makeAnnouncement([TestCase::TITLE => 'Orig'], 'publish', 70);

        writeRepository()->update($announcement);
    })->throws(AnnouncementException::class);

    it('fires the changed hook when the title differs', function () {
        // The announcement being written captures 'After' at construction time.
        $announcement = $this->makeAnnouncement([TestCase::TITLE => 'After'], 'publish', 71);

        // Re-point the id's fields to 'Before' so the original that findById()
        // rebuilds inside update() differs → hasAnnouncementChanged() is true.
        $this->setFields([TestCase::TITLE => 'Before'], 71);
        seedWrittenPost(71);

        expect(writeRepository()->update($announcement))->toBeTrue();
    });
});

// ─── hasAnnouncementChanged: remaining branches ──────────────────
describe('hasAnnouncementChanged', function () {
    it('counts an end date null versus set as changed', function () {
        $a = $this->makeAnnouncement([TestCase::END_DATE => '01/01/2027'], 'publish', 1);
        $b = $this->makeAnnouncement([], 'publish', 2);
        expect(writeRepository()->hasAnnouncementChanged($a, $b))->toBeTrue();
    });

    it('counts start dates both set but different as changed', function () {
        $a = $this->makeAnnouncement([TestCase::START_DISPLAY => '01/01/2026'], 'publish', 1);
        $b = $this->makeAnnouncement([TestCase::START_DISPLAY => '02/02/2027'], 'publish', 2);
        expect(writeRepository()->hasAnnouncementChanged($a, $b))->toBeTrue();
    });

    it('counts a meeting count difference as changed', function () {
        $a = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]], 'publish', 1);
        $b = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 2, 3]], 'publish', 2);
        expect(writeRepository()->hasAnnouncementChanged($a, $b))->toBeTrue();
    });

    it('counts a meeting content difference as changed', function () {
        $a = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]], 'publish', 1);
        $b = $this->makeAnnouncement([TrumpetConfig::RELATED_MEETING_FIELD => [1, 3]], 'publish', 2);
        expect(writeRepository()->hasAnnouncementChanged($a, $b))->toBeTrue();
    });

    it('does not count identical meeting lists as a change', function () {
        // Same members in a different order → sort()+serialize() match, so this
        // comparison passes through without reporting a change.
        $fields = [TestCase::TITLE => 'Same'];
        $a = $this->makeAnnouncement($fields + [TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]], 'publish', 1);
        $b = $this->makeAnnouncement($fields + [TrumpetConfig::RELATED_MEETING_FIELD => [2, 1]], 'publish', 2);
        expect(writeRepository()->hasAnnouncementChanged($a, $b))->toBeFalse();
    });
});

// ─── error paths: cache failure is wrapped ───────────────────────
describe('cache failures', function () {
    it('wraps an unexpected cache error in findAll', function () {
        $repo = new AnnouncementRepository(new ThrowingCache());
        $repo->findAll();
    })->throws(AnnouncementException::class);

    it('wraps an unexpected cache error in findActive', function () {
        $repo = new AnnouncementRepository(new ThrowingCache());
        $repo->findActive();
    })->throws(AnnouncementException::class);
});

/** A Cache whose reads blow up, to drive the repositories' catch blocks. */
final class ThrowingCache implements Cache
{
    public function get(string $key, string $group = '')
    {
        throw new RuntimeException('cache exploded');
    }

    /**
     * Blows up like get(), for the same reason: this double exists to prove
     * the repository's catch blocks work, and a read that quietly succeeded
     * would be the one hole in it.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, string $group = ''): array
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
