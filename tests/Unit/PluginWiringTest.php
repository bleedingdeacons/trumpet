<?php

declare(strict_types=1);

namespace Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Plugin;
use Unity\Core\Interfaces\Cache;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Testing\Doubles\FakeContainer;

/*
 * Covers the Plugin bootstrap: registering Trumpet's services into Unity's
 * container, resolving the tracker/manager, the getContainer() guard, and the
 * deactivation cleanup. registerTrumpetMenu()/render*Page() are admin/output
 * glue and are only registered here, not invoked.
 */

covers(Plugin::class);

function resetWiredPluginStatics(): void
{
    $ref = new ReflectionClass(Plugin::class);
    foreach (['container' => null, 'initialized' => false] as $prop => $value) {
        if ($ref->hasProperty($prop)) {
            $ref->getProperty($prop)->setValue(null, $value);
        }
    }
}

function wiringContainer(): FakeContainer
{
    return new FakeContainer([
        Cache::class => Mockery::mock(Cache::class)->shouldIgnoreMissing(),
        MeetingRepository::class => Mockery::mock(MeetingRepository::class),
    ]);
}

beforeEach(function () {
    resetWiredPluginStatics();
    // parent::setUp() clears WpState, including the cron schedule;
    // is_admin() defaults to true there, and these tests want it off.
    WpState::$isAdmin = false;
});

afterEach(function () {
    resetWiredPluginStatics();
    Mockery::close();
});

// Plugin overrides the trait's default channel derivation. With a real
// wp_log() the resolution memoises in a static that nothing resets between
// tests, so whichever call logs first does the resolving — clear it here
// so the override actually runs where it is being asserted on.
it('logs through its own channel', function () {
    (new ReflectionClass(Plugin::class))->getProperty('loggerChannel')->setValue(null, null);

    $channel = Plugin::log();

    expect($channel)->not->toBeNull()
        ->and($channel->channel)->toBe('trumpet');
});

it('registers services and resolves the tracker and manager on init', function () {
    $container = wiringContainer();
    Plugin::init($container);

    expect(Plugin::getContainer())->toBe($container)
        ->and($container->get(AnnouncementRepositoryInterface::class))->toBeInstanceOf(AnnouncementRepositoryInterface::class)
        ->and($container->get(AnnouncementChangeTracker::class))->toBeInstanceOf(AnnouncementChangeTracker::class)
        ->and($container->get(AnnouncementManager::class))->toBeInstanceOf(AnnouncementManager::class);
});

it('is idempotent on init', function () {
    $container = wiringContainer();
    Plugin::init($container);
    Plugin::init(wiringContainer()); // second call ignored

    expect(Plugin::getContainer())->toBe($container);
});

it('throws from getContainer before init', function () {
    Plugin::getContainer();
})->throws(RuntimeException::class);

it('clears caches, drops tables and removes capabilities on deactivate', function () {
    $cache = Mockery::mock(Cache::class);
    $cache->shouldReceive('delete')->once();

    // And nothing else: deactivating Trumpet must not empty the object
    // cache for everything else on the site. Mockery fails an unexpected
    // call, so this expectation is the assertion.

    $container = new FakeContainer([Cache::class => $cache]);
    (new ReflectionClass(Plugin::class))->getProperty('container')->setValue(null, $container);

    // A scheduled event to unschedule, and a role whose caps get stripped.
    WpState::$cron['announcement_cleanup_task'] = 12345;

    // wp-mocks' get_role() hands back a plain object describing the role;
    // this test needs one that records remove_cap(), so it is overridden
    // for the duration of the test.
    $role = Mockery::mock();
    $role->shouldReceive('remove_cap')->atLeast()->once();
    Functions\when('get_role')->justReturn($role);

    // $wpdb: report the table exists so the DROP path runs.
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($q, $t) => $t);
    $wpdb->shouldReceive('get_var')->andReturnUsing(fn ($t) => $t);
    $wpdb->shouldReceive('query');
    $GLOBALS['wpdb'] = $wpdb;

    Plugin::deactivate();
});

it('swallows the error on deactivate when not initialised', function () {
    // Container null → the guard throws, the catch logs, nothing escapes.
    expect(fn () => Plugin::deactivate())->not->toThrow(\Throwable::class);
});
