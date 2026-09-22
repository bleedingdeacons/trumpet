<?php

declare(strict_types=1);

namespace Tests\Unit\Logger;

use BleedingDeacons\WpMocks\WpState;
use Trumpet\Exception\AnnouncementException;
use Trumpet\Logger\HasLogger;

/*
 * The HasLogger trait resolves the shared Sentinel logger via wp_log(). A host
 * class that does not override logChannel() drives the default derivation and
 * every level forwarder; wp-mocks' `sentinel` group supplies the channel, so
 * what each forwarder emits is assertable. AnnouncementException's constructor
 * logs through the same trait (on Plugin), so it is covered here too.
 */

covers(HasLogger::class, AnnouncementException::class);

it('forwards every level to the channel', function () {
    expect(TrumpetLoggerHost::log())->not->toBeNull();

    TrumpetLoggerHost::logEmergency('m', ['k' => 'v']);
    TrumpetLoggerHost::logAlert('m');
    TrumpetLoggerHost::logCritical('m');
    TrumpetLoggerHost::logError('m');
    TrumpetLoggerHost::logWarning('m');
    TrumpetLoggerHost::logNotice('m');
    TrumpetLoggerHost::logInfo('m');
    TrumpetLoggerHost::logDebug('m');

    $levels = array_column(
        array_filter(WpState::$logs, static fn (array $l): bool => $l[0] === 'trumpetloggerhost'),
        1
    );

    expect($levels)->toBe(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
});

it('carries the message, code and previous exception on AnnouncementException', function () {
    $previous = new \RuntimeException('root');
    $e = new AnnouncementException('bad announcement', 7, $previous);

    expect($e->getMessage())->toBe('bad announcement')
        ->and($e->getCode())->toBe(7)
        ->and($e->getPrevious())->toBe($previous);
});

/** A class that uses the trait without overriding logChannel(). */
class TrumpetLoggerHost
{
    use HasLogger;
}
