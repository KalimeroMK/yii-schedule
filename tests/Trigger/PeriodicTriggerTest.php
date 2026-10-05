<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Trigger;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Trigger\PeriodicTrigger;

final class PeriodicTriggerTest extends TestCase
{
    public function testSecondsInterval(): void
    {
        $trigger = new PeriodicTrigger(300);
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-01 00:05:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testSecondsIntervalStaysAnchored(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        // 7 minutes after the anchor: next run is at 10, not at 12.
        $lastRun = new DateTimeImmutable('2026-01-01 00:07:00+00:00');

        $this->assertSame('2026-01-01 00:10:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testBeforeAnchorRunsAtAnchor(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 12:00:00+00:00'));
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-01 12:00:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testUntilExhaustsTheTrigger(): void
    {
        $until = new DateTimeImmutable('2026-01-01 01:00:00+00:00');
        $trigger = new PeriodicTrigger(300, until: $until);

        $this->assertNotNull($trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 00:00:00+00:00')));
        $this->assertNull($trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 00:56:00+00:00')));
    }

    public function testDateInterval(): void
    {
        $trigger = new PeriodicTrigger(new DateInterval('P1D'));
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-02 00:00:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }
}
