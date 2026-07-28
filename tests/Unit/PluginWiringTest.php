<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Trumpet\Announcement\AnnouncementChangeTracker;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Trumpet\Plugin;
use Unity\Core\Interfaces\Cache;
use Unity\Core\Interfaces\Container;
use Unity\Meetings\Interfaces\MeetingRepository;

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
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatics();
        $GLOBALS['trumpet_test_is_admin'] = false;
        $GLOBALS['trumpet_test_cron'] = [];
        $GLOBALS['trumpet_test_roles'] = [];
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        Mockery::close();
        parent::tearDown();
    }

    private function container(): TrumpetFakeContainer
    {
        return new TrumpetFakeContainer([
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

        $container = new TrumpetFakeContainer([Cache::class => $cache]);
        (new ReflectionClass(Plugin::class))->getProperty('container')->setValue(null, $container);

        // A scheduled event to unschedule, and a role whose caps get stripped.
        $GLOBALS['trumpet_test_cron']['announcement_cleanup_task'] = 12345;
        $role = Mockery::mock();
        $role->shouldReceive('remove_cap')->atLeast()->once();
        $GLOBALS['trumpet_test_roles']['administrator'] = $role;
        $GLOBALS['trumpet_test_roles']['editor'] = $role;

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

/** Minimal Unity container: presets + registered factories, resolved once. */
final class TrumpetFakeContainer implements Container
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances;

    /** @param array<string, mixed> $presets */
    public function __construct(array $presets = [])
    {
        $this->instances = $presets;
    }

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        throw new RuntimeException('No service registered for ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
