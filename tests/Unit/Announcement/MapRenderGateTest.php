<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Tests\TestCase;

/*
 * The contract behind the map render gate.
 *
 * AnnouncementManager decides whether to emit the map markup, and it now asks
 * hasValidLocation() rather than getShowMap(). The renderer builds the marker
 * from data-lat / data-lng attributes, so an announcement with the map
 * switched on and no coordinates used to emit data-lat="" data-lng="" and
 * leave the front end to make what it could of that.
 *
 * The manager itself is not unit-testable without a WordPress runtime — it
 * reaches for $wp_embed, do_shortcode, post thumbnails and the_content — so
 * these tests pin the property that makes the substitution safe rather than
 * driving the renderer.
 */

// The invariant the swap depends on: hasValidLocation() must imply
// getShowMap(). If that ever stopped holding, moving the gate would start
// showing maps on announcements whose author had switched the map off.
it('only has a valid location when the map is switched on', function () {
    $locations = [
        'real coordinates' => ['lat' => '51.5074', 'lng' => '-0.1278'],
        'meridian' => ['lat' => '51.4779', 'lng' => '0'],
        'null island' => ['lat' => '0', 'lng' => '0'],
        'blank' => [],
        'empty strings' => ['lat' => '', 'lng' => ''],
        'non-numeric' => ['lat' => 'x', 'lng' => 'y'],
    ];

    foreach ([true, false] as $showMap) {
        foreach ($locations as $label => $location) {
            $announcement = $this->makeAnnouncement([
                TestCase::SHOW_MAP => $showMap,
                TestCase::LOCATION => $location,
            ]);

            if ($announcement->hasValidLocation()) {
                expect($announcement->getShowMap())
                    ->toBeTrue("hasValidLocation() was true with the map switched off ($label).");
            }
        }
    }
});

// The case the gate exists for: map on, nothing entered. Previously this
// rendered a marker with empty coordinates.
it('does not render an announcement with no coordinates', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => [],
    ]);

    expect($announcement->hasValidLocation())
        ->toBeFalse('The gate must close for an announcement with no coordinates.');
});

// ...and the case it must not break.
it('still renders an announcement with real coordinates', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::SHOW_MAP => true,
        TestCase::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278', 'address' => 'London'],
    ]);

    expect($announcement->hasValidLocation())->toBeTrue();
});

// Switching the map off still suppresses it, coordinates or not — the
// behaviour the old gate provided and this must not lose.
it('still suppresses the map when it is switched off', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::SHOW_MAP => false,
        TestCase::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278'],
    ]);

    expect($announcement->hasValidLocation())->toBeFalse();
});
