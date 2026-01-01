<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

/**
 * Class Meeting
 *
 * Implementation of MeetingInterface.
 */
class Meeting implements MeetingInterface
{
    private int $id;
    private string $name;
    private string $slug;
    private string $location;
    private string $url;
    private int $day;
    private string $dayofweek;
    private string $time;
    private string $end_time;
    private array $types;
    private string $state;
    private bool $online;
    private array $contacts;
    private array $meta;
    private string $onlineLink;
    private string $onlineNotes;

    /**
     * Constructor.
     *
     * @param int $id Meeting ID
     * @param string $name Meeting name
     * @param string $slug Meeting slug
     * @param string $location Meeting location
     * @param string $url Meeting URL
     * @param int $day Meeting day
     * @param string $dayofweek Day of the week
     * @param string $time Meeting time
     * @param string $end_time Meeting end time
     * @param array $types Meeting types
     * @param string $state Meeting state
     * @param bool $online Whether meeting is online
     * @param array $contacts Array of Contact objects
     * @param array $meta Meta data
     * @param string $onlineLink Online meeting link
     * @param string $onlineNotes Online meeting notes
     */
    public function __construct(
        int $id,
        string $name,
        string $slug,
        string $location,
        string $url,
        int $day,
        string $dayofweek,
        string $time,
        string $end_time,
        array $types,
        string $state,
        bool $online,
        array $contacts = [],
        array $meta = [],
        string $onlineLink = '',
        string $onlineNotes = ''
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->location = $location;
        $this->url = $url;
        $this->day = $day;
        $this->dayofweek = $dayofweek;
        $this->time = $time;
        $this->end_time = $end_time;
        $this->types = $types;
        $this->state = $state;
        $this->online = $online;
        $this->contacts = $contacts;
        $this->meta = $meta;
        $this->onlineLink = $onlineLink;
        $this->onlineNotes = $onlineNotes;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocation(): string
    {
        return $this->location;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * {@inheritdoc}
     */
    public function getDay(): int
    {
        return $this->day;
    }

    /**
     * {@inheritdoc}
     */
    public function getDayOfWeek(): string
    {
        return $this->dayofweek;
    }

    /**
     * {@inheritdoc}
     */
    public function getTime(): string
    {
        return $this->time;
    }

    /**
     * {@inheritdoc}
     */
    public function getEndTime(): string
    {
        return $this->end_time;
    }

    /**
     * {@inheritdoc}
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * {@inheritdoc}
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function isOnline(): bool
    {
        return $this->online;
    }

    /**
     * {@inheritdoc}
     */
    public function getContacts(): array
    {
        return $this->contacts;
    }

    /**
     * {@inheritdoc}
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * {@inheritdoc}
     */
    public function getOnlineLink(): string
    {
        return $this->onlineLink;
    }

    /**
     * {@inheritdoc}
     */
    public function getOnlineNotes(): string
    {
        return $this->onlineNotes;
    }
}
