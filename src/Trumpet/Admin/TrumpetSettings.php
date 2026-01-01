<?php

declare(strict_types=1);

namespace Trumpet\Admin;

use Trumpet\Config\TrumpetConfig;

/**
 * Class TrumpetSettings
 * Handles plugin settings
 */
class TrumpetSettings
{
    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'initializeSettings']);
    }

    /**
     * Add settings page to admin menu
     */
    public function addSettingsPage(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . TrumpetConfig::ANNOUNCEMENT_POST_TYPE,
            'Trumpet Settings',
            'Settings',
            'manage_options',
            TrumpetConfig::SETTINGS_PAGE,
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Initialize settings with default to preserve data
     */
    public function initializeSettings(): void
    {
        register_setting(
            TrumpetConfig::OPTION_GROUP,
            TrumpetConfig::OPTION_NAME,
            [
                'type' => 'array',
                'default' => [
                    'preserve_data' => true,
                ]
            ]
        );

        add_settings_section(
            'uninstall_section',
            'Uninstall Settings',
            [$this, 'renderUninstallSection'],
            TrumpetConfig::SETTINGS_PAGE
        );

        add_settings_field(
            'preserve_data',
            'Data Preservation on Uninstall',
            [$this, 'renderPreserveDataField'],
            TrumpetConfig::SETTINGS_PAGE,
            'uninstall_section'
        );
    }

    /**
     * Render the info box at the bottom of the settings page
     */
    private function renderInfoBox(): void
    {
        ?>
        <div class="card" style="margin-top: 20px; padding: 15px;">
            <h3>Data Preservation</h3>
            <p>
                By default, your announcement posts are preserved when uninstalling the plugin.
            </p>
        </div>
        <?php
    }

    /**
     * Render the settings page
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields(TrumpetConfig::OPTION_GROUP);
                do_settings_sections(TrumpetConfig::SETTINGS_PAGE);
                submit_button('Save Settings');
                ?>
            </form>
            <?php $this->renderInfoBox(); ?>
        </div>
        <?php
    }

    /**
     * Render uninstall section description
     */
    public function renderUninstallSection(): void
    {
        echo '<p>Configure how the plugin should behave when uninstalled.</p>';
    }

    /**
     * Render preserve data field
     */
    public function renderPreserveDataField(): void
    {
        $options = get_option(TrumpetConfig::OPTION_NAME);
        $preserve_data = isset($options['preserve_data']) ? $options['preserve_data'] : true;
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr(TrumpetConfig::OPTION_NAME); ?>[preserve_data]"
                <?php checked($preserve_data); ?>>
            Keep announcement posts and custom post type when uninstalling the plugin
        </label>
        <p class="description">
            If checked, your announcement posts and data will be preserved when the plugin is uninstalled.
            If unchecked, all announcement posts and related data will be permanently deleted.
        </p>
        <?php
    }

    /**
     * Get uninstall settings
     *
     * @return array
     */
    public static function getUninstallSettings(): array
    {
        return get_option(TrumpetConfig::OPTION_NAME, ['preserve_data' => true]);
    }
}
