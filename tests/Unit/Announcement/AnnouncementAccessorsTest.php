<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use DateTime;
use Tests\TestCase;
use Trumpet\Announcement\Announcement;
use Trumpet\Announcement\Priority;
use Trumpet\Config\TrumpetConfig;

/*
 * Cover the Announcement accessors and derived-state helpers not exercised by
 * the parsing/visibility/map suites: HTML body sanitisation, the formatted
 * date getters, getPostDate/isHidden, and every branch of getStatusText.
 */

covers(Announcement::class);

it('passes the HTML body through the kses allow list', function () {
    // Exercises sanitizeHtml()'s allowed-tag map for a string body. The test
    // wp_kses stub is a pass-through, so this asserts the media-preserving
    // path runs, not the real stripping behaviour.
    $a = $this->makeAnnouncement([
        TestCase::BODY => '<p class="lead">Hi</p><img src="x.jpg" alt="x">',
    ]);

    $body = $a->getBody();
    expect($body)
        ->toContain('<img')
        ->toContain('<p');
});

it('sanitises a non-string body to an empty string', function () {
    $a = $this->makeAnnouncement([TestCase::BODY => ['not', 'a', 'string']]);
    expect($a->getBody())->toBe('');
});

it('renders formatted dates when set and leaves them blank when absent', function () {
    $with = $this->makeAnnouncement([
        TestCase::END_DATE => '25/12/2027',
        TestCase::START_DISPLAY => '01/06/2026',
    ]);
    expect($with->getFormattedEndDate())->toBe('25/12/2027')
        ->and($with->getFormattedStartDisplayDate())->toBe('01/06/2026');

    $without = $this->makeAnnouncement([]);
    expect($without->getFormattedEndDate())->toBe('')
        ->and($without->getFormattedStartDisplayDate())->toBe('');
});

it('takes the post date and formatted post date from the publish time', function () {
    // get_the_time defaults to 01/01/2026 in the test bootstrap.
    $a = $this->makeAnnouncement([]);

    expect($a->getPostDate())->toBeInstanceOf(DateTime::class)
        ->and($a->getFormattedPostDate())->toBe('01/01/2026');
});

it('reflects the hide field in isHidden', function () {
    expect($this->makeAnnouncement([TestCase::HIDE => true])->isHidden())->toBeTrue()
        ->and($this->makeAnnouncement([TestCase::HIDE => false])->isHidden())->toBeFalse();
});

it('reads the priority field, defaulting to normal', function () {
    expect($this->makeAnnouncement([TrumpetConfig::PRIORITY_FIELD => 'high'])->getPriority())->toBe(Priority::High)
        ->and($this->makeAnnouncement([TrumpetConfig::PRIORITY_FIELD => 'low'])->getPriority())->toBe(Priority::Low)
        ->and($this->makeAnnouncement([])->getPriority())->toBe(Priority::Normal);
});

it('reads an announcement cached before priority existed as normal', function () {
    // An object cached by an older version is unserialized without the
    // constructor running; newInstanceWithoutConstructor() builds it the same
    // way, leaving only declared defaults in place.
    $stale = (new \ReflectionClass(Announcement::class))->newInstanceWithoutConstructor();

    expect($stale->getPriority())->toBe(Priority::Normal);
});

it('reads a serialized announcement with no priority as normal', function () {
    $fresh = serialize($this->makeAnnouncement([TrumpetConfig::PRIORITY_FIELD => 'high']));
    // Strip the property, as in a payload written before it existed.
    $stale = preg_replace(
        '/s:\d+:"\0Trumpet\\\\Announcement\\\\Announcement\0priority";E:\d+:"[^"]+";/',
        '',
        $fresh,
        1,
        $count
    );
    $stale = preg_replace_callback(
        '/^O:(\d+):"([^"]+)":(\d+):/',
        fn (array $m) => sprintf('O:%s:"%s":%d:', $m[1], $m[2], (int) $m[3] - 1),
        (string) $stale
    );

    expect($count)->toBe(1);
    expect(unserialize((string) $stale)->getPriority())->toBe(Priority::Normal);
});

// ─── getStatusText, branch by branch ─────────────────────────────
describe('getStatusText', function () {
    it('is Review for a pending post', function () {
        $a = $this->makeAnnouncement([TestCase::TITLE => 'T'], 'pending');
        expect($a->getStatusText())->toBe('Review');
    });

    it('is Hidden when hidden', function () {
        $a = $this->makeAnnouncement([TestCase::HIDE => true]);
        expect($a->getStatusText())->toBe('Hidden');
    });

    it('is Pending before the start date', function () {
        $a = $this->makeAnnouncement([TestCase::START_DISPLAY => $this->daysFromToday(5)]);
        expect($a->getStatusText())->toBe('Pending');
    });

    it('is Active with no end date', function () {
        $a = $this->makeAnnouncement([TestCase::TITLE => 'T']);
        expect($a->getStatusText())->toBe('Active');
    });

    it('is Active before the end date', function () {
        $a = $this->makeAnnouncement([TestCase::END_DATE => $this->daysFromToday(5)]);
        expect($a->getStatusText())->toBe('Active');
    });

    it('is Expired after the end date', function () {
        $a = $this->makeAnnouncement([TestCase::END_DATE => $this->daysFromToday(-5)]);
        expect($a->getStatusText())->toBe('Expired');
    });
});
