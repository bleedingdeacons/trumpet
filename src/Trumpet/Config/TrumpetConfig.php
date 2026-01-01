<?php

declare(strict_types=1);

namespace Trumpet\Config;

/**
 * Configuration class for Trumpet plugin
 */
final class TrumpetConfig
{
    public const ANNOUNCEMENTS_CACHE_KEY = 'trumpet_announcements';
    public const CACHE_DURATION = 3600; // 1 hour
    public const OPTION_PREFIX = 'announcement_';
    public const ANNOUNCEMENT_POST_TYPE = 'announcement';
    public const TITLE_FIELD = 'general-group_article-title';
    public const HIDE_FIELD = 'general-group_hide';
    public const END_DATE_FIELD = 'general-group_end-date';
    public const BODY_FIELD = 'announcement-body';
    public const LOCATION_FIELD = 'announcement-location_map';
    public const SHOW_MAP_FIELD = 'announcement-location_show-map';
    public const RELATED_MEETING_FIELD = 'related-meeting';
    public const ANNOUNCEMENT_FIELD_GROUP = 'group_6651ffcb828c2';
    public const OPTION_GROUP = 'trumpet_settings';
    public const OPTION_NAME = 'trumpet_uninstall_settings';
    public const SETTINGS_PAGE = 'trumpet-settings';
    public const START_DISPLAY_FIELD = 'general-group_start_display';

    private function __construct()
    {
    }
}
