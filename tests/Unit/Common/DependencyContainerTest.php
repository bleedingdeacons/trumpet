<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trumpet\Common\DependencyContainer;

/**
 * Tests for Trumpet's own container.
 *
 * This is distinct from Unity's container: Trumpet registers its services into
 * Unity's, but keeps this one for its internal wiring. The behaviour that
 * matters is that factories resolve lazily and exactly once — services holding
 * caches or repositories would misbehave subtly if a second instance appeared.
 */
class DependencyContainerTest extends TestCase
{
    private DependencyContainer $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new DependencyContainer();
    }

    /**
     * @test
     */
    public function it_resolves_a_registered_factory(): void
    {
        $service = new \stdClass();
        $this->container->register('svc', static fn (): object => $service);

        $this->assertSame($service, $this->container->get('svc'));
    }

    /**
     * @test
     */
    public function it_throws_naming_the_missing_service(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service not found: absent.service');

        $this->container->get('absent.service');
    }

    /**
     * @test
     */
    public function it_returns_the_same_instance_on_every_call(): void
    {
        $this->container->register('svc', static fn (): object => new \stdClass());

        $this->assertSame(
            $this->container->get('svc'),
            $this->container->get('svc'),
            'The container must cache resolved services, not rebuild them per call.'
        );
    }

    /**
     * @test
     */
    public function it_does_not_run_a_factory_until_the_service_is_requested(): void
    {
        $runs = 0;
        $this->container->register('svc', static function () use (&$runs): object {
            $runs++;

            return new \stdClass();
        });

        $this->assertSame(0, $runs, 'Registering must not resolve.');

        $this->container->get('svc');
        $this->container->get('svc');

        $this->assertSame(1, $runs, 'The factory must run exactly once.');
    }

    /**
     * @test
     */
    public function the_factory_receives_the_container_so_services_can_depend_on_each_other(): void
    {
        $this->container->register('dependency', static fn (): string => 'inner');
        $this->container->register(
            'consumer',
            static fn (DependencyContainer $c): string => 'wraps:' . $c->get('dependency')
        );

        $this->assertSame('wraps:inner', $this->container->get('consumer'));
    }

    /**
     * @test
     */
    public function a_later_registration_replaces_an_earlier_one(): void
    {
        $this->container->register('svc', static fn (): string => 'first');
        $this->container->register('svc', static fn (): string => 'second');

        $this->assertSame('second', $this->container->get('svc'));
    }
}
