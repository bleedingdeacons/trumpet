<?php

declare(strict_types=1);

namespace Trumpet\Meetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Contact
 *
 * Represents a single contact.
 */
class Contact
{
    private string $name;
    private string $email;
    private string $phone;

    /**
     * Constructor.
     *
     * @param string $name The contact's name.
     * @param string $email The contact's email address.
     * @param string $phone The contact's phone number.
     */
    public function __construct(string $name = '', string $email = '', string $phone = '')
    {
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
    }

    /**
     * Get contact name.
     *
     * @return string Contact name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get contact email.
     *
     * @return string Contact email.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Get contact phone.
     *
     * @return string Contact phone.
     */
    public function getPhone(): string
    {
        return $this->phone;
    }
}
