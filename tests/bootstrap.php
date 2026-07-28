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
    // Defaults to false; a test sets trumpet_test_user_can to exercise the
    // admin edit-link branch of renderSingleAnnouncement().
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return $GLOBALS['trumpet_test_user_can'] ?? false;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(mixed $post = null, string $context = 'display'): ?string
    {
        return $GLOBALS['trumpet_test_edit_link'] ?? null;
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    // Defaults to 0 (no thumbnail); a test sets trumpet_test_thumb_id to
    // exercise the featured-image branch.
    function get_post_thumbnail_id(mixed $post = null): int
    {
        return $GLOBALS['trumpet_test_thumb_id'] ?? 0;
    }
}

if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src(int $id, mixed $size = 'thumbnail'): array|false
    {
        return $GLOBALS['trumpet_test_image_src'] ?? false;
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

// ── Cache + logger stubs ────────────────────────────────────────────
// WordPressCache maps onto wp_cache_*; the store is in-memory here. The
// HasLogger trait resolves via wp_log() (null → safe no-op) and derives a
// channel name with sanitize_key().
$GLOBALS['trumpet_test_cache'] = [];
if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '')
    {
        return $GLOBALS['trumpet_test_cache'][$group][$key] ?? false;
    }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, $value, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['trumpet_test_cache'][$group][$key] = $value;
        return true;
    }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['trumpet_test_cache'][$group][$key]);
        return true;
    }
}
if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        $GLOBALS['trumpet_test_cache'] = [];
        return true;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}
if (!function_exists('wp_log')) {
    function wp_log(string $channel): ?object
    {
        return null;
    }
}

// ── Unity sibling autoloader ────────────────────────────────────────
// Trumpet builds on Unity's interfaces (Container, Cache, MeetingRepository,
// …). CI checks Unity out as a sibling; load its real interfaces from there so
// the container-wiring tests resolve the same contracts WordPress loads.
$trumpetUnitySrc = dirname(__DIR__, 2) . '/unity/src';
if (is_dir($trumpetUnitySrc)) {
    spl_autoload_register(static function (string $class) use ($trumpetUnitySrc): void {
        if (!str_starts_with($class, 'Unity\\')) {
            return;
        }
        $file = $trumpetUnitySrc . '/' . str_replace('\\', '/', substr($class, strlen('Unity\\'))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// ── Plugin bootstrap / deactivation stubs ───────────────────────────
if (!defined('TRUMPET_PLUGIN_FILE')) {
    define('TRUMPET_PLUGIN_FILE', dirname(__DIR__) . '/trumpet.php');
}
if (!defined('TRUMPET_VERSION')) {
    define('TRUMPET_VERSION', '0.0.0-test');
}
if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return (bool) ($GLOBALS['trumpet_test_is_admin'] ?? false);
    }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, $callback): void
    {
    }
}
if (!function_exists('add_menu_page')) {
    function add_menu_page(...$args): string
    {
        return 'toplevel_page_trumpet';
    }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page(...$args)
    {
        return 'trumpet_page';
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return $GLOBALS['trumpet_test_cron'][$hook] ?? false;
    }
}
if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
    {
        return true;
    }
}
if (!function_exists('get_role')) {
    function get_role(string $role)
    {
        return $GLOBALS['trumpet_test_roles'][$role] ?? null;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        return true;
    }
}
if (!function_exists('esc_sql')) {
    function esc_sql($data)
    {
        return $data;
    }
}
if (!function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return $text;
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url): string
    {
        return (string) $url;
    }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'http://example.test/wp-content/plugins/trumpet/';
    }
}

// ── Post / query stubs for AnnouncementRepository ───────────────────
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $message = '') {}
        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($post = null)
    {
        if (array_key_exists('trumpet_test_post_type', $GLOBALS)) {
            return $GLOBALS['trumpet_test_post_type'];
        }
        return 'announcement';
    }
}
if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        return $GLOBALS['trumpet_test_get_posts'] ?? [];
    }
}
if (!function_exists('get_post')) {
    function get_post($id = null)
    {
        return $GLOBALS['trumpet_test_get_post'] ?? null;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false)
    {
        if (isset($GLOBALS['trumpet_test_insert_error'])) {
            return new \WP_Error((string) $GLOBALS['trumpet_test_insert_error']);
        }
        return (int) ($GLOBALS['trumpet_test_insert_id'] ?? 1);
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post(array $postarr, bool $wp_error = false)
    {
        if (isset($GLOBALS['trumpet_test_update_error'])) {
            return new \WP_Error((string) $GLOBALS['trumpet_test_update_error']);
        }
        return (int) ($postarr['ID'] ?? 1);
    }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $postid, bool $force_delete = false)
    {
        return $GLOBALS['trumpet_test_delete_result'] ?? true;
    }
}
if (!function_exists('update_field')) {
    function update_field($selector, $value, $postId = false): bool
    {
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
    }
}

// ── AnnouncementManager rendering stubs ─────────────────────────────
if (!defined('TRUMPET_PLUGIN_URL')) {
    define('TRUMPET_PLUGIN_URL', 'http://example.test/wp-content/plugins/trumpet/');
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $args = []): void
    {
    }
}
if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string $src = '', array $deps = [], $ver = false, $args = []): bool
    {
        return true;
    }
}
if (!function_exists('wp_register_style')) {
    function wp_register_style(string $handle, $src, array $deps = [], $ver = false, string $media = 'all')
    {
        return true;
    }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void
    {
    }
}
// renderSingleAnnouncement calls $wp_embed->autoembed()/run_shortcode().
if (!isset($GLOBALS['wp_embed'])) {
    $GLOBALS['wp_embed'] = new class {
        public function autoembed(string $content): string
        {
            return $content;
        }
        public function run_shortcode(string $content): string
        {
            return $content;
        }
    };
}
