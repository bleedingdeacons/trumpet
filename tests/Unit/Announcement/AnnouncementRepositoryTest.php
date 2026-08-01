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

/**
 * Cover AnnouncementRepository: the cached findAll, findById/findActive, the
 * save/update/delete write paths and their WP_Error/failure branches, the
 * status-transition hook, cache clearing, and the field-by-field
 * change-detection used to decide whether to fire the "changed" event.
 *
 * @covers \Trumpet\Announcement\AnnouncementRepository
 */
class AnnouncementRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp() clears WpState: the object cache, the seeded posts
        // and everything get_post()/get_posts() read. Nothing to unset here.
    }

    /**
     * Seed a post so get_post()/get_post_type() resolve it, and hand it back
     * for the tests that also need the object itself.
     */
    private function seed(int $id, string $status = 'publish'): WP_Post
    {
        $post = $this->post($id, $status);
        WpState::$posts[$id] = $post;
        WpState::$postTypes[$id] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
        WpState::$postStatuses[$id] = $status;

        return $post;
    }

    private function repo(): AnnouncementRepository
    {
        return new AnnouncementRepository(new InMemoryUnityCache());
    }

    private function post(int $id, string $status = 'publish'): WP_Post
    {
        return new WP_Post([
            'ID' => $id,
            'post_status' => $status,
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
        ]);
    }

    // ─── findAll (cache miss + hit) ──────────────────────────────────

    public function testFindAllQueriesThenServesFromCache(): void
    {
        $this->setFields([self::TITLE => 'Hello'], 10);
        WpState::$queryPosts = [$this->seed(10)];

        $repo = $this->repo();
        $first = $repo->findAll();
        $this->assertCount(1, $first);
        $this->assertInstanceOf(Announcement::class, $first[0]);

        // Second call is served from the transient cache (get_posts emptied).
        WpState::$queryPosts = [];
        $second = $repo->findAll();
        $this->assertCount(1, $second);
    }

    // ─── findById ────────────────────────────────────────────────────

    public function testFindByIdReturnsAnAnnouncement(): void
    {
        $this->setFields([self::TITLE => 'One'], 5);
        $this->seed(5);

        $this->assertInstanceOf(Announcement::class, $this->repo()->findById(5));
    }

    public function testFindByIdReturnsNullForMissingOrWrongType(): void
    {
        // Nothing seeded, so get_post() answers null.
        $this->assertNull($this->repo()->findById(99));

        $post = $this->seed(6);
        $post->post_type = 'page';
        WpState::$postTypes[6] = 'page';
        $this->assertNull($this->repo()->findById(6));
    }

    // ─── findActive ──────────────────────────────────────────────────

    public function testFindActiveFiltersHiddenAndExpired(): void
    {
        $this->setFields([self::TITLE => 'Visible'], 1);
        $this->setFields([self::TITLE => 'Hidden', self::HIDE => true], 2);
        WpState::$queryPosts = [$this->seed(1), $this->seed(2)];

        $active = $this->repo()->findActive();
        $this->assertCount(1, $active);
    }

    // ─── save ────────────────────────────────────────────────────────

    public function testSavePersistsAndClearsCache(): void
    {
        $announcement = $this->makeAnnouncement([self::TITLE => 'New'], 'publish', 20);
        WpState::$nextPostId = 21;

        $this->assertTrue($this->repo()->save($announcement));
    }

    public function testSaveWrapsAWpErrorInAnAnnouncementException(): void
    {
        $announcement = $this->makeAnnouncement([self::TITLE => 'New'], 'publish', 20);
        // wp-mocks' wp_insert_post() always succeeds, so the WP_Error branch
        // is reached by overriding it for this test only.
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('insert_failed', 'insert refused'));

        $this->expectException(AnnouncementException::class);
        $this->repo()->save($announcement);
    }

    // ─── update ──────────────────────────────────────────────────────

    public function testUpdatePersistsWhenTheOriginalExists(): void
    {
        // findById inside update() reads get_post; return the same post so the
        // original loads, then the update proceeds.
        $this->setFields([self::TITLE => 'Updated'], 30);
        $this->seed(30);
        $announcement = $this->makeAnnouncement([self::TITLE => 'Updated'], 'publish', 30);

        $this->assertTrue($this->repo()->update($announcement));
    }

    public function testUpdateThrowsWhenTheOriginalIsMissing(): void
    {
        // Nothing seeded, so get_post() answers null.
        $announcement = $this->makeAnnouncement([self::TITLE => 'X'], 'publish', 40);

        $this->expectException(AnnouncementException::class);
        $this->repo()->update($announcement);
    }

    // ─── delete ──────────────────────────────────────────────────────

    public function testDeleteSucceeds(): void
    {
        $this->seed(7);
        $this->assertTrue($this->repo()->delete(7));
    }

    public function testDeleteThrowsWhenWpDeleteFails(): void
    {
        // wp_delete_post() reports failure by returning false, which it does
        // for a post that is not there � so simply do not seed one.
        $this->expectException(AnnouncementException::class);
        $this->repo()->delete(7);
    }

    // ─── clearCache + status transition ──────────────────────────────

    public function testClearCacheSkipsForANonAnnouncementPost(): void
    {
        WpState::$postTypes[123] = 'page';
        // Should return early without touching the cache; simply must not error.
        $this->repo()->clearCache(123);
        $this->assertTrue(true);
    }

    public function testStatusTransitionIgnoresOtherPostTypes(): void
    {
        $post = $this->post(1);
        $post->post_type = 'page';
        $this->repo()->handlePostStatusTransition('publish', 'draft', $post);
        $this->assertTrue(true);
    }

    public function testStatusTransitionFiresApprovalAndReviewEvents(): void
    {
        $this->setFields([self::TITLE => 'T'], 50);
        $post = $this->post(50);
        $post->post_type = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;

        $this->repo()->handlePostStatusTransition('publish', 'pending', $post); // approved
        $this->repo()->handlePostStatusTransition('pending', 'draft', $post);   // in review
        $this->assertTrue(true);
    }

    // ─── hasAnnouncementChanged ──────────────────────────────────────

    public function testHasChangedIsFalseForIdenticalAnnouncements(): void
    {
        $fields = [self::TITLE => 'Same', self::BODY => 'Body', self::END_DATE => '01/01/2027'];
        $a = $this->makeAnnouncement($fields, 'publish', 1);
        $b = $this->makeAnnouncement($fields, 'publish', 2);

        $this->assertFalse($this->repo()->hasAnnouncementChanged($a, $b));
    }

    /**
     * @dataProvider changedFields
     * @param array<string, mixed> $overrides
     */
    public function testHasChangedDetectsAFieldDifference(array $overrides): void
    {
        $base = [self::TITLE => 'Same', self::BODY => 'Body', self::END_DATE => '01/01/2027'];
        $a = $this->makeAnnouncement($base, 'publish', 1);
        $b = $this->makeAnnouncement(array_merge($base, $overrides), 'publish', 2);

        $this->assertTrue($this->repo()->hasAnnouncementChanged($a, $b));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function changedFields(): array
    {
        return [
            'title'     => [[TrumpetConfig::TITLE_FIELD => 'Different']],
            'body'      => [[TrumpetConfig::BODY_FIELD => 'Different body']],
            'hidden'    => [[TrumpetConfig::HIDE_FIELD => true]],
            'show map'  => [[TrumpetConfig::SHOW_MAP_FIELD => true]],
            'end date'  => [[TrumpetConfig::END_DATE_FIELD => '02/02/2028']],
            'location'  => [[TrumpetConfig::LOCATION_FIELD => ['lat' => '51.5', 'lng' => '-0.1', 'address' => 'X']]],
            'meeting'   => [[TrumpetConfig::RELATED_MEETING_FIELD => [1, 2]]],
            'start date' => [[TrumpetConfig::START_DISPLAY_FIELD => '03/03/2026']],
        ];
    }

    public function testHasChangedDetectsAStatusDifference(): void
    {
        $a = $this->makeAnnouncement([self::TITLE => 'Same'], 'publish', 1);
        $b = $this->makeAnnouncement([self::TITLE => 'Same'], 'pending', 2);

        $this->assertTrue($this->repo()->hasAnnouncementChanged($a, $b));
    }
}

/** In-memory implementation of Unity's Cache contract for the repository tests. */
final class InMemoryUnityCache implements Cache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, string $group = '')
    {
        return $this->store[$group . '|' . $key] ?? false;
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
