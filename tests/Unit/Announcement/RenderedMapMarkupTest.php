<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Mockery;
use ReflectionMethod;
use Tests\TestCase;
use Trumpet\Announcement\Announcement;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Unity\Meetings\Interfaces\MeetingRepository;

/*
 * Drives the real renderer and asserts on the markup it produces.
 *
 * MapRenderGateTest pins the property that makes the gate safe; this one
 * checks the gate is actually wired in, by rendering an announcement and
 * looking for the map markup. Without it, a test suite could stay green while
 * the manager still called getShowMap().
 *
 * renderSingleAnnouncement() is private and needs a WordPress runtime, so it
 * is reached by reflection with the surrounding functions stubbed in
 * tests/bootstrap.php.
 */

function renderMapMarkup(Announcement $announcement): string
{
    $manager = new AnnouncementManager(
        Mockery::mock(AnnouncementRepositoryInterface::class),
        Mockery::mock(MeetingRepository::class)
    );

    // No setAccessible() call: private methods have been reflectively
    // invocable without it since PHP 8.1, and PHP 8.5 deprecates it.
    $method = new ReflectionMethod($manager, 'renderSingleAnnouncement');

    return (string) $method->invoke($manager, $announcement);
}

beforeEach(function () {
    // renderSingleAnnouncement() runs the body through $wp_embed.
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
});

afterEach(function () {
    unset($GLOBALS['wp_embed']);
    Mockery::close();
});

it('renders the map for real coordinates', function () {
    $html = renderMapMarkup($this->makeAnnouncement([
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278', 'address' => 'London'],
    ]));

    expect($html)
        ->toContain('acf-map')
        ->toContain('data-lat="51.5074"')
        ->toContain('data-lng="-0.1278"');
});

// The behaviour this change exists for. Previously the map was gated on
// getShowMap() alone, so this rendered a marker carrying empty
// coordinates for the front end to choke on.
it('renders no map when the coordinates are blank', function () {
    $html = renderMapMarkup($this->makeAnnouncement([
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => [],
    ]));

    expect($html)
        ->not->toContain('acf-map')
        ->not->toContain('data-lat=""');
});

it('renders no map for null island', function () {
    $html = renderMapMarkup($this->makeAnnouncement([
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => ['lat' => '0', 'lng' => '0'],
    ]));

    expect($html)->not->toContain('acf-map');
});

it('renders no map when the map is switched off', function () {
    $html = renderMapMarkup($this->makeAnnouncement([
        TestCase::SHOW_MAP => false,
        TestCase::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278'],
    ]));

    expect($html)->not->toContain('acf-map');
});

// A meeting on the meridian keeps its map — the case that made "both
// coordinates zero" the right rule rather than "either".
it('renders the map on the Greenwich meridian', function () {
    $html = renderMapMarkup($this->makeAnnouncement([
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => ['lat' => '51.4779', 'lng' => '0', 'address' => 'Greenwich'],
    ]));

    expect($html)
        ->toContain('acf-map')
        ->toContain('data-lng="0"');
});
