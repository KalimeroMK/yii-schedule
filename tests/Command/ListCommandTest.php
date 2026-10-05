<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Schedule\Command\ListCommand;
use Yiisoft\Schedule\Handler\CallableTaskHandler;
use Yiisoft\Schedule\RecurringTask;
use Yiisoft\Schedule\Schedule;
use Yiisoft\Schedule\Scheduler;
use Yiisoft\Schedule\Tests\Support\TestClock;

final class ListCommandTest extends TestCase
{
    public function testListsTasksWithNextRunDates(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('0 5 * * *', 'report:daily'),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $tester = new CommandTester(new ListCommand($scheduler, $clock));
        $tester->execute([]);

        $display = $tester->getDisplay();

        $this->assertStringContainsString('cron(0 5 * * *)', $display);
        $this->assertStringContainsString('2026-01-01 05:00:00', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }
}
