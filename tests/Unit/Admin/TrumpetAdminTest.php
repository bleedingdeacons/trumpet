<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
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

/*
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
 */

covers(TrumpetAdmin::class);

// An offset from today as d/m/Y — the format Trumpet's date fields use.
function adminOffsetDate(int $days): string
{
    return (new \DateTimeImmutable())->modify(sprintf('%+d days', $days))->format('d/m/Y');
}

function adminSortMeta(string $key, int $postId = TestCase::POST_ID): string
{
    return (string) (WpState::$postMeta[$postId][$key] ?? '');
}

function onAnnouncementList(): void
{
    $GLOBALS['pagenow'] = 'edit.php';
    $GLOBALS['post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;
}

// Everything Plugin's logger recorded, as one searchable string.
function adminLoggedErrors(): string
{
    return implode("\n", array_map(
        static fn (array $entry): string => (string) ($entry[2] ?? ''),
        array_filter(WpState::$logs, static fn (array $entry): bool => ($entry[1] ?? '') === 'error')
    ));
}

beforeEach(function () {
    /** @var AnnouncementManager&Mockery\MockInterface */
    $this->manager = Mockery::mock(AnnouncementManager::class);

    /** @var AnnouncementRepositoryInterface&Mockery\MockInterface */
    $this->repository = Mockery::mock(AnnouncementRepositoryInterface::class);

    $this->adminInstance = null;

    // Built on demand rather than in beforeEach(), because the constructor is
    // itself under test: the not-in-admin case needs to be the first
    // construction in its test, with an empty hook store behind it.
    $this->admin = fn (): TrumpetAdmin
        => $this->adminInstance ??= new TrumpetAdmin($this->manager, $this->repository);

    // Render one list-table column for the announcement under test.
    $this->column = fn (string $column, int $postId = TestCase::POST_ID): string => captureOutput(
        fn () => ($this->admin)()->displayCustomColumnContent($column, $postId)
    );

    // Seed published announcements that get_posts() will return, each with its
    // own ACF field values.
    //
    // @param array<int, array<string, mixed>> $announcements Post id => fields
    $this->seedAnnouncements = function (array $announcements): void {
        foreach ($announcements as $postId => $fields) {
            WpState::$queryPosts[] = WpState::addPost($postId, [
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_status' => 'publish',
            ]);
            $this->setFields($fields, $postId);
        }
    };
});

afterEach(function () {
    unset($GLOBALS['pagenow'], $GLOBALS['post_type']);
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers every admin hook in the constructor', function () {
        ($this->admin)();

        foreach (
            [
            'manage_announcement_posts_columns',
            'manage_edit-announcement_sortable_columns',
            'post_row_actions',
            ] as $filter
        ) {
            $this->assertFilterAdded($filter, false, 'expected ' . $filter . ' to be hooked');
        }

        foreach (
            [
            'manage_announcement_posts_custom_column',
            'pre_get_posts',
            'admin_notices',
            'admin_head',
            'save_post_announcement',
            'acf/save_post',
            ] as $action
        ) {
            $this->assertActionAdded($action, false, 'expected ' . $action . ' to be hooked');
        }
    });

    // The constructor bails before assigning its dependencies, so a front-end
    // request must not leave any of these hooks behind.
    it('hooks nothing outside the admin', function () {
        WpState::$isAdmin = false;

        new TrumpetAdmin($this->manager, $this->repository);

        $this->assertFilterNotAdded('post_row_actions');
        $this->assertActionNotAdded('admin_notices');
        $this->assertActionNotAdded('acf/save_post');
    });
});

// ── list-table columns ────────────────────────────────────────────
describe('list-table columns', function () {
    // The date column is pulled out and re-appended so the announcement
    // columns sit in front of it rather than after it.
    it('inserts the announcement columns ahead of the date column', function () {
        $columns = TrumpetAdmin::addCustomColumns([
            'cb' => '<input type="checkbox" />',
            'title' => 'Title',
            'date' => 'Date',
        ]);

        expect(array_keys($columns))->toBe([
            'cb',
            'title',
            'announcement_status',
            'announcement_start_date',
            'announcement_end_date',
            'date',
        ])
            ->and($columns['date'])->toBe('Date', 'the original date label should survive the move');
    });

    it('adds only the announcement columns to a column set with no date column', function () {
        $columns = TrumpetAdmin::addCustomColumns(['title' => 'Title']);

        expect(array_keys($columns))->toBe([
            'title',
            'announcement_status',
            'announcement_start_date',
            'announcement_end_date',
        ]);
    });

    it('registers the announcement columns as sortable', function () {
        expect(($this->admin)()->sortableCustomColumns(['title' => 'title']))->toBe([
            'title' => 'title',
            'announcement_end_date' => 'end_date',
            'announcement_status' => 'announcement_status',
            'announcement_start_date' => 'start_date',
        ]);
    });
});

