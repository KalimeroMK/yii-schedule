<?php

declare(strict_types=1);

namespace Yiisoft\Schedule;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Yiisoft\Schedule\Event\FailureEvent;
use Yiisoft\Schedule\Event\PostRunEvent;
use Yiisoft\Schedule\Event\PreRunEvent;
use Yiisoft\Schedule\Exception\LogicException;
use Yiisoft\Schedule\Generator\TaskGenerator;
use Yiisoft\Schedule\Handler\TaskHandlerInterface;

use function array_key_exists;
use function array_map;
use function hrtime;
use function max;
use function sprintf;
use function usleep;

/**
 * Runs the due tasks of one or more schedules.
 *
 * Use tick() for a single pass (e.g. from a system cron entry) or run() for a daemon loop.
 */
final class Scheduler
{
    /** @var array<string, TaskGenerator> */
    private array $generators = [];
    private bool $shouldStop = false;
    private readonly ?EventDispatcherInterface $dispatcher;
    private readonly LoggerInterface $logger;

    /**
     * @param iterable<Schedule> $schedules The schedules to run.
     */
    public function __construct(
        iterable $schedules,
        private readonly TaskHandlerInterface $handler,
        private readonly ClockInterface $clock,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger ?? new NullLogger();

        foreach ($schedules as $schedule) {
            $name = $schedule->getName();

            // Schedules are addressed by name, in the checkpoint cache key as well, so two of
            // them sharing one would quietly discard a schedule and mix up their positions.
            if (array_key_exists($name, $this->generators)) {
                throw new LogicException(
                    sprintf('A schedule named "%s" is already registered in the scheduler.', $name),
                );
            }

            $this->generators[$name] = new TaskGenerator($schedule, $clock);
        }
    }

    /**
     * Runs every due task once and returns how many tasks ran.
     */
    public function tick(): int
    {
        $count = 0;

        foreach ($this->generators as $generator) {
            foreach ($generator->getTasks() as $context => $task) {
                $this->runTask($task, $context);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Runs the scheduler loop until stop() is called (e.g. from a signal handler).
     *
     * A stop() requested before the loop starts, by a signal handler registered ahead of it,
     * is honoured: the loop is not entered at all.
     *
     * @param float $sleepSeconds The time to sleep between ticks when no task is due.
     *
     * @psalm-suppress RedundantCondition, TypeDoesNotContainType The flag is flipped by stop() from a signal handler.
     */
    public function run(float $sleepSeconds = 1.0): void
    {
        while (!$this->shouldStop) {
            $startedAt = hrtime(true);

            $this->tick();

            if ($this->shouldStop) {
                break;
            }

            $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
            $sleep = max(0.0, $sleepSeconds - $elapsed);

            if ($sleep > 0) {
                usleep(max(0, (int) ($sleep * 1_000_000)));
            }
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * @return Schedule[]
     */
    public function getSchedules(): array
    {
        return array_map(
            static fn(TaskGenerator $generator): Schedule => $generator->getSchedule(),
            $this->generators,
        );
    }

    private function runTask(RecurringTask $task, TaskContext $context): void
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

        $preEvent = new PreRunEvent($context, $task);
        $this->dispatcher?->dispatch($preEvent);

        // Checked before the listeners run: a cancelled run has no after/onFailure counterpart,
        // so whatever a before listener opens here would never be closed.
        if ($preEvent->isCancelled()) {
            $this->logger->info('Task {id} of schedule {schedule} was cancelled.', ['id' => $context->taskId, 'schedule' => $context->scheduleName]);

            return;
        }

        foreach ($schedule->getBeforeListeners() as $listener) {
            $listener($context, $task);
        }

        try {
            $result = $this->handler->handle($task->getTask(), $context);
        } catch (Throwable $error) {
            $failureEvent = new FailureEvent($context, $task, $error);
            $this->dispatcher?->dispatch($failureEvent);
            foreach ($schedule->getFailureListeners() as $listener) {
                $listener($context, $task, $error);
            }

            if (!$failureEvent->isIgnored()) {
                throw $error;
            }

            $this->logger->error(
                'Task {id} of schedule {schedule} failed: {error}',
                ['id' => $context->taskId, 'schedule' => $context->scheduleName, 'error' => $error->getMessage()],
            );

            return;
        }

        $this->dispatcher?->dispatch(new PostRunEvent($context, $task, $result));
        foreach ($schedule->getAfterListeners() as $listener) {
            $listener($context, $task, $result);
        }
    }
}
