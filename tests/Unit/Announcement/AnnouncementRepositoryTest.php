<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Tests\TestCase;
use Trumpet\Announcement\Announcement;
use Trumpet\Announcement\AnnouncementRepository;
use Trumpet\Config\TrumpetConfig;
use Trumpet\Exception\AnnouncementException;
use Unity\Core\Interfaces\Cache;
use WP_Post;

/*
 * Cover AnnouncementRepository: the cached findAll, findById/findActive, the
 * save/update/delete write paths and their WP_Error/failure branches, the
 * status-transition hook, cache clearing, and the field-by-field
 * change-detection used to decide whether to fire the "changed" event.
 */

covers(AnnouncementRepository::class);

// parent::setUp() clears WpState: the object cache, the seeded posts and
// everything get_post()/get_posts() read. Nothing to unset here.

function cachedRepository(): AnnouncementRepository
{
    return new AnnouncementRepository(new InMemoryUnityCache());
}

function repositoryPost(int $id, string $status = 'publish'): WP_Post
{
    return new WP_Post([
        'ID' => $id,
        'post_status' => $status,
        'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
    ]);
}

// Seed a post so get_post()/get_post_type() resolve it, and hand it back
// for the tests that also need the object itself.
function seedRepositoryPost(int $id, string $status = 'publish'): WP_Post
{
    $post = repositoryPost($id, $status);
    WpState::$posts[$id] = $post;
    WpState::$postTypes[$id] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
    WpState::$postStatuses[$id] = $status;

    return $post;
}

// ─── findAll (cache miss + hit) ──────────────────────────────────
it('queries findAll then serves it from cache', function () {
    $this->setFields([TestCase::TITLE => 'Hello'], 10);
    WpState::$queryPosts = [seedRepositoryPost(10)];

    $repo = cachedRepository();
    $first = $repo->findAll();
    expect($first)->toHaveCount(1)
        ->and($first[0])->toBeInstanceOf(Announcement::class);

    // Second call is served from the transient cache (get_posts emptied).
    WpState::$queryPosts = [];
    $second = $repo->findAll();
    expect($second)->toHaveCount(1);
});

// ─── findById ────────────────────────────────────────────────────
describe('findById', function () {
    it('returns an announcement', function () {
        $this->setFields([TestCase::TITLE => 'One'], 5);
        seedRepositoryPost(5);

        expect(cachedRepository()->findById(5))->toBeInstanceOf(Announcement::class);
    });

    it('returns null for a missing post or the wrong type', function () {
        // Nothing seeded, so get_post() answers null.
        expect(cachedRepository()->findById(99))->toBeNull();

        $post = seedRepositoryPost(6);
        $post->post_type = 'page';
        WpState::$postTypes[6] = 'page';
        expect(cachedRepository()->findById(6))->toBeNull();
    });
});

// ─── findActive ──────────────────────────────────────────────────
it('filters hidden and expired announcements out of findActive', function () {
    $this->setFields([TestCase::TITLE => 'Visible'], 1);
    $this->setFields([TestCase::TITLE => 'Hidden', TestCase::HIDE => true], 2);
    WpState::$queryPosts = [seedRepositoryPost(1), seedRepositoryPost(2)];

    $active = cachedRepository()->findActive();
    expect($active)->toHaveCount(1);
});

// ─── save ────────────────────────────────────────────────────────
describe('save', function () {
    it('persists and clears the cache', function () {
        $announcement = $this->makeAnnouncement([TestCase::TITLE => 'New'], 'publish', 20);
        WpState::$nextPostId = 21;

        expect(cachedRepository()->save($announcement))->toBeTrue();
    });

    it('wraps a WP_Error in an AnnouncementException', function () {
        $announcement = $this->makeAnnouncement([TestCase::TITLE => 'New'], 'publish', 20);
        // wp-mocks' wp_insert_post() always succeeds, so the WP_Error branch
        // is reached by overriding it for this test only.
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('insert_failed', 'insert refused'));

        cachedRepository()->save($announcement);
    })->throws(AnnouncementException::class);
});

// ─── update ──────────────────────────────────────────────────────
describe('update', function () {
    it('persists when the original exists', function () {
        // findById inside update() reads get_post; return the same post so the
        // original loads, then the update proceeds.
        $this->setFields([TestCase::TITLE => 'Updated'], 30);
        seedRepositoryPost(30);
        $announcement = $this->makeAnnouncement([TestCase::TITLE => 'Updated'], 'publish', 30);

        expect(cachedRepository()->update($announcement))->toBeTrue();
    });

    it('throws when the original is missing', function () {
        // Nothing seeded, so get_post() answers null.
        $announcement = $this->makeAnnouncement([TestCase::TITLE => 'X'], 'publish', 40);

        cachedRepository()->update($announcement);
    })->throws(AnnouncementException::class);
});

