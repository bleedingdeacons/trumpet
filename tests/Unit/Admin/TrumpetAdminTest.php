<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Functions;
use BleedingDeacons\WpMocks\WpState;
use DateTime;
use Exception;
use Mockery;
use Tests\TestCase;
use Trumpet\Admin\TrumpetAdmin;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Config\TrumpetConfig;
use WP_Post;
use WP_Query;
use WP_Screen;

/**
 * Tests for the announcement list-table admin.
 *
 * src/Trumpet/Admin was excluded from the coverage source set until now, on the
 * grounds that admin screens are "render/menu/enqueue glue exercised through
 * WordPress at runtime". Amber covers its whole src/Admin on the same tooling,
 * so the exclusion was habit rather than necessity — and this class in
 * particular is not glue: the status a row shows, and the meta value that
 * status is sorted by, are both computed here.
 *
 * Three kinds of method, three techniques:
 *
 *   - Registration (the constructor, which is where registerHooks() runs) is
 *     driven for real and asserted against Brain Monkey's hook store.
 *   - Column output is captured with ob_start()/ob_get_clean() and asserted on
 *     as HTML, which is the only place the status → CSS-class mapping shows.
 *   - The status and sort-key writes land in WpState::$postMeta, so what the
 *     list table will later order by is assertable without a database.
 *
 * Nothing here redirects-and-exits, so the exit wall the other ports hit does
 * not apply and no production code needed extracting.
 *
 * Three branches are deliberately left uncovered:
 *
 *   - The `defined('DOING_AUTOSAVE') && DOING_AUTOSAVE` guards in
 *     updateStatusOnSave()/updateStatusOnAcfSave(). A constant cannot be
 *     undefined once set, so defining it would change every test that ran
 *     afterwards in the same process — a process-isolated test to reach two
 *     `return;` statements is a worse trade than leaving them.
 *   - displayAnnouncementStatus()'s classless fallback. Every status
 *     getAnnouncementStatus() can return is a key in $statusClasses, so the
 *     `else` is unreachable defensive code, not an untested path.
 *   - getReviewAnnouncementsCount()'s catch. Its try block holds nothing but
 *     `new WP_Query(...)` and a property read, and WP_Query is a stub class
 *     rather than a function, so there is no seam to make it throw. The other
 *     four catch blocks in this class are covered, two through the manager mock
 *     and two by aliasing get_posts()/update_post_meta() to throw.
 *
 * @covers \Trumpet\Admin\TrumpetAdmin
 */
class TrumpetAdminTest extends TestCase
{
    /** @var AnnouncementManager&Mockery\MockInterface */
    private $manager;

    /** @var AnnouncementRepositoryInterface&Mockery\MockInterface */
    private $repository;

    private ?TrumpetAdmin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = Mockery::mock(AnnouncementManager::class);
        $this->repository = Mockery::mock(AnnouncementRepositoryInterface::class);
        $this->admin = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pagenow'], $GLOBALS['post_type']);

