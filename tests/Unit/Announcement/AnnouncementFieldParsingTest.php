<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Tests\TestCase;

/*
 * Tests for how Announcement parses the raw ACF field values.
 *
 * Dates arrive as d/m/Y strings and locations as a lat/lng/address array;
 * both are converted in the constructor, so a value that fails to parse is
 * indistinguishable downstream from one that was never set.
 */

describe('dates', function () {
    it('parses a UK format date', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::START_DISPLAY => '15/03/2026',
        ]);

        expect($announcement->getFormattedStartDisplayDate())->toBe('15/03/2026')
            ->and($announcement->getStartDisplayDate()?->format('Y-m-d'))->toBe('2026-03-15');
    });

    it('formats a date with a caller-supplied format', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::START_DISPLAY => '15/03/2026',
        ]);

        expect($announcement->getFormattedStartDisplayDate('Y-m-d'))->toBe('2026-03-15');
    });

    // The field format is d/m/Y. An ISO date does not match it and is dropped
    // rather than reinterpreted — which matters because 03/04 in the two
    // formats are different days, so guessing would silently move an
    // announcement by months.
    it('rejects an ISO date rather than guessing', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::START_DISPLAY => '2026-03-15',
        ]);

        expect($announcement->getStartDisplayDate())->toBeNull()
            ->and($announcement->getFormattedStartDisplayDate())->toBe('');
    });

    it('drops an unparseable date', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::START_DISPLAY => 'not a date',
        ]);

        expect($announcement->getStartDisplayDate())->toBeNull();
    });

    // An absent start date means "display immediately", so a date the parser
    // rejects silently becomes one. Pinned because it makes a typo in the
    // admin fail open — the announcement publishes at once instead of waiting.
    it('makes the announcement display immediately when the start date will not parse', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::START_DISPLAY => '2026-12-31',
        ]);

        expect($announcement->isReadyToDisplay())
            ->toBeTrue('An unparsed start date is indistinguishable from no start date.');
    });

    // PHP's createFromFormat overflows impossible dates rather than failing:
    // 31 February becomes 3 March. Characterising current behaviour — this is
    // PHP's, not Trumpet's, but it is worth pinning so a future switch to
    // strict parsing is a deliberate decision with a visibly changed test.
    it('overflows an impossible date into the next month', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::START_DISPLAY => '31/02/2026',
        ]);

        expect($announcement->getStartDisplayDate()?->format('Y-m-d'))->toBe('2026-03-03');
    });
});

describe('locations', function () {
    it('treats a location with coordinates as valid when the map is shown', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278', 'address' => 'London'],
        ]);

        expect($announcement->hasValidLocation())->toBeTrue();
    });

    it('does not treat a location as valid when the map is switched off', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => false,
            TestCase::LOCATION => ['lat' => '51.5074', 'lng' => '-0.1278'],
        ]);

        expect($announcement->hasValidLocation())->toBeFalse();
    });

    it('rejects null island', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => ['lat' => '0', 'lng' => '0'],
        ]);

        expect($announcement->hasValidLocation())->toBeFalse();
    });

    // The defect this method used to have: isset() on lat/lng could never
    // fail, because sanitizeLocation() always writes both keys, defaulting to
    // ''. An announcement with the map switched on and nothing entered
    // reported a valid location.
    it('does not treat blank coordinates as a valid location', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => [],
        ]);

        expect($announcement->hasValidLocation())->toBeFalse();
    });

    it('does not treat an empty string coordinate as a valid location', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => ['lat' => '', 'lng' => ''],
        ]);

        expect($announcement->hasValidLocation())->toBeFalse();
    });

    // Half a location is not a location.
    it('does not treat a missing longitude as a valid location', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => ['lat' => '51.5074'],
        ]);

        expect($announcement->hasValidLocation())->toBeFalse();
    });

    // Null island written as a decimal. The original guard compared strings
    // against "0", so '0.0' and '0.00' walked straight through it.
    it('still treats zero written as a decimal as null island', function () {
        foreach (['0.0', '0.00', '0'] as $zero) {
            $announcement = $this->makeAnnouncement([
                TestCase::SHOW_MAP => true,
                TestCase::LOCATION => ['lat' => $zero, 'lng' => $zero],
            ]);

            expect($announcement->hasValidLocation())
                ->toBeFalse("Coordinates of $zero,$zero are null island however they are written.");
        }
    });

    // A single zero is a real place. The Greenwich meridian is longitude 0 and
    // runs through London, so an announcement there must keep its map — only
    // 0,0 together is the failed-geocode sentinel.
    it('keeps a location on the Greenwich meridian valid', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => ['lat' => '51.4779', 'lng' => '0'],
        ]);

        expect($announcement->hasValidLocation())
            ->toBeTrue('Longitude 0 is the meridian, not a missing coordinate.');
    });

    it('does not treat a non-numeric coordinate as a valid location', function () {
        $announcement = $this->makeAnnouncement([
            TestCase::SHOW_MAP => true,
            TestCase::LOCATION => ['lat' => 'not a number', 'lng' => 'nonsense'],
        ]);

        expect($announcement->hasValidLocation())->toBeFalse();
    });
});

it('reports the post status it was built from', function () {
    $pending = $this->makeAnnouncement([], 'pending');
    $published = $this->makeAnnouncement([], 'publish');

    expect($pending->getPublicationStatus())->toBe('pending')
        ->and($pending->isInReview())->toBeTrue()
        ->and($published->isInReview())->toBeFalse();
});
