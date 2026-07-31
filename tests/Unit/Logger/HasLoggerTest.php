<?php

declare(strict_types=1);

namespace Tests\Unit\Logger;

use Tests\TestCase;
use Trumpet\Exception\AnnouncementException;
use Trumpet\Logger\HasLogger;

/**
 * The HasLogger trait resolves the shared Sentinel logger via wp_log() and
 * degrades to a no-op when it is unavailable. A host class drives every level
 * forwarder. AnnouncementException's constructor logs through the same trait
 * (on Plugin), so it is covered here too.
 *
 * @covers \Trumpet\Logger\HasLogger
 * @covers \Trumpet\Exception\AnnouncementException
 */
class HasLoggerTest extends TestCase
{
    public function testEveryLevelForwarderIsASafeNoopWithoutAChannel(): void
    {
        $this->assertNull(TrumpetLoggerHost::log());

        TrumpetLoggerHost::logEmergency('m', ['k' => 'v']);
        TrumpetLoggerHost::logAlert('m');
        TrumpetLoggerHost::logCritical('m');
        TrumpetLoggerHost::logError('m');
        TrumpetLoggerHost::logWarning('m');
        TrumpetLoggerHost::logNotice('m');
        TrumpetLoggerHost::logInfo('m');
        TrumpetLoggerHost::logDebug('m');

        $this->assertTrue(true);
    }

    public function testAnnouncementExceptionCarriesMessageCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('root');
        $e = new AnnouncementException('bad announcement', 7, $previous);

        $this->assertSame('bad announcement', $e->getMessage());
        $this->assertSame(7, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }
}

/** A class that uses the trait without overriding logChannel(). */
class TrumpetLoggerHost
{
    use HasLogger;
}
