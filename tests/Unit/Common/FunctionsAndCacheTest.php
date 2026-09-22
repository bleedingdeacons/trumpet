<?php

declare(strict_types=1);

namespace Tests\Unit\Common;

use Trumpet\Common\Functions;
use Trumpet\Common\WordPressCache;
use Trumpet\Config\TrumpetConfig;

/*
 * Cover the small pure helpers: the link/anchor builders in Functions, the
 * wp_cache_* adapter, and that TrumpetConfig's constants are reachable.
 */

covers(Functions::class, WordPressCache::class, TrumpetConfig::class);

beforeEach(function () {
    $GLOBALS['trumpet_test_cache'] = [];
});

// ─── Functions ───────────────────────────────────────────────────
describe('Functions', function () {
    it('builds a mailto link with and without a subject', function () {
        expect(Functions::emailTo('a@example.com'))->toBe('mailto:a@example.com')
            ->and(Functions::emailTo('a@example.com', 'Hello'))->toBe('mailto:a@example.com?subject=Hello');
    });

    it('builds a tel link', function () {
        expect(Functions::phoneTo('07700900000'))->toBe('tel:07700900000');
    });

    it('builds an anchor', function () {
        $html = Functions::linkTo('https://example.com', 'btn', 'Click');
        expect($html)
            ->toContain('href="https://example.com"')
            ->toContain('class="btn"')
            ->toContain('>Click</a>')
            ->toContain('rel="noreferrer noopener"');
    });

    it('combines mailto and anchor in createEmailAnchor', function () {
        $html = Functions::createEmailAnchor('a@example.com', 'Hi', 'btn', 'Email us');
        expect($html)
            ->toContain('href="mailto:a@example.com?subject=Hi"')
            ->toContain('>Email us</a>');
    });
});

// ─── WordPressCache ──────────────────────────────────────────────
it('round-trips values through the cache', function () {
    $cache = new WordPressCache();
    expect($cache->get('missing'))->toBeFalse();

    // get() reads the default cache group, so set() must write there too.
    expect($cache->set('k', ['v' => 1]))->toBeTrue()
        ->and($cache->get('k'))->toBe(['v' => 1]);

    expect($cache->delete('k'))->toBeTrue()
        ->and($cache->get('k'))->toBeFalse();

    $cache->set('a', 1);
    expect($cache->flush())->toBeTrue()
        ->and($cache->get('a'))->toBeFalse();
});

// ─── TrumpetConfig ───────────────────────────────────────────────
it('makes the config constants reachable', function () {
    expect(TrumpetConfig::ANNOUNCEMENT_POST_TYPE)->toBe('announcement')
        ->and(TrumpetConfig::CACHE_DURATION)->toBe(3600);
});
