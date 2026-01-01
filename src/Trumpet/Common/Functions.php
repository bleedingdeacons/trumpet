<?php

declare(strict_types=1);

namespace Trumpet\Common;

/**
 * Common utility functions
 */
class Functions
{
    /**
     * Create mailto link
     *
     * @param string $address Email address
     * @param string $subject Email subject
     * @return string
     */
    public static function emailTo(string $address, string $subject = ''): string
    {
        if (!empty($subject)) {
            $address = $address . '?subject=' . $subject;
        }

        return 'mailto:' . $address;
    }

    /**
     * Create tel link
     *
     * @param string $number Phone number
     * @return string
     */
    public static function phoneTo(string $number): string
    {
        return 'tel:' . $number;
    }

    /**
     * Create anchor link
     *
     * @param string $href Link URL
     * @param string $class CSS class
     * @param string $text Link text
     * @return string
     */
    public static function linkTo(string $href, string $class, string $text = ''): string
    {
        return '<a target="_blank" rel="noreferrer noopener" class="' . esc_attr($class) . '" href="' . esc_attr($href) . '">' . esc_html($text) . '</a>';
    }

    /**
     * Create email anchor link
     *
     * @param string $address Email address
     * @param string $subject Email subject
     * @param string $class CSS class
     * @param string $text Link text
     * @return string
     */
    public static function createEmailAnchor(string $address, string $subject, string $class, string $text): string
    {
        $address = self::emailTo($address, $subject);
        return self::linkTo($address, $class, $text);
    }
}
