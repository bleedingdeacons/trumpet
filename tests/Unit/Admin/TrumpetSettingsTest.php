<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use Brain\Monkey\Functions;
use BleedingDeacons\WpMocks\WpState;
use Tests\TestCase;
use Trumpet\Admin\TrumpetSettings;
use Trumpet\Config\TrumpetConfig;

/**
 * Tests for the Trumpet settings screen.
 *
 * One setting, one consequence: whether uninstalling the plugin takes the
 * announcement posts with it. That makes the screen worth covering despite
 * being mostly markup — the default has to be "preserve", and it has to survive
 * a settings save that does not send the checkbox.
 *
 * The Settings API is not part of what wp-mocks stubs (register_setting,
 * add_settings_section, add_settings_field, settings_fields,
 * do_settings_sections, submit_button and get_admin_page_title are all absent),
 * so those are defined per-test through Brain Monkey. The ones whose arguments
 * matter record them rather than returning a fixed value.
 *
 * @covers \Trumpet\Admin\TrumpetSettings
 */
class TrumpetSettingsTest extends TestCase
{
    /** @var array<string, array{group: string, args: array<string, mixed>}> */
    private array $registeredSettings = [];

    /** @var array<int, array<string, mixed>> */
    private array $sections = [];

    /** @var array<int, array<string, mixed>> */
    private array $fields = [];

    private TrumpetSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registeredSettings = [];
        $this->sections = [];
        $this->fields = [];

        $this->stubSettingsApi();

        $this->settings = new TrumpetSettings();
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

