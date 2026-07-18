<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Tests\TestCase;

/**
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
class MapRenderGateTest extends TestCase
{
    /**
     * The invariant the swap depends on: hasValidLocation() must imply
     * getShowMap(). If that ever stopped holding, moving the gate would start
     * showing maps on announcements whose author had switched the map off.
     *
     * @test
     */
    public function a_valid_location_always_implies_the_map_is_switched_on(): void
    {
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
                    self::SHOW_MAP => $showMap,
                    self::LOCATION => $location,
                ]);

                if ($announcement->hasValidLocation()) {
                    $this->assertTrue(
                        $announcement->getShowMap(),
                        "hasValidLocation() was true with the map switched off ($label)."
                    );
                }
            }
        }
    }

    /**
     * The case the gate exists for: map on, nothing entered. Previously this
     * rendered a marker with empty coordinates.
     *
     * @test
     */
    public function an_announcement_with_no_coordinates_is_not_rendered(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => [],
        ]);

        $this->assertFalse(
            $announcement->hasValidLocation(),
            'The gate must close for an announcement with no coordinates.'
        );
    }

    /**
     * ...and the case it must not break.
     *
     * @test
     */
    public function an_announcement_with_real_coordinates_is_still_rendered(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278', 'address' => 'London'],
        ]);

        $this->assertTrue($announcement->hasValidLocation());
    }

    /**
     * Switching the map off still suppresses it, coordinates or not — the
     * behaviour the old gate provided and this must not lose.
     *
     * @test
     */
    public function switching_the_map_off_still_suppresses_it(): void
    {
        $announcement = $this->makeAnnouncement([
            self::SHOW_MAP => false,
            self::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278'],
        ]);

        $this->assertFalse($announcement->hasValidLocation());
    }
}
