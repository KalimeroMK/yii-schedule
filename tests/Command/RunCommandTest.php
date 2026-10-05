<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Schedule\Command\RunCommand;
use Yiisoft\Schedule\Handler\CallableTaskHandler;
use Yiisoft\Schedule\RecurringTask;
use Yiisoft\Schedule\Schedule;
use Yiisoft\Schedule\Scheduler;
use Yiisoft\Schedule\Tests\Support\TestClock;

final class RunCommandTest extends TestCase
{
    public function testNoTaskIsDue(): void
    {
        $scheduler = new Scheduler([new Schedule()], new CallableTaskHandler(), new TestClock());

        $tester = new CommandTester(new RunCommand($scheduler));
        $tester->execute([]);

        $this->assertStringContainsString('No task is due.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testDueTaskRuns(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        $scheduler->tick();
        $clock->advance('+1 minute');

        $tester = new CommandTester(new RunCommand($scheduler));
        $tester->execute([]);

        $this->assertSame(1, $ran);
        $this->assertStringContainsString('Ran 1 task(s).', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }
}