    private function stubSettingsApi(): void
    {
        Functions\when('register_setting')->alias(
            function (string $group, string $name, mixed $args = []): void {
                $this->registeredSettings[$name] = ['group' => $group, 'args' => (array) $args];
            }
        );

        Functions\when('add_settings_section')->alias(
            function (string $id, string $title, mixed $callback, string $page): void {
                $this->sections[] = compact('id', 'title', 'callback', 'page');
            }
        );

        Functions\when('add_settings_field')->alias(
            function (string $id, string $title, mixed $callback, string $page, string $section = 'default'): void {
                $this->fields[] = compact('id', 'title', 'callback', 'page', 'section');
            }
        );

        Functions\when('get_admin_page_title')->justReturn('Trumpet Settings');
        Functions\when('settings_fields')->alias(static function (string $group): void {
            echo '<input type="hidden" name="option_page" value="' . $group . '">';
        });
        Functions\when('do_settings_sections')->alias(static function (string $page): void {
            echo '<!-- sections for ' . $page . ' -->';
        });
        Functions\when('submit_button')->alias(static function (string $text = 'Save Changes'): void {
            echo '<button type="submit">' . $text . '</button>';
        });
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function the_constructor_hooks_the_menu_and_the_settings_registration(): void
    {
        $this->assertActionAdded('admin_menu', false, 'the settings page should be added to the menu');
        $this->assertActionAdded('admin_init', false, 'the settings should be registered on admin_init');
    }

    /**
     * The page hangs off Trumpet's own top-level menu rather than
     * Settings → …, so it sits with the announcements it configures.
     *
     * @test
     */
    public function the_settings_page_is_added_under_the_trumpet_menu(): void
    {
        $this->settings->addSettingsPage();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame([
            'type' => 'submenu',
            'parent' => 'trumpet',
            'slug' => TrumpetConfig::SETTINGS_PAGE,
            'title' => 'Settings',
            'cap' => 'manage_options',
        ], WpState::$menus[0]);
    }

    /**
     * The registered default is what WordPress hands back on a fresh install,
     * and preserving data is the safe side of that choice.
     *
     * @test
     */
    public function the_uninstall_setting_is_registered_defaulting_to_preserve_data(): void
    {
        $this->settings->initializeSettings();

        $this->assertArrayHasKey(TrumpetConfig::OPTION_NAME, $this->registeredSettings);
        $setting = $this->registeredSettings[TrumpetConfig::OPTION_NAME];

        $this->assertSame(TrumpetConfig::OPTION_GROUP, $setting['group']);
        $this->assertSame('array', $setting['args']['type']);
        $this->assertSame(['preserve_data' => true], $setting['args']['default']);
    }

    /** @test */
    public function the_uninstall_section_and_its_field_are_added_to_the_settings_page(): void
    {
        $this->settings->initializeSettings();

        $this->assertCount(1, $this->sections);
        $this->assertSame('uninstall_section', $this->sections[0]['id']);
        $this->assertSame(TrumpetConfig::SETTINGS_PAGE, $this->sections[0]['page']);

        $this->assertCount(1, $this->fields);
        $this->assertSame('preserve_data', $this->fields[0]['id']);
        $this->assertSame(TrumpetConfig::SETTINGS_PAGE, $this->fields[0]['page']);
        $this->assertSame('uninstall_section', $this->fields[0]['section']);
    }

    // ── the screen ────────────────────────────────────────────────────

    /**
     * The capability is re-checked on the screen itself rather than trusted to
     * the menu having hidden it.
     *
     * @test
     */
    public function the_screen_renders_nothing_without_the_capability(): void
    {
        WpState::$userCan = false;

        $this->assertSame('', $this->capture(fn () => $this->settings->renderSettingsPage()));
    }

    /** @test */
    public function the_screen_renders_a_settings_form_posting_to_options_php(): void
    {
        $html = $this->capture(fn () => $this->settings->renderSettingsPage());

        $this->assertStringContainsString('Trumpet Settings', $html);
        $this->assertStringContainsString('action="options.php"', $html);
        $this->assertStringContainsString('value="' . TrumpetConfig::OPTION_GROUP . '"', $html);
        $this->assertStringContainsString('sections for ' . TrumpetConfig::SETTINGS_PAGE, $html);
        $this->assertStringContainsString('<button type="submit">Save Settings</button>', $html);
        // The info box explaining the default sits below the form.
        $this->assertStringContainsString('Data Preservation', $html);
        $this->assertStringContainsString('preserved when uninstalling', $html);
    }

    /** @test */
    public function the_section_description_explains_what_the_setting_governs(): void
    {
        $this->assertStringContainsString(
            'Configure how the plugin should behave when uninstalled.',
            $this->capture(fn () => $this->settings->renderUninstallSection())
        );
    }

    // ── the checkbox ──────────────────────────────────────────────────

    /**
     * With nothing stored yet the box has to render ticked, or the first save
     * from a fresh install would switch data preservation off.
     *
     * @test
     */
    public function the_checkbox_is_ticked_when_nothing_has_been_stored(): void
    {
        $html = $this->capture(fn () => $this->settings->renderPreserveDataField());

        $this->assertStringContainsString('checked="checked"', $html);
        $this->assertStringContainsString(
            'name="' . TrumpetConfig::OPTION_NAME . '[preserve_data]"',
            $html
        );
    }

    /** @test */
    public function the_checkbox_is_ticked_when_preservation_is_switched_on(): void
    {
        WpState::$options[TrumpetConfig::OPTION_NAME] = ['preserve_data' => true];

        $this->assertStringContainsString(
            'checked="checked"',
            $this->capture(fn () => $this->settings->renderPreserveDataField())
        );
    }

    /**
     * An unticked checkbox is absent from the POST, so it is stored as a
     * falsey value rather than removed — and has to render unticked.
     *
     * @test
     */
    public function the_checkbox_is_clear_when_preservation_is_switched_off(): void
    {
        WpState::$options[TrumpetConfig::OPTION_NAME] = ['preserve_data' => false];

        $html = $this->capture(fn () => $this->settings->renderPreserveDataField());

        $this->assertStringNotContainsString('checked=', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    // ── the value the uninstaller reads ───────────────────────────────

    /** @test */
    public function the_uninstall_settings_default_to_preserving_data(): void
    {
        $this->assertSame(['preserve_data' => true], TrumpetSettings::getUninstallSettings());
    }

    /** @test */
    public function the_uninstall_settings_report_what_was_stored(): void
    {
        WpState::$options[TrumpetConfig::OPTION_NAME] = ['preserve_data' => false];

        $this->assertSame(['preserve_data' => false], TrumpetSettings::getUninstallSettings());
    }
}
