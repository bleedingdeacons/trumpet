<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use ReflectionClass;
use Trumpet\Admin\TrumpetAdmin;
use Trumpet\Admin\TrumpetSettings;
use Trumpet\Plugin;
use Unity\Core\Interfaces\Cache;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Testing\Doubles\FakeContainer;

/*
 * Covers the admin-context paths of the Plugin bootstrap: the is_admin() branch
 * of init() (menu hook + admin/settings resolution) and the standalone
 * render*Page callbacks.
 */

covers(Plugin::class);

function resetAdminPluginStatics(): void
{
    $ref = new ReflectionClass(Plugin::class);
    foreach (['container' => null, 'initialized' => false] as $prop => $value) {
        if ($ref->hasProperty($prop)) {
            $ref->getProperty($prop)->setValue(null, $value);
        }
    }
}

beforeEach(function () {
    resetAdminPluginStatics();
    $GLOBALS['trumpet_test_is_admin'] = true;
});

afterEach(function () {
    resetAdminPluginStatics();
    $GLOBALS['trumpet_test_is_admin'] = false;
    Mockery::close();
});

it('registers the menu and resolves the admin services when init runs in admin', function () {
    // Preseed the admin services so init() resolves them without invoking
    // their real (hook-registering) constructors.
    $container = new FakeContainer([
        Cache::class => Mockery::mock(Cache::class)->shouldIgnoreMissing(),
        MeetingRepository::class => Mockery::mock(MeetingRepository::class),
        TrumpetAdmin::class => Mockery::mock(TrumpetAdmin::class),
        TrumpetSettings::class => Mockery::mock(TrumpetSettings::class),
    ]);

    Plugin::init($container);

    expect(Plugin::getContainer())->toBe($container);
});

it('wires the pages and submenus in registerTrumpetMenu', function () {
    // The add_menu_page/add_submenu_page/add_action stubs are no-ops, so
    // the pages are only registered, never rendered. Exercising the wiring
    // must complete without emitting any warnings.
    expect(fn () => Plugin::registerTrumpetMenu())->not->toThrow(\Throwable::class);
});

it('renders nothing from renderMenuPage', function () {
    expect(captureOutput(fn () => Plugin::renderMenuPage()))->toBe('');
});

it('emits the redirect markup from renderHelpPage', function () {
    $html = captureOutput(fn () => Plugin::renderHelpPage());

    expect($html)
        ->toContain('Trumpet Help')
        ->toContain('window.open');
});
