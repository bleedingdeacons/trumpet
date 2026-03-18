<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface MeetingFactoryInterface
 *
 * Defines the contract for meeting factory implementations.
 */
interface MeetingFactoryInterface
{
    /**
     * Create a Meeting object from source data.
     *
     * @param mixed $source Source data (e.g., array from TSML plugin)
     * @return MeetingInterface|null Meeting object or null on failure
     */
    public function createFromSource(mixed $source): ?MeetingInterface;
}
