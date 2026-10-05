<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests;

use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Event\FailureEvent;
use Yiisoft\Schedule\Event\PostRunEvent;
use Yiisoft\Schedule\Event\PreRunEvent;
use Yiisoft\Schedule\Handler\CallableTaskHandler;
use Yiisoft\Schedule\RecurringTask;
use Yiisoft\Schedule\Schedule;
use Yiisoft\Schedule\Scheduler;
use Yiisoft\Schedule\Tests\Support\InMemoryCache;
use Yiisoft\Schedule\Tests\Support\TestClock;
use Psr\EventDispatcher\EventDispatcherInterface;
use Yiisoft\Schedule\Exception\LogicException;
use Yiisoft\Schedule\Tests\Support\InMemoryMutex;
use RuntimeException;

final class SchedulerTest extends TestCase
{
    public function testDueTaskRuns(): void
    {
        $clock = new TestClock();
        $ran = [];

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                $ran[] = 'hit';
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $this->assertSame(0, $scheduler->tick());

        $clock->advance('+1 minute');
        $this->assertSame(1, $scheduler->tick());
        $this->assertSame(['hit'], $ran);
    }

    public function testTaskRunsOncePerDueDate(): void
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
        $scheduler->tick();
        $scheduler->tick();

        $this->assertSame(1, $ran);
    }

    public function testMissedRunsAreCaughtUp(): void
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
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(3, $ran);
    }

    public function testProcessOnlyLastMissedRun(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())
            ->processOnlyLastMissedRun()
            ->task(
                RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                    ++$ran;
                }),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(1, $ran);
    }

    public function testStatefulScheduleResumesAcrossProcesses(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $ran = 0;

        $buildScheduler = static function () use ($cache, $clock, &$ran): Scheduler {
            $schedule = (new Schedule())
                ->stateful($cache)
                ->task(
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        ++$ran;
                    }),
                );

            return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        };

        $scheduler = $buildScheduler();
        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        // The first process dies; a new one resumes from the persisted checkpoint.
        $scheduler = $buildScheduler();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(2, $ran);
    }

    public function testPreRunEventCancelsTheRun(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof PreRunEvent) {
                    $event->cancel();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(0, $ran);
    }

    public function testPostRunEventReceivesTheResult(): void
    {
        $clock = new TestClock();
        $results = [];

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static fn(): string => 'done'),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public array $results = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof PostRunEvent) {
                    $this->results[] = $event->result;
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(['done'], $dispatcher->results);
    }

    public function testFailureIsRethrownUnlessIgnored(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task failed.');

        $scheduler->tick();
    }

    public function testIgnoredFailureLetsTheSchedulerContinue(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof FailureEvent) {
                    $event->ignore();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $this->assertSame(2, $scheduler->tick());
        $this->assertSame(1, $ran);
    }

    public function testScheduleListeners(): void
    {
        $clock = new TestClock();
        $calls = [];

        $schedule = (new Schedule())
            ->before(static function () use (&$calls): void {
                $calls[] = 'before';
            })
            ->after(static function () use (&$calls): void {
                $calls[] = 'after';
            })
            ->task(
                RecurringTask::cron('* * * * *', static fn(): null => null),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(['before', 'after'], $calls);
    }

    public function testDuplicateTaskIdIsRejected(): void
    {
        $task = RecurringTask::cron('* * * * *', 'cache:clear');

        $schedule = new Schedule();

        $this->expectException(LogicException::class);

        $schedule->task($task, $task);
    }

    public function testLockedScheduleIsSkipped(): void
    {
        $clock = new TestClock();
        $mutex = new InMemoryMutex();
        $ran = 0;

        $schedule = (new Schedule())
            ->lock($mutex)
            ->task(
                RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                    ++$ran;
                }),
            );

        // Another process holds the schedule mutex.
        $mutex->forceLock();

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $this->assertSame(0, $scheduler->tick());
        $this->assertSame(0, $ran);
    }

    public function testMissedRunsAreCaughtUpAcrossProcesses(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $ran = 0;

        $buildScheduler = static function () use ($cache, $clock, &$ran): Scheduler {
            $schedule = (new Schedule())
                ->stateful($cache)
                ->task(
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        ++$ran;
                    }),
                );

            return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        };

        // First process ticks once, then is down for three minutes.
        $buildScheduler()->tick();

        $clock->advance('+3 minutes');

        // A new process resumes from the persisted checkpoint and catches up every missed run.
        $buildScheduler()->tick();

        $this->assertSame(3, $ran);
    }
}
