<?php

declare(strict_types=1);

namespace Trumpet\Announcement;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface AnnouncementRepositoryInterface
 *
 * Defines the contract for announcement repository implementations
 */
interface AnnouncementRepositoryInterface
{
    /**
     * Find all announcements
     *
     * @return Announcement[]
     */
    public function findAll(): array;

    /**
     * Find announcement by ID
     *
     * @param int $id Announcement ID
     * @return Announcement|null
     */
    public function findById(int $id): ?Announcement;

    /**
     * Find all active announcements
     *
     * @return Announcement[]
     */
    public function findActive(): array;

    /**
     * Save a new announcement
     *
     * @param Announcement $announcement Announcement to save
     * @return bool
     */
    public function save(Announcement $announcement): bool;

    /**
     * Delete an announcement
     *
     * @param int $id Announcement ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Update an existing announcement
     *
     * @param Announcement $announcement Announcement to update
     * @return bool
     */
    public function update(Announcement $announcement): bool;

    /**
     * Clear the cache
     *
     * @return void
     */
    public function clearCache(): void;

    /**
     * Check if an announcement has been changed
     *
     * @param Announcement $original The original announcement
     * @param Announcement $updated The updated announcement
     * @return bool True if the announcement has changed, false otherwise
     */
    public function hasAnnouncementChanged(Announcement $original, Announcement $updated): bool;
}