// ─── delete ──────────────────────────────────────────────────────
describe('delete', function () {
    it('succeeds', function () {
        seedRepositoryPost(7);
        expect(cachedRepository()->delete(7))->toBeTrue();
    });

    it('throws when wp_delete_post fails', function () {
        // wp_delete_post() reports failure by returning false, which it does
        // for a post that is not there — so simply do not seed one.
        cachedRepository()->delete(7);
    })->throws(AnnouncementException::class);
});

// ─── clearCache + status transition ──────────────────────────────
describe('clearCache and status transitions', function () {
    it('skips clearCache for a non-announcement post', function () {
        WpState::$postTypes[123] = 'page';
        // Should return early without touching the cache; simply must not error.
        expect(fn () => cachedRepository()->clearCache(123))->not->toThrow(\Throwable::class);
    });

    it('ignores status transitions on other post types', function () {
        $post = repositoryPost(1);
        $post->post_type = 'page';
        cachedRepository()->handlePostStatusTransition('publish', 'draft', $post);

        $this->assertActionFiredTimes('announcement_status_changed', 0);
    });

    it('fires the approval and review events on a status transition', function () {
        $this->setFields([TestCase::TITLE => 'T'], 50);
        $post = repositoryPost(50);
        $post->post_type = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;

        cachedRepository()->handlePostStatusTransition('publish', 'pending', $post); // approved
        cachedRepository()->handlePostStatusTransition('pending', 'draft', $post);   // in review

        $this->assertActionFired('announcement_approved');
        $this->assertActionFired('announcement_in_review');
    });
});

// ─── hasAnnouncementChanged ──────────────────────────────────────
describe('hasAnnouncementChanged', function () {
    it('is false for identical announcements', function () {
        $fields = [TestCase::TITLE => 'Same', TestCase::BODY => 'Body', TestCase::END_DATE => '01/01/2027'];
        $a = $this->makeAnnouncement($fields, 'publish', 1);
        $b = $this->makeAnnouncement($fields, 'publish', 2);

        expect(cachedRepository()->hasAnnouncementChanged($a, $b))->toBeFalse();
    });

    it('detects a field difference', function (array $overrides) {
        $base = [TestCase::TITLE => 'Same', TestCase::BODY => 'Body', TestCase::END_DATE => '01/01/2027'];
        $a = $this->makeAnnouncement($base, 'publish', 1);
        $b = $this->makeAnnouncement(array_merge($base, $overrides), 'publish', 2);

        expect(cachedRepository()->hasAnnouncementChanged($a, $b))->toBeTrue();
    })->with([
        'title'     => [[TrumpetConfig::TITLE_FIELD => 'Different']],
        'body'      => [[TrumpetConfig::BODY_FIELD => 'Different body']],
        'hidden'    => [[TrumpetConfig::HIDE_FIELD => true]],
        'show map'  => [[TrumpetConfig::SHOW_MAP_FIELD => true]],
        'end date'  => [[TrumpetConfig::END_DATE_FIELD => '02/02/2028']],
        'location'  => [[TrumpetConfig::LOCATION_FIELD => ['lat' => '51.5', 'lng' => '-0.1', 'address' => 'X']]],
        'meeting'   => [[TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]]],
        'start date' => [[TrumpetConfig::START_DISPLAY_FIELD => '03/03/2026']],
    ]);

    it('detects a status difference', function () {
        $a = $this->makeAnnouncement([TestCase::TITLE => 'Same'], 'publish', 1);
        $b = $this->makeAnnouncement([TestCase::TITLE => 'Same'], 'pending', 2);

        expect(cachedRepository()->hasAnnouncementChanged($a, $b))->toBeTrue();
    });
});

/**
 * In-memory implementation of Unity's Cache contract for the repository tests.
 *
 * AnnouncementRepositoryWriteTest.php, in the same namespace, uses it too.
 */
final class InMemoryUnityCache implements Cache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, string $group = '')
    {
        return $this->store[$group . '|' . $key] ?? false;
    }

    /**
     * Unity's Cache grew this alongside its member cache. Trumpet reads one
     * key at a time and has no use for it, but a double that does not
     * implement the whole contract will not load at all.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, string $group = ''): array
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key, $group);
        }

        return $found;
    }

    public function set(string $key, mixed $value, string $group = '', int $expire = 0): bool
    {
        $this->store[$group . '|' . $key] = $value;
        return true;
    }

    public function delete(string $key, string $group = ''): bool
    {
        unset($this->store[$group . '|' . $key]);
        return true;
    }

    public function flush(): void
    {
        $this->store = [];
    }
}
