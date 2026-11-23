<?php

declare(strict_types=1);

namespace Tests\Unit\Announcement;

use Trumpet\Announcement\Priority;

/*
 * Cover Priority: reading the raw ACF `priority` value, with anything
 * unrecognised falling back to Normal, and the sort rank.
 */

covers(Priority::class);

it('reads the ACF priority value', function (mixed $raw, Priority $expected) {
    expect(Priority::fromField($raw))->toBe($expected);
})->with([
    'high'               => ['high', Priority::High],
    'normal'             => ['normal', Priority::Normal],
    'low'                => ['low', Priority::Low],
    'any case, padded'   => [' HIGH ', Priority::High],
    'select "both" array' => [['value' => 'low', 'label' => 'Low'], Priority::Low],
    'empty string'       => ['', Priority::Normal],
    'missing'            => [null, Priority::Normal],
    'unrecognised'       => ['urgent', Priority::Normal],
    'array with no value' => [['label' => 'High'], Priority::Normal],
    'not a string'       => [1, Priority::Normal],
]);

it('ranks high before normal before low', function () {
    expect(Priority::High->rank())->toBeLessThan(Priority::Normal->rank())
        ->and(Priority::Normal->rank())->toBeLessThan(Priority::Low->rank());
});
