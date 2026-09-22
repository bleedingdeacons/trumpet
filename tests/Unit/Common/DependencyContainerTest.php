<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use RuntimeException;
use Trumpet\Common\DependencyContainer;

/*
 * Tests for Trumpet's own container.
 *
 * This is distinct from Unity's container: Trumpet registers its services into
 * Unity's, but keeps this one for its internal wiring. The behaviour that
 * matters is that factories resolve lazily and exactly once — services holding
 * caches or repositories would misbehave subtly if a second instance appeared.
 */

beforeEach(function () {
    $this->container = new DependencyContainer();
});

it('resolves a registered factory', function () {
    $service = new \stdClass();
    $this->container->register('svc', static fn (): object => $service);

    expect($this->container->get('svc'))->toBe($service);
});

it('throws naming the missing service', function () {
    $this->container->get('absent.service');
})->throws(RuntimeException::class, 'Service not found: absent.service');

it('returns the same instance on every call', function () {
    $this->container->register('svc', static fn (): object => new \stdClass());

    expect($this->container->get('svc'))->toBe(
        $this->container->get('svc'),
        'The container must cache resolved services, not rebuild them per call.'
    );
});

it('does not run a factory until the service is requested', function () {
    $runs = 0;
    $this->container->register('svc', static function () use (&$runs): object {
        $runs++;

        return new \stdClass();
    });

    expect($runs)->toBe(0, 'Registering must not resolve.');

    $this->container->get('svc');
    $this->container->get('svc');

    expect($runs)->toBe(1, 'The factory must run exactly once.');
});

it('passes the container to the factory so services can depend on each other', function () {
    $this->container->register('dependency', static fn (): string => 'inner');
    $this->container->register(
        'consumer',
        static fn (DependencyContainer $c): string => 'wraps:' . $c->get('dependency')
    );

    expect($this->container->get('consumer'))->toBe('wraps:inner');
});

it('lets a later registration replace an earlier one', function () {
    $this->container->register('svc', static fn (): string => 'first');
    $this->container->register('svc', static fn (): string => 'second');

    expect($this->container->get('svc'))->toBe('second');
});
