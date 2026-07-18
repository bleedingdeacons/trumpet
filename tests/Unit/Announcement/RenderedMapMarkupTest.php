<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionMethod;
use Tests\TestCase;
use Trumpet\Announcement\Announcement;
use Trumpet\Announcement\AnnouncementManager;
use Trumpet\Announcement\AnnouncementRepositoryInterface;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
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
class RenderedMapMarkupTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_embed']);
        Mockery::close();

        parent::tearDown();
    }

    private function render(Announcement $announcement): string
    {
        $manager = new AnnouncementManager(
            Mockery::mock(AnnouncementRepositoryInterface::class),
            Mockery::mock(MeetingRepository::class)
        );

        $method = new ReflectionMethod($manager, 'renderSingleAnnouncement');
        $method->setAccessible(true);

        return (string) $method->invoke($manager, $announcement);
    }

    /**
     * @test
     */
    public function it_renders_the_map_for_real_coordinates(): void
    {
        $html = $this->render($this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278', 'address' => 'London'],
        ]));

        $this->assertStringContainsString('acf-map', $html);
        $this->assertStringContainsString('data-lat="51.5074"', $html);
        $this->assertStringContainsString('data-lng="-0.1278"', $html);
    }

    /**
     * The behaviour this change exists for. Previously the map was gated on
     * getShowMap() alone, so this rendered a marker carrying empty
     * coordinates for the front end to choke on.
     *
     * @test
     */
    public function it_renders_no_map_when_the_coordinates_are_blank(): void
    {
        $html = $this->render($this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => [],
        ]));

        $this->assertStringNotContainsString('acf-map', $html);
        $this->assertStringNotContainsString('data-lat=""', $html);
    }

    /**
     * @test
     */
    public function it_renders_no_map_for_null_island(): void
    {
        $html = $this->render($this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '0', 'lng' => '0'],
        ]));

        $this->assertStringNotContainsString('acf-map', $html);
    }

    /**
     * @test
     */
    public function it_renders_no_map_when_the_map_is_switched_off(): void
    {
        $html = $this->render($this->makeAnnouncement([
            self::SHOW_MAP => false,
            self::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278'],
        ]));

        $this->assertStringNotContainsString('acf-map', $html);
    }

    /**
     * A meeting on the meridian keeps its map — the case that made "both
     * coordinates zero" the right rule rather than "either".
     *
     * @test
     */
    public function it_renders_the_map_on_the_greenwich_meridian(): void
    {
        $html = $this->render($this->makeAnnouncement([
            self::SHOW_MAP => true,
            self::LOCATION => ['lat' => '51.4779', 'lng' => '0', 'address' => 'Greenwich'],
        ]));

        $this->assertStringContainsString('acf-map', $html);
        $this->assertStringContainsString('data-lng="0"', $html);
    }
}
