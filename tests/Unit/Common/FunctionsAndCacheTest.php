<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use Tests\TestCase;
use Trumpet\Common\Functions;
use Trumpet\Common\WordPressCache;
use Trumpet\Config\TrumpetConfig;

/**
 * Cover the small pure helpers: the link/anchor builders in Functions, the
 * wp_cache_* adapter, and that TrumpetConfig's constants are reachable.
 *
 * @covers \Trumpet\Common\Functions
 * @covers \Trumpet\Common\WordPressCache
 * @covers \Trumpet\Config\TrumpetConfig
 */
class FunctionsAndCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['trumpet_test_cache'] = [];
    }

    // ─── Functions ───────────────────────────────────────────────────

    public function testEmailToBuildsMailtoWithAndWithoutSubject(): void
    {
        $this->assertSame('mailto:a@example.com', Functions::emailTo('a@example.com'));
        $this->assertSame('mailto:a@example.com?subject=Hello', Functions::emailTo('a@example.com', 'Hello'));
    }

    public function testPhoneToBuildsTelLink(): void
    {
        $this->assertSame('tel:07700900000', Functions::phoneTo('07700900000'));
    }

    public function testLinkToBuildsAnAnchor(): void
    {
        $html = Functions::linkTo('https://example.com', 'btn', 'Click');
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('class="btn"', $html);
        $this->assertStringContainsString('>Click</a>', $html);
        $this->assertStringContainsString('rel="noreferrer noopener"', $html);
    }

    public function testCreateEmailAnchorCombinesMailtoAndAnchor(): void
    {
        $html = Functions::createEmailAnchor('a@example.com', 'Hi', 'btn', 'Email us');
        $this->assertStringContainsString('href="mailto:a@example.com?subject=Hi"', $html);
        $this->assertStringContainsString('>Email us</a>', $html);
    }

    // ─── WordPressCache ──────────────────────────────────────────────

    public function testCacheRoundTrips(): void
    {
        $cache = new WordPressCache();
        $this->assertFalse($cache->get('missing'));

        // get() reads the default cache group, so set() must write there too.
        $this->assertTrue($cache->set('k', ['v' => 1]));
        $this->assertSame(['v' => 1], $cache->get('k'));

        $this->assertTrue($cache->delete('k'));
        $this->assertFalse($cache->get('k'));

        $cache->set('a', 1);
        $this->assertTrue($cache->flush());
        $this->assertFalse($cache->get('a'));
    }

    // ─── TrumpetConfig ───────────────────────────────────────────────

    public function testConfigConstantsAreReachable(): void
    {
        $this->assertSame('announcement', TrumpetConfig::ANNOUNCEMENT_POST_TYPE);
        $this->assertSame(3600, TrumpetConfig::CACHE_DURATION);
    }
}
