<?php

/**
 * PHPUnit bootstrap.
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything below that defines WordPress functions or classes of its own must
 * stay after the Bootstrap::load() call, not before it.
 *
 * Trumpet uses ACF for its announcement fields, so that group is loaded too,
 * and `sentinel` with it: HasLogger skips its whole resolution when wp_log()
 * is absent, and the old bootstrap defined a null-returning wp_log() for
 * exactly that reason. The shared stub does the same job and records what was
 * logged into WpState::$logs, where a test can assert on it.
 *
 * A note the old hand-rolled stubs carried, still true of the shared ones:
 * they are not faithful reimplementations. sanitize_text_field() and wp_kses()
 * strip far less than the real thing. Tests must therefore not assert on
 * sanitising behaviour — that would be testing the stubs, not Trumpet.
 */

declare(strict_types=1);

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::load(['wordpress', 'acf', 'sentinel']);

// Makes plugins_url()/plugin_dir_url() answer with Trumpet's own path.
WpState::$pluginSlug = 'trumpet';

// Announcement.php (and its siblings) bail out unless ABSPATH is defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('TRUMPET_PLUGIN_FILE')) {
    define('TRUMPET_PLUGIN_FILE', dirname(__DIR__) . '/trumpet.php');
}
if (!defined('TRUMPET_VERSION')) {
    define('TRUMPET_VERSION', '0.0.0-test');
}
if (!defined('TRUMPET_PLUGIN_URL')) {
    define('TRUMPET_PLUGIN_URL', 'http://example.test/wp-content/plugins/trumpet/');
}

// ── Unity sibling autoloader ────────────────────────────────────────
// Trumpet builds on Unity's interfaces (Container, Cache, MeetingRepository,
// …) and on the test doubles Unity ships at Unity\Testing\Doubles. CI checks
// Unity out as a sibling; load both from there so the container-wiring tests
// resolve the same contracts WordPress loads.
$trumpetUnitySrc = dirname(__DIR__, 2) . '/unity/src';
if (!is_dir($trumpetUnitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $trumpetUnitySrc . PHP_EOL
        . "Trumpet is built on Unity's interfaces and test doubles, so the Unity" . PHP_EOL
        . 'plugin must be checked out as a sibling directory for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($trumpetUnitySrc): void {
    if (!str_starts_with($class, 'Unity\\')) {
        return;
    }
    $file = $trumpetUnitySrc . '/' . str_replace('\\', '/', substr($class, strlen('Unity\\'))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// renderSingleAnnouncement() calls $wp_embed->autoembed()/run_shortcode().
// $wp_embed is a WordPress *global object*, not a function, so it is outside
// what the shared stubs cover.
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
