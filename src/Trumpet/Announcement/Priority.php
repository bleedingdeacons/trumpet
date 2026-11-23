<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * How prominently an announcement is listed.
 *
 * Read from the ACF `priority` field. High announcements (funeral notices,
 * for example) are pinned to the top of [list_announcements], low ones sink
 * to the bottom, and normal ones sit between them in their usual order.
 */
enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * Interpret the raw ACF field value.
     *
     * Accepts the plain value ('high'), any case or surrounding whitespace,
     * and the array an ACF select/radio returns when its return format is
     * "both" (['value' => 'high', 'label' => 'High']). Anything missing,
     * empty or unrecognised is Normal, so announcements saved before the
     * field existed keep their place.
     */
    public static function fromField(mixed $value): self
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if (!is_string($value)) {
            return self::Normal;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Normal;
    }

    /**
     * Sort rank: lower is listed first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }
}