// ── quick edit ────────────────────────────────────────────────────
describe('quick edit', function () {
    it('removes quick edit from an announcement row', function () {
        $actions = ($this->admin)()->removeQuickEdit(
            ['edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit', 'trash' => 'Trash'],
            new WP_Post(['ID' => 1, 'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE])
        );

        expect($actions)->toBe(['edit' => 'Edit', 'trash' => 'Trash']);
    });

    it('leaves quick edit on other post types', function () {
        $actions = ['edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit'];

        expect(($this->admin)()->removeQuickEdit($actions, new WP_Post(['ID' => 1, 'post_type' => 'page'])))
            ->toBe($actions);
    });
});

// ── admin styles ──────────────────────────────────────────────────
it('prints the status colours and column widths into admin head', function () {
    $css = captureOutput(fn () => ($this->admin)()->addAdminStyles());

    foreach (
        [
        '.status-active',
        '.status-expired',
        '.status-hidden',
        '.status-pending',
        '.status-review',
        '.status-invalid',
        '.status-no-date',
        '.column-announcement_status',
        '.column-announcement_end_date',
        ] as $selector
    ) {
        expect($css)->toContain($selector);
    }
});

// ── column content ────────────────────────────────────────────────
describe('column content', function () {
    it('prints the stored end date in the end date column', function () {
        $this->setFields([TestCase::END_DATE => '31/12/2026']);

        expect(($this->column)('announcement_end_date'))->toBe('31/12/2026');
    });

    it('prints an empty end date column for an announcement with no end date', function () {
        expect(($this->column)('announcement_end_date'))->toBe('');
    });

    // A start date still in the future is shown as the date itself, so an
    // editor can see when the announcement will appear.
    it('prints a future start date and records its sort key', function () {
        $future = adminOffsetDate(10);
        $this->setFields([TestCase::START_DISPLAY => $future]);

        expect(($this->column)('announcement_start_date'))->toBe($future)
            ->and(adminSortMeta('_announcement_start_date_sort'))
            ->toBe((string) DateTime::createFromFormat('d/m/Y', $future)?->format('Y-m-d'));
    });

    it('prints Started for a start date already reached', function () {
        $this->setFields([TestCase::START_DISPLAY => adminOffsetDate(-1)]);

        expect(($this->column)('announcement_start_date'))->toBe('Started');
    });

    // An unparseable value is treated as "already started" rather than shown
    // back to the editor, and sorts to the end of the list.
    it('prints Started for an unparseable start date and sorts it last', function () {
        $this->setFields([TestCase::START_DISPLAY => 'whenever']);

        expect(($this->column)('announcement_start_date'))->toBe('Started')
            ->and(adminSortMeta('_announcement_start_date_sort'))->toBe('9999-99-99');
    });

    it('prints a dash for an empty start date and sorts it last', function () {
        $html = ($this->column)('announcement_start_date');

        expect($html)
            ->toContain('status-no-date')
            ->toContain('—')
            ->and(adminSortMeta('_announcement_start_date_sort'))->toBe('9999-99-99');
    });

    it('prints nothing for an unrecognised column', function () {
        expect(($this->column)('title'))->toBe('');
    });
});

// ── status column ─────────────────────────────────────────────────
describe('status column', function () {
    // The status shown in the list table and the meta value it is sorted by are
    // computed together, so they are asserted together.
    it('reports and records the announcement status', function (
        array $fields,
        string $postStatus,
        string $status,
        string $sortValue,
        string $cssClass
    ) {
        WpState::addPost(TestCase::POST_ID, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => $postStatus,
        ]);
        $this->setFields($fields);

        $html = ($this->column)('announcement_status');

        expect($html)->toBe(sprintf('<span class="%s">%s</span>', $cssClass, $status))
            ->and(adminSortMeta('_announcement_status_sort'))->toBe($sortValue);
    })->with([
        'awaiting review' => [
            [TestCase::END_DATE => adminOffsetDate(10)],
            'pending',
            'Review',
            'review',
            'status-review',
        ],
        // The hide flag outranks the dates, but not the review status.
        'hidden' => [
            [TestCase::HIDE => true, TestCase::END_DATE => adminOffsetDate(10)],
            'publish',
            'Hidden',
            'hidden',
            'status-hidden',
        ],
        'not due yet' => [
            [TestCase::START_DISPLAY => adminOffsetDate(5), TestCase::END_DATE => adminOffsetDate(10)],
            'publish',
            'Pending',
            'pending',
            'status-pending',
        ],
        'no end date' => [
            [TestCase::START_DISPLAY => adminOffsetDate(-5)],
            'publish',
            'No End Date',
            'no_end_date',
            'status-no-date',
        ],
        'end date that will not parse' => [
            [TestCase::END_DATE => 'sometime'],
            'publish',
            'Invalid Date',
            'invalid',
            'status-invalid',
        ],
        'end date in the past' => [
            [TestCase::END_DATE => adminOffsetDate(-1)],
            'publish',
            'Expired',
            'expired',
            'status-expired',
        ],
        'end date in the future' => [
            [TestCase::END_DATE => adminOffsetDate(1)],
            'publish',
            'Active',
            'active',
            'status-active',
        ],
        // Same day counts as still running, not expired.
        'end date today' => [
            [TestCase::END_DATE => adminOffsetDate(0)],
            'publish',
            'Active',
            'active',
            'status-active',
        ],
    ]);

    // A post id with nothing behind it — a row deleted between the query and
    // the render — falls through the pending check to the date logic rather
    // than erroring on a null post.
    it('still yields a status for a missing post', function () {
        $this->setFields([TestCase::END_DATE => adminOffsetDate(3)]);

        expect(($this->column)('announcement_status'))->toBe('<span class="status-active">Active</span>');
    });
});

// ── sorting ───────────────────────────────────────────────────────
describe('sorting', function () {
    it('leaves sorting alone outside the admin', function () {
        $admin = ($this->admin)();
        WpState::$isAdmin = false;

        $query = new WP_Query(['orderby' => 'end_date']);
        $admin->customSortColumns($query);

        expect($query->get('orderby'))->toBe('end_date', 'orderby should be untouched')
            ->and($query->get('meta_key'))->toBe('');
    });

    it('leaves sorting alone for a secondary query', function () {
        $query = new WP_Query(['orderby' => 'end_date']);
        $query->isMainQuery = false;

        ($this->admin)()->customSortColumns($query);

        expect($query->get('orderby'))->toBe('end_date')
            ->and($query->get('meta_key'))->toBe('');
    });

    it('orders by end date on the ACF end date field', function () {
        $query = new WP_Query(['orderby' => 'end_date']);

        ($this->admin)()->customSortColumns($query);

        expect($query->get('meta_key'))->toBe(TrumpetConfig::END_DATE_FIELD)
            ->and($query->get('orderby'))->toBe('meta_value');
    });

    // The start date is stored as d/m/Y, which does not sort, so ordering goes
    // through a Y-m-d shadow meta key instead.
    it('orders by start date on the shadow sort key', function () {
        $query = new WP_Query(['orderby' => 'start_date']);

        ($this->admin)()->customSortColumns($query);

        expect($query->get('meta_key'))->toBe('_announcement_start_date_sort')
            ->and($query->get('orderby'))->toBe('meta_value')
            ->and(WpState::$postMeta)->toBe([], 'no screen means no rebuild');
    });

    // On the announcement list screen the shadow keys are rebuilt first, so a
    // row whose date was edited elsewhere still sorts correctly.
    it('rebuilds every shadow key when sorting by start date on the announcement screen', function () {
        ($this->seedAnnouncements)([
            201 => [TestCase::START_DISPLAY => '05/03/2026'],
            202 => [],
        ]);
        WpState::$screen = new WP_Screen(['id' => 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE]);

        ($this->admin)()->customSortColumns(new WP_Query(['orderby' => 'start_date']));

        expect(WpState::$postMeta[201]['_announcement_start_date_sort'])->toBe('2026-03-05')
            ->and(WpState::$postMeta[202]['_announcement_start_date_sort'])->toBe('9999-99-99');
    });

    it('orders by status on the status sort key', function () {
        $query = new WP_Query(['orderby' => 'announcement_status']);

        ($this->admin)()->customSortColumns($query);

        expect($query->get('meta_key'))->toBe('_announcement_status_sort')
            ->and($query->get('orderby'))->toBe('meta_value')
            ->and(WpState::$postMeta)->toBe([], 'no screen means no rebuild');
    });

    it('rebuilds every status key when sorting by status on the announcement screen', function () {
        ($this->seedAnnouncements)([
            301 => [TestCase::END_DATE => adminOffsetDate(5)],
            302 => [TestCase::HIDE => true],
        ]);
        WpState::$screen = new WP_Screen(['id' => 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE]);

        ($this->admin)()->customSortColumns(new WP_Query(['orderby' => 'announcement_status']));

        expect(WpState::$postMeta[301]['_announcement_status_sort'])->toBe('active')
            ->and(WpState::$postMeta[302]['_announcement_status_sort'])->toBe('hidden');
    });

    it('does not rebuild on another screen', function () {
        ($this->seedAnnouncements)([301 => [TestCase::END_DATE => adminOffsetDate(5)]]);
        WpState::$screen = new WP_Screen(['id' => 'edit-post']);

        ($this->admin)()->customSortColumns(new WP_Query(['orderby' => 'announcement_status']));

        expect(WpState::$postMeta)->toBe([]);
    });

    it('leaves an unrecognised orderby alone', function () {
        $query = new WP_Query(['orderby' => 'title']);

        ($this->admin)()->customSortColumns($query);

        expect($query->get('orderby'))->toBe('title')
            ->and($query->get('meta_key'))->toBe('');
    });

    // The rebuild loops swallow failures rather than breaking the list table,
    // and both report through Plugin's logger.
    it('logs a failed rebuild and leaves the list table working', function (
        string $orderby,
        string $expectedMessage
    ) {
        Functions\when('get_posts')->alias(static function (array $args = []): array {
            throw new Exception('the posts table is unavailable');
        });
        WpState::$screen = new WP_Screen(['id' => 'edit-' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE]);

        $query = new WP_Query(['orderby' => $orderby]);
        ($this->admin)()->customSortColumns($query);

        expect($query->get('orderby'))->toBe('meta_value', 'the ordering should still be applied')
            ->and(adminLoggedErrors())->toContain($expectedMessage);
    })->with([
        'start date' => ['start_date', 'Error updating start date sort meta values'],
        'status' => ['announcement_status', 'Error updating announcement status meta values'],
    ]);
});

// ── admin notices ─────────────────────────────────────────────────
describe('admin notices', function () {
    it('prints no notices away from the announcement list', function () {
        $GLOBALS['pagenow'] = 'index.php';
        $GLOBALS['post_type'] = TrumpetConfig::ANNOUNCEMENT_POST_TYPE;

        expect(captureOutput(fn () => ($this->admin)()->displayAdminNotices()))->toBe('');
    });

    it('prints no notices for another post type', function () {
        $GLOBALS['pagenow'] = 'edit.php';
        $GLOBALS['post_type'] = 'page';

        expect(captureOutput(fn () => ($this->admin)()->displayAdminNotices()))->toBe('');
    });

    it('prints nothing when every count is zero', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([]);

        expect(captureOutput(fn () => ($this->admin)()->displayAdminNotices()))->toBe('');
    });

    it('raises a pluralised warning for expired announcements', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement([TestCase::END_DATE => adminOffsetDate(-5)], 'publish', 401),
            $this->makeAnnouncement([TestCase::END_DATE => adminOffsetDate(-6)], 'publish', 402),
        ]);

        $html = captureOutput(fn () => ($this->admin)()->displayAdminNotices());

        expect($html)
            ->toContain('notice-warning')
            ->toContain('There are 2 expired announcements.');
    });

    it('reads in the singular for a single expired announcement', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement([TestCase::END_DATE => adminOffsetDate(-5)], 'publish', 401),
        ]);

        expect(captureOutput(fn () => ($this->admin)()->displayAdminNotices()))
            ->toContain('There is 1 expired announcement.');
    });

    // The review count comes from the posts table rather than the manager,
    // because a pending post is not something the front-end query returns.
    it('raises its own notice for announcements awaiting review', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([]);
        WpState::$queryPosts = [
            WpState::addPost(501, [
                'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
                'post_status' => 'pending',
            ]),
        ];

        $html = captureOutput(fn () => ($this->admin)()->displayAdminNotices());

        expect($html)
            ->toContain('There is 1 announcement awaiting review.')
            // The review notice is tinted apart from the expiry warning.
            ->toContain('#f56e28');
    });

    it('raises an informational notice for announcements not yet due', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement([TestCase::START_DISPLAY => adminOffsetDate(5)], 'publish', 601),
        ]);

        $html = captureOutput(fn () => ($this->admin)()->displayAdminNotices());

        expect($html)
            ->toContain('notice-info')
            ->toContain('There is 1 pending announcement.');
    });

    it('does not count a hidden announcement as pending', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')->andReturn([
            $this->makeAnnouncement(
                [TestCase::HIDE => true, TestCase::START_DISPLAY => adminOffsetDate(5)],
                'publish',
                601
            ),
        ]);

        expect(captureOutput(fn () => ($this->admin)()->displayAdminNotices()))->toBe('');
    });

    // Both manager-backed counts fail closed at zero and log, so a broken
    // repository leaves the list table usable rather than fatal.
    it('logs a failure counting announcements and reports it as zero', function () {
        onAnnouncementList();
        $this->manager->shouldReceive('getAnnouncements')
            ->andThrow(new Exception('the repository is unavailable'));

        expect(captureOutput(fn () => ($this->admin)()->displayAdminNotices()))->toBe('');

        expect(adminLoggedErrors())
            ->toContain('Error counting expired announcements')
            ->toContain('Error counting pending announcements')
            ->toContain('the repository is unavailable');
    });
});