        parent::tearDown();
    }

    /**
     * Built on demand rather than in setUp(), because the constructor is itself
     * under test: the not-in-admin case needs to be the first construction in
     * its test, with an empty hook store behind it.
     */
    private function admin(): TrumpetAdmin
    {
        return $this->admin ??= new TrumpetAdmin($this->manager, $this->repository);
    }

    /** Capture what a callback prints. */
    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** An offset from today as d/m/Y — the format Trumpet's date fields use. */
    private static function offsetDate(int $days): string
    {
        return (new \DateTimeImmutable())->modify(sprintf('%+d days', $days))->format('d/m/Y');
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function the_constructor_registers_every_admin_hook(): void
    {
        $this->admin();

        foreach ([
            'manage_announcement_posts_columns',
            'manage_edit-announcement_sortable_columns',
            'post_row_actions',
        ] as $filter) {
            $this->assertFilterAdded($filter, false, 'expected ' . $filter . ' to be hooked');
        }

        foreach ([
            'manage_announcement_posts_custom_column',
            'pre_get_posts',
            'admin_notices',
            'admin_head',
            'save_post_announcement',
            'acf/save_post',
        ] as $action) {
            $this->assertActionAdded($action, false, 'expected ' . $action . ' to be hooked');
        }
    }

    /**
     * The constructor bails before assigning its dependencies, so a front-end
     * request must not leave any of these hooks behind.
     *
     * @test
     */
    public function nothing_is_hooked_outside_the_admin(): void
    {
        WpState::$isAdmin = false;

        new TrumpetAdmin($this->manager, $this->repository);

        $this->assertFilterNotAdded('post_row_actions');
        $this->assertActionNotAdded('admin_notices');
        $this->assertActionNotAdded('acf/save_post');
    }

    // ── list-table columns ────────────────────────────────────────────

    /**
     * The date column is pulled out and re-appended so the announcement
     * columns sit in front of it rather than after it.
     *
     * @test
     */
    public function the_announcement_columns_are_inserted_ahead_of_the_date_column(): void
    {
        $columns = TrumpetAdmin::addCustomColumns([
            'cb' => '<input type="checkbox" />',
            'title' => 'Title',
            'date' => 'Date',
        ]);

        $this->assertSame([
            'cb',
            'title',
            'announcement_status',
            'announcement_start_date',
            'announcement_end_date',
            'date',
        ], array_keys($columns));

        $this->assertSame('Date', $columns['date'], 'the original date label should survive the move');
    }

    /** @test */
    public function a_column_set_with_no_date_column_gains_only_the_announcement_columns(): void
    {
        $columns = TrumpetAdmin::addCustomColumns(['title' => 'Title']);

        $this->assertSame([
            'title',
            'announcement_status',
            'announcement_start_date',
            'announcement_end_date',
        ], array_keys($columns));
    }

    /** @test */
    public function the_announcement_columns_are_registered_as_sortable(): void
    {
        $this->assertSame([
            'title' => 'title',
            'announcement_end_date' => 'end_date',
            'announcement_status' => 'announcement_status',
            'announcement_start_date' => 'start_date',
        ], $this->admin()->sortableCustomColumns(['title' => 'title']));
    }

    // ── quick edit ────────────────────────────────────────────────────

    /** @test */
    public function quick_edit_is_removed_from_an_announcement_row(): void
    {
        $actions = $this->admin()->removeQuickEdit(
            ['edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit', 'trash' => 'Trash'],
            new WP_Post(['ID' => 1, 'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE])
        );

        $this->assertSame(['edit' => 'Edit', 'trash' => 'Trash'], $actions);
    }

    /** @test */
    public function quick_edit_survives_on_other_post_types(): void
    {
        $actions = ['edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit'];

        $this->assertSame(
            $actions,
            $this->admin()->removeQuickEdit($actions, new WP_Post(['ID' => 1, 'post_type' => 'page']))
        );
    }

    // ── admin styles ──────────────────────────────────────────────────

    /** @test */
    public function the_status_colours_and_column_widths_are_printed_into_admin_head(): void
    {
        $css = $this->capture(fn () => $this->admin()->addAdminStyles());

        foreach ([
            '.status-active',
            '.status-expired',
            '.status-hidden',
            '.status-pending',
            '.status-review',
            '.status-invalid',
            '.status-no-date',
            '.column-announcement_status',
            '.column-announcement_end_date',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
    }

    // ── column content ────────────────────────────────────────────────

    /** @test */
    public function the_end_date_column_prints_the_stored_end_date(): void
    {
        $this->setFields([self::END_DATE => '31/12/2026']);

        $this->assertSame(
            '31/12/2026',
            $this->column('announcement_end_date')
        );
    }

    /** @test */
    public function an_announcement_with_no_end_date_prints_an_empty_end_date_column(): void
    {
        $this->assertSame('', $this->column('announcement_end_date'));
    }

    /**
     * A start date still in the future is shown as the date itself, so an
     * editor can see when the announcement will appear.
     *
     * @test
     */
    public function a_future_start_date_is_printed_and_its_sort_key_recorded(): void
    {
        $future = self::offsetDate(10);
        $this->setFields([self::START_DISPLAY => $future]);

        $this->assertSame($future, $this->column('announcement_start_date'));
        $this->assertSame(
            (string) DateTime::createFromFormat('d/m/Y', $future)?->format('Y-m-d'),
            $this->sortMeta('_announcement_start_date_sort')
        );
    }

    /** @test */
    public function a_start_date_already_reached_prints_started(): void
    {
        $this->setFields([self::START_DISPLAY => self::offsetDate(-1)]);

        $this->assertSame('Started', $this->column('announcement_start_date'));
    }

    /**
     * An unparseable value is treated as "already started" rather than shown
     * back to the editor, and sorts to the end of the list.
     *
     * @test
     */
    public function an_unparseable_start_date_prints_started_and_sorts_last(): void
    {
        $this->setFields([self::START_DISPLAY => 'whenever']);

        $this->assertSame('Started', $this->column('announcement_start_date'));
        $this->assertSame('9999-99-99', $this->sortMeta('_announcement_start_date_sort'));
    }

    /** @test */
    public function an_empty_start_date_prints_a_dash_and_sorts_last(): void
    {
        $html = $this->column('announcement_start_date');

        $this->assertStringContainsString('status-no-date', $html);
        $this->assertStringContainsString('—', $html);
        $this->assertSame('9999-99-99', $this->sortMeta('_announcement_start_date_sort'));
    }

    /** @test */
    public function an_unrecognised_column_prints_nothing(): void
    {
        $this->assertSame('', $this->column('title'));
    }

    // ── status column ─────────────────────────────────────────────────

    /**
     * The status shown in the list table and the meta value it is sorted by are
     * computed together, so they are asserted together.
     *
     * @test
     * @dataProvider announcementStatuses
     * @param array<string, mixed> $fields
     */
    public function the_status_column_reports_and_records_the_announcement_status(
        array $fields,
        string $postStatus,
        string $status,
        string $sortValue,
        string $cssClass
    ): void {
        WpState::addPost(self::POST_ID, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => $postStatus,
        ]);
        $this->setFields($fields);

        $html = $this->column('announcement_status');

        $this->assertSame(sprintf('<span class="%s">%s</span>', $cssClass, $status), $html);
        $this->assertSame($sortValue, $this->sortMeta('_announcement_status_sort'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string, 4: string}> */
    public static function announcementStatuses(): array
    {
        return [
            'awaiting review' => [
                [self::END_DATE => self::offsetDate(10)],
                'pending',
                'Review',
                'review',
                'status-review',
            ],
            // The hide flag outranks the dates, but not the review status.
            'hidden' => [
                [self::HIDE => true, self::END_DATE => self::offsetDate(10)],
                'publish',
                'Hidden',
                'hidden',
                'status-hidden',
            ],
            'not due yet' => [
                [self::START_DISPLAY => self::offsetDate(5), self::END_DATE => self::offsetDate(10)],
                'publish',
                'Pending',
                'pending',
                'status-pending',
            ],
            'no end date' => [
                [self::START_DISPLAY => self::offsetDate(-5)],
                'publish',
                'No End Date',
                'no_end_date',
                'status-no-date',
            ],
            'end date that will not parse' => [
                [self::END_DATE => 'sometime'],
                'publish',
                'Invalid Date',
                'invalid',
                'status-invalid',
            ],
            'end date in the past' => [
                [self::END_DATE => self::offsetDate(-1)],
                'publish',
                'Expired',
                'expired',
                'status-expired',
            ],
            'end date in the future' => [
                [self::END_DATE => self::offsetDate(1)],
                'publish',
                'Active',
                'active',
                'status-active',
            ],
            // Same day counts as still running, not expired.
            'end date today' => [
                [self::END_DATE => self::offsetDate(0)],
                'publish',
                'Active',
                'active',
                'status-active',
            ],
        ];
    }

    /**
     * A post id with nothing behind it — a row deleted between the query and
     * the render — falls through the pending check to the date logic rather
     * than erroring on a null post.
     *
     * @test
     */
    public function a_missing_post_still_yields_a_status(): void
    {
        $this->setFields([self::END_DATE => self::offsetDate(3)]);

        $this->assertSame(
            '<span class="status-active">Active</span>',
            $this->column('announcement_status')
        );
    }

    // ── sorting ───────────────────────────────────────────────────────

    /** @test */
    public function sorting_is_left_alone_outside_the_admin(): void
    {
        $admin = $this->admin();
        WpState::$isAdmin = false;

        $query = new WP_Query(['orderby' => 'end_date']);
        $admin->customSortColumns($query);

        $this->assertSame('end_date', $query->get('orderby'), 'orderby should be untouched');
        $this->assertSame('', $query->get('meta_key'));
    }

    /** @test */
    public function sorting_is_left_alone_for_a_secondary_query(): void
    {
        $query = new WP_Query(['orderby' => 'end_date']);
        $query->isMainQuery = false;

        $this->admin()->customSortColumns($query);

        $this->assertSame('end_date', $query->get('orderby'));
        $this->assertSame('', $query->get('meta_key'));
    }

    /** @test */
    public function sorting_by_end_date_orders_on_the_acf_end_date_field(): void
    {
        $query = new WP_Query(['orderby' => 'end_date']);

        $this->admin()->customSortColumns($query);

        $this->assertSame(TrumpetConfig::END_DATE_FIELD, $query->get('meta_key'));
        $this->assertSame('meta_value', $query->get('orderby'));
    }

    /**
     * The start date is stored as d/m/Y, which does not sort, so ordering goes
     * through a Y-m-d shadow meta key instead.
     *
     * @test
     */
    public function sorting_by_start_date_orders_on_the_shadow_sort_key(): void
    {
        $query = new WP_Query(['orderby' => 'start_date']);

        $this->admin()->customSortColumns($query);

        $this->assertSame('_announcement_start_date_sort', $query->get('meta_key'));
        $this->assertSame('meta_value', $query->get('orderby'));
        $this->assertSame([], WpState::$postMeta, 'no screen means no rebuild');
    }

    /**
     * On the announcement list screen the shadow keys are rebuilt first, so a
     * row whose date was edited elsewhere still sorts correctly.
     *
     * @test
     */
    public function sorting_by_start_date_on_the_announcement_screen_rebuilds_every_shadow_key(): void
    {
        $this->seedAnnouncements([
            201 => [self::START_DISPLAY => '05/03/2026'],
            202 => [],
        ]);
        WpState::$screen = new WP_Screen(['id' => 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE]);

        $this->admin()->customSortColumns(new WP_Query(['orderby' => 'start_date']));

        $this->assertSame('2026-03-05', WpState::$postMeta[201]['_announcement_start_date_sort']);
        $this->assertSame('9999-99-99', WpState::$postMeta[202]['_announcement_start_date_sort']);
    }

    /** @test */
    public function sorting_by_status_orders_on_the_status_sort_key(): void
    {
        $query = new WP_Query(['orderby' => 'announcement_status']);

        $this->admin()->customSortColumns($query);

        $this->assertSame('_announcement_status_sort', $query->get('meta_key'));
        $this->assertSame('meta_value', $query->get('orderby'));
        $this->assertSame([], WpState::$postMeta, 'no screen means no rebuild');
    }

    /** @test */
    public function sorting_by_status_on_the_announcement_screen_rebuilds_every_status_key(): void
    {
        $this->seedAnnouncements([
            301 => [self::END_DATE => self::offsetDate(5)],
            302 => [self::HIDE => true],
        ]);
        WpState::$screen = new WP_Screen(['id' => 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE]);

        $this->admin()->customSortColumns(new WP_Query(['orderby' => 'announcement_status']));

        $this->assertSame('active', WpState::$postMeta[301]['_announcement_status_sort']);
        $this->assertSame('hidden', WpState::$postMeta[302]['_announcement_status_sort']);
    }

    /** @test */
    public function no_rebuild_happens_on_another_screen(): void
    {
        $this->seedAnnouncements([301 => [self::END_DATE => self::offsetDate(5)]]);
        WpState::$screen = new WP_Screen(['id' => 'edit-post']);

        $this->admin()->customSortColumns(new WP_Query(['orderby' => 'announcement_status']));

        $this->assertSame([], WpState::$postMeta);
    }

    /** @test */
    public function an_unrecognised_orderby_is_left_alone(): void
    {
        $query = new WP_Query(['orderby' => 'title']);

        $this->admin()->customSortColumns($query);

        $this->assertSame('title', $query->get('orderby'));
        $this->assertSame('', $query->get('meta_key'));
    }

    /**
     * The rebuild loops swallow failures rather than breaking the list table,
     * and both report through Plugin's logger.
     *
     * @test
     * @dataProvider rebuildOrderings
     */
    public function a_failed_rebuild_is_logged_and_leaves_the_list_table_working(
        string $orderby,
        string $expectedMessage
    ): void {
        Functions\when('get_posts')->alias(static function (array $args = []): array {
            throw new Exception('the posts table is unavailable');
        });
        WpState::$screen = new WP_Screen(['id' => 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE]);

        $query = new WP_Query(['orderby' => $orderby]);
        $this->admin()->customSortColumns($query);

        $this->assertSame('meta_value', $query->get('orderby'), 'the ordering should still be applied');
        $this->assertStringContainsString($expectedMessage, $this->loggedErrors());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rebuildOrderings(): array
    {
        return [
            'start date' => ['start_date', 'Error updating start date sort meta values'],
            'status' => ['announcement_status', 'Error updating announcement status meta values'],
        ];
    }

    // ── admin notices ─────────────────────────────────────────────────

    /** @test */
    public function no_notices_are_printed_away_from_the_announcement_list(): void
    {
        $GLOBALS['pagenow'] = 'index.php';
        $GLOBALS['post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;

        $this->assertSame('', $this->capture(fn () => $this->admin()->displayAdminNotices()));
    }

    /** @test */
    public function no_notices_are_printed_for_another_post_type(): void
    {
        $GLOBALS['pagenow'] = 'edit.php';
        $GLOBALS['post_type'] = 'page';

        $this->assertSame('', $this->capture(fn () => $this->admin()->displayAdminNotices()));
    }

    /** @test */
    public function nothing_is_printed_when_every_count_is_zero(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([]);

        $this->assertSame('', $this->capture(fn () => $this->admin()->displayAdminNotices()));
    }

    /** @test */
    public function expired_announcements_raise_a_pluralised_warning(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement([self::END_DATE => self::offsetDate(-5)], 'publish', 401),
            $this->makeAnnouncement([self::END_DATE => self::offsetDate(-6)], 'publish', 402),
        ]);

        $html = $this->capture(fn () => $this->admin()->displayAdminNotices());

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('There are 2 expired announcements.', $html);
    }

    /** @test */
    public function a_single_expired_announcement_reads_in_the_singular(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement([self::END_DATE => self::offsetDate(-5)], 'publish', 401),
        ]);

        $this->assertStringContainsString(
            'There is 1 expired announcement.',
            $this->capture(fn () => $this->admin()->displayAdminNotices())
        );
    }

    /**
     * The review count comes from the posts table rather than the manager,
     * because a pending post is not something the front-end query returns.
     *
     * @test
     */
    public function announcements_awaiting_review_raise_their_own_notice(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([]);
        WpState::$queryPosts = [
            WpState::addPost(501, [
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_status' => 'pending',
            ]),
        ];

        $html = $this->capture(fn () => $this->admin()->displayAdminNotices());

        $this->assertStringContainsString('There is 1 announcement awaiting review.', $html);
        // The review notice is tinted apart from the expiry warning.
        $this->assertStringContainsString('#f56e28', $html);
    }

    /** @test */
    public function announcements_not_yet_due_raise_an_informational_notice(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement([self::START_DISPLAY => self::offsetDate(5)], 'publish', 601),
        ]);

        $html = $this->capture(fn () => $this->admin()->displayAdminNotices());

        $this->assertStringContainsString('notice-info', $html);
        $this->assertStringContainsString('There is 1 pending announcement.', $html);
    }

    /** @test */
    public function a_hidden_announcement_is_not_counted_as_pending(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement(
                [self::HIDE => true, self::START_DISPLAY => self::offsetDate(5)],
                'publish',
                601
            ),
        ]);

        $this->assertSame('', $this->capture(fn () => $this->admin()->displayAdminNotices()));
    }

    /**
     * Both manager-backed counts fail closed at zero and log, so a broken
     * repository leaves the list table usable rather than fatal.
     *
     * @test
     */
    public function a_failure_counting_announcements_is_logged_and_reported_as_zero(): void
    {
        $this->onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')
            ->andThrow(new Exception('the repository is unavailable'));

        $this->assertSame('', $this->capture(fn () => $this->admin()->displayAdminNotices()));

        $logged = $this->loggedErrors();
        $this->assertStringContainsString('Error counting expired announcements', $logged);
        $this->assertStringContainsString('Error counting pending announcements', $logged);
        $this->assertStringContainsString('the repository is unavailable', $logged);
    }

    // ── saves ─────────────────────────────────────────────────────────

    /** @test */
    public function saving_a_published_announcement_records_its_status_and_sort_keys(): void
    {
        WpState::addPost(701, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => 'publish',
        ]);
        $this->setFields([
            self::END_DATE => self::offsetDate(10),
            self::START_DISPLAY => '05/03/2026',
        ], 701);

        $this->admin()->updateStatusOnSave(
            701,
            new WP_Post(['ID' => 701, 'post_status' => 'publish']),
            true
        );

        $this->assertSame('active', WpState::$postMeta[701]['_announcement_status_sort']);
        $this->assertSame('2026-03-05', WpState::$postMeta[701]['_announcement_start_date_sort']);
    }

    /** @test */
    public function saving_a_draft_records_nothing(): void
    {
        $this->admin()->updateStatusOnSave(
            701,
            new WP_Post(['ID' => 701, 'post_status' => 'draft']),
            true
        );

        $this->assertSame([], WpState::$postMeta);
    }

    /** @test */
    public function a_revision_save_is_ignored(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(702);

        $this->admin()->updateStatusOnSave(
            701,
            new WP_Post(['ID' => 701, 'post_status' => 'publish']),
            true
        );

        $this->assertSame([], WpState::$postMeta);
    }

    /**
     * ACF saves fire for every post type, so the handler has to filter on type
     * itself — save_post_announcement does that for it, acf/save_post does not.
     *
     * @test
     */
    public function an_acf_save_on_another_post_type_is_ignored(): void
    {
        WpState::addPost(801, ['post_type' => 'page']);

        $this->admin()->updateStatusOnAcfSave(801);

        $this->assertSame([], WpState::$postMeta);
    }

    /** @test */
    public function an_acf_save_records_the_status_and_sort_keys(): void
    {
        WpState::addPost(801, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => 'publish',
        ]);
        $this->setFields([self::END_DATE => self::offsetDate(-3)], 801);

        $this->admin()->updateStatusOnAcfSave(801);

        $this->assertSame('expired', WpState::$postMeta[801]['_announcement_status_sort']);
        $this->assertSame('9999-99-99', WpState::$postMeta[801]['_announcement_start_date_sort']);
    }

    /** @test */
    public function an_acf_save_on_a_revision_is_ignored(): void
    {
        WpState::addPost(801, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => 'publish',
        ]);
        Functions\when('wp_is_post_revision')->justReturn(802);

        $this->admin()->updateStatusOnAcfSave(801);

        $this->assertSame([], WpState::$postMeta);
    }

    /**
     * The column callback swallows failures so one bad row cannot blank the
     * whole list table.
     *
     * @test
     */
    public function a_failure_rendering_a_column_is_logged_rather_than_thrown(): void
    {
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value, mixed $prev = ''): bool {
                throw new Exception('the meta table is unavailable');
            }
        );
        $this->setFields([self::START_DISPLAY => '05/03/2026']);

        $this->assertSame('', $this->column('announcement_start_date'));
        $this->assertStringContainsString('Error displaying column content', $this->loggedErrors());
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** Render one list-table column for the announcement under test. */
    private function column(string $column, int $postId = self::POST_ID): string
    {
        return $this->capture(
            fn () => $this->admin()->displayCustomColumnContent($column, $postId)
        );
    }

    private function sortMeta(string $key, int $postId = self::POST_ID): string
    {
        return (string) (WpState::$postMeta[$postId][$key] ?? '');
    }

    /**
     * Seed published announcements that get_posts() will return, each with its
     * own ACF field values.
     *
     * @param array<int, array<string, mixed>> $announcements Post id => fields
     */
    private function seedAnnouncements(array $announcements): void
    {
        foreach ($announcements as $postId => $fields) {
            WpState::$queryPosts[] = WpState::addPost($postId, [
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_status' => 'publish',
            ]);
            $this->setFields($fields, $postId);
        }
    }

    private function onAnnouncementList(): void
    {
        $GLOBALS['pagenow'] = 'edit.php';
        $GLOBALS['post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
    }

    /** Everything Plugin's logger recorded, as one searchable string. */
    private function loggedErrors(): string
    {
        return implode("\n", array_map(
            static fn (array $entry): string => (string) ($entry[2] ?? ''),
            array_filter(WpState::$logs, static fn (array $entry): bool => ($entry[1] ?? '') === 'error')
        ));
    }
}
