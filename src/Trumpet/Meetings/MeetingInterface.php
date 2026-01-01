<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

/**
 * Interface MeetingInterface
 *
 * Defines the contract for Meeting objects.
 */
interface MeetingInterface
{
    /**
     * Get meeting ID.
     *
     * @return int Meeting ID.
     */
    public function getId(): int;

    /**
     * Get meeting name.
     *
     * @return string Meeting name.
     */
    public function getName(): string;

    /**
     * Get meeting slug.
     *
     * @return string Meeting slug.
     */
    public function getSlug(): string;

    /**
     * Get meeting location.
     *
     * @return string Meeting location.
     */
    public function getLocation(): string;

    /**
     * Get meeting URL.
     *
     * @return string Meeting URL.
     */
    public function getUrl(): string;

    /**
     * Get meeting day.
     *
     * @return int Meeting day.
     */
    public function getDay(): int;

    /**
     * Get meeting day of week.
     *
     * @return string Day of week.
     */
    public function getDayOfWeek(): string;

    /**
     * Get meeting start time.
     *
     * @return string Meeting start time.
     */
    public function getTime(): string;

    /**
     * Get meeting end time.
     *
     * @return string Meeting end time.
     */
    public function getEndTime(): string;

    /**
     * Get meeting types.
     *
     * @return array Meeting types.
     */
    public function getTypes(): array;

    /**
     * Get meeting state.
     *
     * @return string Meeting state.
     */
    public function getState(): string;

    /**
     * Check if meeting is online.
     *
     * @return bool Whether meeting is online.
     */
    public function isOnline(): bool;

    /**
     * Get meeting contacts.
     *
     * @return array Array of Contact objects.
     */
    public function getContacts(): array;

    /**
     * Get all post meta data.
     *
     * @return array Post meta data.
     */
    public function getMeta(): array;

    /**
     * Get online meeting link.
     *
     * @return string Online meeting link.
     */
    public function getOnlineLink(): string;

    /**
     * Get online meeting notes.
     *
     * @return string Online meeting notes.
     */
    public function getOnlineNotes(): string;
}
