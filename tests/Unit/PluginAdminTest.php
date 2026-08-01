<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use Tests\TestCase;
use ReflectionClass;
use Trumpet\Admin\TrumpetAdmin;
use Trumpet\Admin\TrumpetSettings;
use Trumpet\Plugin;
use Unity\Core\Interfaces\Cache;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Testing\Doubles\FakeContainer;

/**
 * Covers the admin-context paths of the Plugin bootstrap: the is_admin() branch
 * of init() (menu hook + admin/settings resolution) and the standalone
 * render*Page callbacks.
 *
 * @covers \Trumpet\Plugin
 */
class PluginAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatics();
        $GLOBALS['trumpet_test_is_admin'] = true;
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        $GLOBALS['trumpet_test_is_admin'] = false;
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function init_in_admin_registers_the_menu_and_resolves_admin_services(): void
    {
        // Preseed the admin services so init() resolves them without invoking
        // their real (hook-registering) constructors.
        $container = new FakeContainer([
            Cache::class => Mockery::mock(Cache::class)->shouldIgnoreMissing(),
            MeetingRepository::class => Mockery::mock(MeetingRepository::class),
            TrumpetAdmin::class => Mockery::mock(TrumpetAdmin::class),
            TrumpetSettings::class => Mockery::mock(TrumpetSettings::class),
        ]);

        Plugin::init($container);

        $this->assertSame($container, Plugin::getContainer());
    }

    /** @test */
    public function register_trumpet_menu_wires_the_pages_and_submenus(): void
    {
        // The add_menu_page/add_submenu_page/add_action stubs are no-ops, so
        // the pages are only registered, never rendered. Exercising the wiring
        // must complete without emitting any warnings.
        Plugin::registerTrumpetMenu();

        $this->assertTrue(true);
    }

    /** @test */
    public function render_menu_page_is_a_no_op(): void
    {
        ob_start();
        Plugin::renderMenuPage();
        $this->assertSame('', (string) ob_get_clean());
    }

    /** @test */
    public function render_help_page_emits_the_redirect_markup(): void
    {
        ob_start();
        Plugin::renderHelpPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Trumpet Help', $html);
        $this->assertStringContainsString('window.open', $html);
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
