<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Tests\TestCase;

/*
 * Tests for Announcement's visibility window.
 *
 * isActive() decides whether an announcement is shown at all, from three
 * inputs: the hide flag, the start-display date and the end date. Both dates
 * are inclusive comparisons against today, which is precisely where an
 * off-by-one turns into an announcement that appears a day late or vanishes a
 * day early — a silent failure nobody reports as a bug.
 *
 * Dates are compared as Y-m-d strings against a fresh DateTime, so "today"
 * means the machine's current date. Tests express dates as offsets from today
 * rather than fixed values, so they do not rot.
 */

it('is active when no dates are set', function () {
    $announcement = $this->makeAnnouncement();

    expect($announcement->isActive())->toBeTrue();
});

it('lets the hide flag override everything', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::HIDE => true,
        TestCase::START_DISPLAY => $this->daysFromToday(-10),
        TestCase::END_DATE => $this->daysFromToday(10),
    ]);

    expect($announcement->isActive())
        ->toBeFalse('A hidden announcement must stay hidden even inside its display window.');
});

// The end date is inclusive: an announcement ending today is still shown
// today, and only drops out tomorrow.
it('keeps an announcement ending today active', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::END_DATE => $this->daysFromToday(0),
    ]);

    expect($announcement->isActive())->toBeTrue();
});

it('does not keep an announcement that ended yesterday active', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::END_DATE => $this->daysFromToday(-1),
    ]);

    expect($announcement->isActive())->toBeFalse();
});

it('never expires an announcement with no end date', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::START_DISPLAY => $this->daysFromToday(-365),
    ]);

    expect($announcement->isActive())->toBeTrue();
});

// The start-display date is inclusive too: an announcement starting today
// is shown today, not tomorrow.
it('is ready to display an announcement starting today', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::START_DISPLAY => $this->daysFromToday(0),
    ]);

    expect($announcement->isReadyToDisplay())->toBeTrue()
        ->and($announcement->isActive())->toBeTrue();
});

it('is not yet ready to display an announcement starting tomorrow', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::START_DISPLAY => $this->daysFromToday(1),
    ]);

    expect($announcement->isReadyToDisplay())->toBeFalse()
        ->and($announcement->isActive())
        ->toBeFalse('Not-yet-started announcements must not be active, even with no end date.');
});

it('is ready immediately with no start date', function () {
    $announcement = $this->makeAnnouncement();

    expect($announcement->isReadyToDisplay())->toBeTrue();
});

// A window that has not opened yet takes precedence over an end date that
// has not passed — both must hold for the announcement to be active.
it('is inactive outside a future window', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::START_DISPLAY => $this->daysFromToday(5),
        TestCase::END_DATE => $this->daysFromToday(10),
    ]);

    expect($announcement->isActive())->toBeFalse();
});

it('is active inside the window', function () {
    $announcement = $this->makeAnnouncement([
        TestCase::START_DISPLAY => $this->daysFromToday(-5),
        TestCase::END_DATE => $this->daysFromToday(5),
    ]);

    expect($announcement->isActive())->toBeTrue();
});
