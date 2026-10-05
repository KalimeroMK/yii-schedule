<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Schedule\Command\WorkCommand;
use Yiisoft\Schedule\Handler\CallableTaskHandler;
use Yiisoft\Schedule\RecurringTask;
use Yiisoft\Schedule\Schedule;
use Yiisoft\Schedule\Scheduler;
use Yiisoft\Schedule\Tests\Support\TestClock;

final class WorkCommandTest extends TestCase
{
    #[DataProvider('invalidSleepProvider')]
    public function testInvalidSleepIsRejected(string $sleep): void
    {
        $tester = new CommandTester(new WorkCommand($this->selfStoppingScheduler()));
        $exitCode = $tester->execute(['--sleep' => $sleep]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('--sleep', $tester->getDisplay());
    }

    public static function invalidSleepProvider(): array
    {
        return [
            'not a number' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'empty' => [''],
        ];
    }

    public function testValidSleepRunsUntilStopped(): void
    {
        $tester = new CommandTester(new WorkCommand($this->selfStoppingScheduler()));
        $exitCode = $tester->execute(['--sleep' => '0.01']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scheduler stopped.', $tester->getDisplay());
    }

    /**
     * A scheduler with one due task that stops the loop as soon as it runs.
     */
    private function selfStoppingScheduler(): Scheduler
    {
        $clock = new TestClock();
        $scheduler = null;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$scheduler): void {
                $scheduler?->stop();
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        $scheduler->tick();
        $clock->advance('+1 minute');

        return $scheduler;
    }
}
