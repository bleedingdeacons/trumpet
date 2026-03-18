<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface MeetingRepositoryInterface
 *
 * Defines the contract for retrieving meetings.
 */
interface MeetingRepositoryInterface
{
    /**
     * Find all meetings.
     *
     * @param array $args Optional arguments to filter meetings.
     * @return array Array of MeetingInterface objects.
     */
    public function findAll(array $args = []): array;

    /**
     * Find a meeting by ID.
     *
     * @param int $id Meeting ID.
     * @return MeetingInterface|null Meeting object or null if not found.
     */
    public function find(int $id): ?MeetingInterface;

    /**
     * Clear the cache.
     *
     * @param int|null $id Optional meeting ID to clear specific cache entry.
     */
    public function clearCache(?int $id = null): void;
}
