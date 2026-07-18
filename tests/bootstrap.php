<?php

/**
 * PHPUnit bootstrap.
 *
 * Trumpet has no WP_Mock in require-dev, and pulling one in to cover a handful
 * of functions would be a heavier dependency than the thing it replaces. These
 * stubs are deliberately small and are only enough to construct an Announcement
 * outside WordPress: the date, status and location logic under test is pure
 * PHP, and stubbing the few WordPress calls around it keeps that logic testable
 * without a WordPress install.
 *
 * The stubs are intentionally *not* faithful reimplementations. sanitize_text_field
 * and wp_kses here strip far less than the real thing. Tests must therefore not
 * assert on sanitising behaviour — that would be testing these stubs, not
 * Trumpet. They assert on Trumpet's own logic instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Announcement.php (and its siblings) bail out unless ABSPATH is defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/**
 * Field values keyed by post id, consumed by the get_field() stub.
 *
 * Tests populate this through TestCase::setField()/setFields() rather than
 * touching it directly.
 *
 * @var array<int, array<string, mixed>>
 */
$GLOBALS['trumpet_test_fields'] = [];

/**
 * Post times keyed by post id, consumed by the get_the_time() stub.
 *
 * @var array<int, string>
 */
$GLOBALS['trumpet_test_post_times'] = [];

if (!class_exists('WP_Post')) {
    /**
     * Minimal stand-in for WordPress's WP_Post.
     *
     * Announcement only reads ->ID and ->post_status from it.
     */
    class WP_Post
    {
        public int $ID = 0;
        public string $post_status = 'publish';
        public string $post_title = '';

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data = [])
        {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

if (!function_exists('get_field')) {
    /**
     * ACF's get_field(). Returns whatever the test registered, else null.
     */
    function get_field(string $selector, int|string|false $postId = false): mixed
    {
        return $GLOBALS['trumpet_test_fields'][$postId][$selector] ?? null;
    }
}

if (!function_exists('get_the_time')) {
    /**
     * WordPress returns string|false here — false when the post cannot be
     * resolved. Announcement passes the result straight into parseDate(?string)
     * under strict_types, so a false would be a TypeError rather than a null
     * date; for a real WP_Post it returns a string, which is what this stub
     * defaults to. Tests wanting a specific post date register one.
     */
    function get_the_time(string $format = '', int|string|null $post = null): string|false
    {
        return $GLOBALS['trumpet_test_post_times'][$post] ?? '01/01/2026';
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Not the real implementation — see the file docblock. Enough to strip tags
     * and trim, which is all the tests need it to do.
     */
    function sanitize_text_field(mixed $str): string
    {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}

if (!function_exists('wp_kses')) {
    /**
     * Not the real implementation — see the file docblock. Passes content
     * through unchanged; the allow-list is not modelled.
     */
    function wp_kses(mixed $content, mixed $allowedHtml = [], mixed $allowedProtocols = []): string
    {
        return is_string($content) ? $content : '';
    }
}
