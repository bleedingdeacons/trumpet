<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test in this suite reaches WordPress through bleedingdeacons/wp-mocks,
// so every one runs on Tests\TestCase, which wraps wp-mocks' TestCase: Brain
// Monkey's lifecycle, Mockery integration, a WpState reset between tests, and
// the announcement builders (makeAnnouncement(), setFields(), daysFromToday())
// and field-name shorthands (self::HIDE, self::END_DATE, ...) the tests use.
//
// None of Trumpet's tests ever extended plain PHPUnit, so there is no pure-PHP
// split to reproduce here, unlike Trusted or Scrutiny. The binding is still
// load-bearing: add_action() and add_filter() belong to Brain Monkey, which
// only defines them inside that TestCase's setUp(), so a new test file placed
// outside tests/Unit would find none of the WordPress stand-ins it expects.

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

/**
 * Runs $render inside an output buffer and returns what it printed.
 *
 * The admin screens and the shortcode renderer echo their markup, so this is
 * how their tests read it. The buffer is closed in a finally, so a render that
 * throws — wp_die() is a WpDieException under the shared stubs — cannot leave
 * it open and have PHPUnit flag the test as risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}
