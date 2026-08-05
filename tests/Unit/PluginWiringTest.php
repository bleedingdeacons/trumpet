<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Tests\TestCase;
use ReflectionClass;
use RuntimeException;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Plugin;
use Unity\Core\Interfaces\Cache;
use Unity\Core\Interfaces\Container;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Testing\Doubles\FakeContainer;

/**
 * Covers the Plugin bootstrap: registering Trumpet's services into Unity's
 * container, resolving the tracker/manager, the getContainer() guard, and the
 * deactivation cleanup. registerTrumpetMenu()/render*Page() are admin/output
 * glue and are only registered here, not invoked.
 *
 * @covers \Trumpet\Plugin
 */
class PluginWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatics();
        // parent::setUp() clears WpState, including the cron schedule;
        // is_admin() defaults to true there, and these tests want it off.
        WpState::$isAdmin = false;
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Plugin overrides the trait's default channel derivation. With a real
     * wp_log() the resolution memoises in a static that nothing resets between
     * tests, so whichever call logs first does the resolving — clear it here
     * so the override actually runs where it is being asserted on.
     *
     * @test
     */
    public function it_logs_through_its_own_channel(): void
    {
        (new ReflectionClass(Plugin::class))->getProperty('loggerChannel')->setValue(null, null);

        $channel = Plugin::log();

        $this->assertNotNull($channel);
        $this->assertSame('trumpet', $channel->channel);
    }

    private function container(): FakeContainer
    {
        return new FakeContainer([
            Cache::class => Mockery::mock(Cache::class)->shouldIgnoreMissing(),
            MeetingRepository::class => Mockery::mock(MeetingRepository::class),
        ]);
    }

    /** @test */
    public function init_registers_services_and_resolves_the_tracker_and_manager(): void
    {
        $container = $this->container();
        Plugin::init($container);

        $this->assertSame($container, Plugin::getContainer());
        $this->assertInstanceOf(AnnouncementRepositoryInterface::class, $container->get(AnnouncementRepositoryInterface::class));
        $this->assertInstanceOf(AnnouncementChangeTracker::class, $container->get(AnnouncementChangeTracker::class));
        $this->assertInstanceOf(AnnouncementManager::class, $container->get(AnnouncementManager::class));
    }

    /** @test */
    public function init_is_idempotent(): void
    {
        $container = $this->container();
        Plugin::init($container);
        Plugin::init($this->container()); // second call ignored

        $this->assertSame($container, Plugin::getContainer());
    }

    /** @test */
    public function get_container_throws_before_init(): void
    {
        $this->expectException(RuntimeException::class);
        Plugin::getContainer();
    }

    /** @test */
    public function deactivate_clears_caches_drops_tables_and_removes_capabilities(): void
    {
        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('delete')->once();
        $cache->shouldReceive('flush')->once();

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
        $this->assertTrue(true);
    }

    /** @test */
    public function deactivate_swallows_the_error_when_not_initialised(): void
    {
        // Container null → the guard throws, the catch logs, nothing escapes.
        Plugin::deactivate();
        $this->assertTrue(true);
    }

    private function resetStatics(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        foreach (['container' => null, 'initialized' => false] as $prop => $value) {
            if ($ref->hasProperty($prop)) {
                $ref->getProperty($prop)->setValue(null, $value);
            }
        }
    }
}