// ── saves ─────────────────────────────────────────────────────────
describe('saves', function () {
    it('records the status and sort keys when a published announcement is saved', function () {
        WpState::addPost(701, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => 'publish',
        ]);
        $this->setFields([
            TestCase::END_DATE => adminOffsetDate(10),
            TestCase::START_DISPLAY => '05/03/2026',
        ], 701);

        ($this->admin)()->updateStatusOnSave(
            701,
            new WP_Post(['ID' => 701, 'post_status' => 'publish']),
            true
        );

        expect(WpState::$postMeta[701]['_announcement_status_sort'])->toBe('active')
            ->and(WpState::$postMeta[701]['_announcement_start_date_sort'])->toBe('2026-03-05');
    });

    it('records nothing when a draft is saved', function () {
        ($this->admin)()->updateStatusOnSave(
            701,
            new WP_Post(['ID' => 701, 'post_status' => 'draft']),
            true
        );

        expect(WpState::$postMeta)->toBe([]);
    });

    it('ignores a revision save', function () {
        Functions\when('wp_is_post_revision')->justReturn(702);

        ($this->admin)()->updateStatusOnSave(
            701,
            new WP_Post(['ID' => 701, 'post_status' => 'publish']),
            true
        );

        expect(WpState::$postMeta)->toBe([]);
    });

    // ACF saves fire for every post type, so the handler has to filter on type
    // itself — save_post_announcement does that for it, acf/save_post does not.
    it('ignores an ACF save on another post type', function () {
        WpState::addPost(801, ['post_type' => 'page']);

        ($this->admin)()->updateStatusOnAcfSave(801);

        expect(WpState::$postMeta)->toBe([]);
    });

    it('records the status and sort keys on an ACF save', function () {
        WpState::addPost(801, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => 'publish',
        ]);
        $this->setFields([TestCase::END_DATE => adminOffsetDate(-3)], 801);

        ($this->admin)()->updateStatusOnAcfSave(801);

        expect(WpState::$postMeta[801]['_announcement_status_sort'])->toBe('expired')
            ->and(WpState::$postMeta[801]['_announcement_start_date_sort'])->toBe('9999-99-99');
    });

    it('ignores an ACF save on a revision', function () {
        WpState::addPost(801, [
            'post_type' => TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'post_status' => 'publish',
        ]);
        Functions\when('wp_is_post_revision')->justReturn(802);

        ($this->admin)()->updateStatusOnAcfSave(801);

        expect(WpState::$postMeta)->toBe([]);
    });

    // The column callback swallows failures so one bad row cannot blank the
    // whole list table.
    it('logs a failure rendering a column rather than throwing', function () {
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value, mixed $prev = ''): bool {
                throw new Exception('the meta table is unavailable');
            }
        );
        $this->setFields([TestCase::START_DISPLAY => '05/03/2026']);

        expect(($this->column)('announcement_start_date'))->toBe('')
            ->and(adminLoggedErrors())->toContain('Error displaying column content');
    });
});
