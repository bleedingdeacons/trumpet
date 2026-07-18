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

// ── Enough of WordPress to drive AnnouncementManager::renderSingleAnnouncement ──
// These exist so the map render gate can be tested against real output rather
// than inferred. Escaping is not modelled; no test asserts on it.

if (!function_exists('add_shortcode')) {
    // AnnouncementManager registers its shortcodes and hooks in the
    // constructor, so these have to exist before one can be built at all.
    function add_shortcode(string $tag, callable $callback): void
    {
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return true;
    }
}

if (!function_exists('get_post_timestamp')) {
    function get_post_timestamp(mixed $post = null, string $field = 'date'): int|false
    {
        return 1_767_225_600; // 2026-01-01 00:00:00 UTC, fixed so output is stable
    }
}

if (!function_exists('esc_html')) {
    function esc_html(mixed $text): string
    {
        return is_string($text) ? htmlspecialchars($text, ENT_QUOTES) : '';
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(mixed $url): string
    {
        return is_string($url) ? $url : '';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return false;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(mixed $post = null, string $context = 'display'): ?string
    {
        return null;
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(mixed $post = null): int
    {
        return 0;
    }
}

if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src(int $id, mixed $size = 'thumbnail'): array|false
    {
        return false;
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content, bool $ignoreHtml = false): string
    {
        return $content;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
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
