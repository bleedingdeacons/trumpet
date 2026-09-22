<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Trumpet\Admin\TrumpetSettings;
use Trumpet\Config\TrumpetConfig;

/*
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
 */

covers(TrumpetSettings::class);

beforeEach(function () {
    /** @var array<string, array{group: string, args: array<string, mixed>}> */
    $this->registeredSettings = [];

    /** @var array<int, array<string, mixed>> */
    $this->sections = [];

    /** @var array<int, array<string, mixed>> */
    $this->fields = [];

    // Stub the Settings API.
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

    $this->settings = new TrumpetSettings();
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('hooks the menu and the settings registration in the constructor', function () {
        $this->assertActionAdded('admin_menu', false, 'the settings page should be added to the menu');
        $this->assertActionAdded('admin_init', false, 'the settings should be registered on admin_init');
    });

    // The page hangs off Trumpet's own top-level menu rather than
    // Settings → …, so it sits with the announcements it configures.
    it('adds the settings page under the Trumpet menu', function () {
        $this->settings->addSettingsPage();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0])->toBe([
                'type' => 'submenu',
                'parent' => 'trumpet',
                'slug' => TrumpetConfig::SETTINGS_PAGE,
                'title' => 'Settings',
                'cap' => 'manage_options',
            ]);
    });

    // The registered default is what WordPress hands back on a fresh install,
    // and preserving data is the safe side of that choice.
    it('registers the uninstall setting defaulting to preserve data', function () {
        $this->settings->initializeSettings();

        expect($this->registeredSettings)->toHaveKey(TrumpetConfig::OPTION_NAME);
        $setting = $this->registeredSettings[TrumpetConfig::OPTION_NAME];

        expect($setting['group'])->toBe(TrumpetConfig::OPTION_GROUP)
            ->and($setting['args']['type'])->toBe('array')
            ->and($setting['args']['default'])->toBe(['preserve_data' => true]);
    });

    it('adds the uninstall section and its field to the settings page', function () {
        $this->settings->initializeSettings();

        expect($this->sections)->toHaveCount(1)
            ->and($this->sections[0]['id'])->toBe('uninstall_section')
            ->and($this->sections[0]['page'])->toBe(TrumpetConfig::SETTINGS_PAGE);

        expect($this->fields)->toHaveCount(1)
            ->and($this->fields[0]['id'])->toBe('preserve_data')
            ->and($this->fields[0]['page'])->toBe(TrumpetConfig::SETTINGS_PAGE)
            ->and($this->fields[0]['section'])->toBe('uninstall_section');
    });
});

// ── the screen ────────────────────────────────────────────────────
describe('the screen', function () {
    // The capability is re-checked on the screen itself rather than trusted to
    // the menu having hidden it.
    it('renders nothing without the capability', function () {
        WpState::$userCan = false;

        expect(captureOutput(fn () => $this->settings->renderSettingsPage()))->toBe('');
    });

    it('renders a settings form posting to options.php', function () {
        $html = captureOutput(fn () => $this->settings->renderSettingsPage());

        expect($html)
            ->toContain('Trumpet Settings')
            ->toContain('action="options.php"')
            ->toContain('value="' . TrumpetConfig::OPTION_GROUP . '"')
            ->toContain('sections for ' . TrumpetConfig::SETTINGS_PAGE)
            ->toContain('<button type="submit">Save Settings</button>')
            // The info box explaining the default sits below the form.
            ->toContain('Data Preservation')
            ->toContain('preserved when uninstalling');
    });

    it('explains what the setting governs in the section description', function () {
        expect(captureOutput(fn () => $this->settings->renderUninstallSection()))
            ->toContain('Configure how the plugin should behave when uninstalled.');
    });
});

// ── the checkbox ──────────────────────────────────────────────────
describe('the checkbox', function () {
    // With nothing stored yet the box has to render ticked, or the first save
    // from a fresh install would switch data preservation off.
    it('is ticked when nothing has been stored', function () {
        $html = captureOutput(fn () => $this->settings->renderPreserveDataField());

        expect($html)
            ->toContain('checked="checked"')
            ->toContain('name="' . TrumpetConfig::OPTION_NAME . '[preserve_data]"');
    });

    it('is ticked when preservation is switched on', function () {
        WpState::$options[TrumpetConfig::OPTION_NAME] = ['preserve_data' => true];

        expect(captureOutput(fn () => $this->settings->renderPreserveDataField()))
            ->toContain('checked="checked"');
    });

    // An unticked checkbox is absent from the POST, so it is stored as a
    // falsey value rather than removed — and has to render unticked.
    it('is clear when preservation is switched off', function () {
        WpState::$options[TrumpetConfig::OPTION_NAME] = ['preserve_data' => false];

        $html = captureOutput(fn () => $this->settings->renderPreserveDataField());

        expect($html)
            ->not->toContain('checked=')
            ->toContain('type="checkbox"');
    });
});

// ── the value the uninstaller reads ───────────────────────────────
describe('the value the uninstaller reads', function () {
    it('defaults to preserving data', function () {
        expect(TrumpetSettings::getUninstallSettings())->toBe(['preserve_data' => true]);
    });

    it('reports what was stored', function () {
        WpState::$options[TrumpetConfig::OPTION_NAME] = ['preserve_data' => false];

        expect(TrumpetSettings::getUninstallSettings())->toBe(['preserve_data' => false]);
    });
});
