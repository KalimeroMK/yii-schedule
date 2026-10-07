<?php

declare(strict_types=1);

namespace Yiisoft\Schedule;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Yiisoft\Schedule\Event\FailureEvent;
use Yiisoft\Schedule\Event\PostRunEvent;
use Yiisoft\Schedule\Event\PreRunEvent;
use Yiisoft\Schedule\Exception\LogicException;
use Yiisoft\Schedule\Exception\RuntimeException;
use Yiisoft\Schedule\Generator\TaskGenerator;
use Yiisoft\Schedule\Handler\TaskHandlerInterface;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function extension_loaded;
use function function_exists;
use function hrtime;
use function max;
use function sprintf;
use function time_nanosleep;
use function usleep;

use const SIGKILL;
use const SIGTERM;
use const WNOHANG;

/**
 * Runs the due tasks of one or more schedules.
 *
 * Use tick() for a single pass (e.g. from a system cron entry) or run() for a daemon loop.
 * The daemon loop sleeps until the next scheduled run instead of polling on a fixed interval
 * and, when ext-pcntl is available, forks a child process per due task so tasks sharing a due
 * time start together instead of blocking one another.
 */
final class Scheduler
{
    /** @var array<string, TaskGenerator> */
    private array $generators = [];
    private bool $shouldStop = false;
    /** @var array<int, array{RecurringTask, TaskContext}> Child process id to its task and context. */
    private array $children = [];
    private readonly LoggerInterface $logger;

    /**
     * @param iterable<Schedule> $schedules The schedules to run.
     */
    public function __construct(
        iterable $schedules,
        private readonly TaskHandlerInterface $handler,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
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
     *
     * @param bool $concurrent Fork a child process per due task, so tasks due at the same time
     * run in parallel instead of blocking one another. Requires ext-pcntl; without it the tasks
     * run one after another as before. A task failure then surfaces as a RuntimeException about
     * the child exit status when the child is reaped, and the PostRunEvent result is null
     * because the outcome cannot cross the process boundary.
     */
    public function tick(bool $concurrent = false): int
    {
        if (!$concurrent || !self::supportsForking()) {
            $count = 0;

            foreach ($this->generators as $generator) {
                foreach ($generator->getTasks() as $context => $task) {
                    $this->runTask($task, $context);
                    ++$count;
                }
            }

            return $count;
        }

        $due = [];

        foreach ($this->generators as $generator) {
            foreach ($generator->getTasks() as $context => $task) {
                $due[] = [$task, $context];
            }
        }

        foreach ($due as [$task, $context]) {
            $this->forkTask($task, $context);
        }

        return count($due);
    }

    /**
     * The soonest pending run across all schedules, if any.
     */
    public function nextRunDate(): ?DateTimeImmutable
    {
        $next = null;

        foreach ($this->generators as $generator) {
            $date = $generator->getNextRunDate();

            if (null !== $date && (null === $next || $date < $next)) {
                $next = $date;
            }
        }

        return $next;
    }

    /**
     * Runs the scheduler loop until stop() is called (e.g. from a signal handler).
     *
     * A stop() requested before the loop starts, by a signal handler registered ahead of it,
     * is honoured: the loop is not entered at all.
     *
     * Between ticks the loop sleeps until the soonest pending run instead of waking up on a
     * fixed interval, so an idle daemon costs a couple of syscalls per run instead of a full
     * schedule evaluation per second.
     *
     * @param float $sleepSeconds The fallback sleep when no task has a pending run.
     * @param bool $concurrent Fork a child process per due task (see tick()). Falls back to
     * sequential execution when ext-pcntl is not available.
     *
     * @psalm-suppress RedundantCondition, TypeDoesNotContainType The flag is flipped by stop() from a signal handler.
     */
    public function run(float $sleepSeconds = 1.0, bool $concurrent = true): void
    {
        $concurrent = $concurrent && self::supportsForking();

        try {
            while (!$this->shouldStop) {
                $this->tick($concurrent);

                if ($this->shouldStop) {
                    break;
                }

                $sleep = $this->computeSleep($sleepSeconds);

                if ($sleep > 0) {
                    $this->sleep($sleep);
                }

                $this->reapChildren();
            }
        } finally {
            $this->terminateChildren();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Collects the outcome of every finished child process, dispatching its post-run or
     * failure event. Safe to call from a SIGCHLD handler; does nothing without ext-pcntl.
     *
     * A failure that no listener ignored is thrown once the whole batch is reaped, so one
     * broken task neither kills its siblings nor goes silently unnoticed.
     */
    public function reapChildren(): void
    {
        if ([] === $this->children || !self::supportsForking()) {
            return;
        }

        $failure = null;

        while (($pid = pcntl_wait($status, WNOHANG)) > 0) {
            if (!array_key_exists($pid, $this->children)) {
                continue;
            }

            [$task, $context] = $this->children[$pid];
            unset($this->children[$pid]);

            try {
                $this->handleChildOutcome($task, $context, $status);
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    public function hasRunningChildren(): bool
    {
        return [] !== $this->children;
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

    private static function supportsForking(): bool
    {
        return extension_loaded('pcntl') && function_exists('pcntl_fork');
    }

    /**
     * How long the loop may idle before the next tick: the time to the soonest pending run,
     * or the configured fallback when no schedule reports one.
     */
    private function computeSleep(float $fallback): float
    {
        $next = $this->nextRunDate();

        if (null === $next) {
            return $fallback;
        }

        return max(
            0.0,
            (float) $next->format('U.u') - (float) $this->clock->now()->format('U.u'),
        );
    }

    /**
     * Sleeps until the deadline, waking up early when a signal (a stop request or a finished
     * child, when a SIGCHLD handler is registered) interrupts the sleep.
     */
    private function sleep(float $seconds): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

        while (!$this->shouldStop) {
            $remaining = ($deadline - hrtime(true)) / 1_000_000_000;

            if ($remaining <= 0) {
                break;
            }

            $wholeSeconds = (int) $remaining;
            $nanoseconds = (int) (($remaining - $wholeSeconds) * 1_000_000_000);

            time_nanosleep($wholeSeconds, $nanoseconds);

            $this->reapChildren();
        }
    }

    private function runTask(RecurringTask $task, TaskContext $context): void
    {
        if (!$this->beforeRun($task, $context)) {
            return;
        }

        $this->executeTask($task, $context);
    }

    /**
     * Dispatches the pre-run event and the schedule's before listeners.
     *
     * Checked before the listeners run: a cancelled run has no after/onFailure counterpart,
     * so whatever a before listener opens here would never be closed.
     */
    private function beforeRun(RecurringTask $task, TaskContext $context): bool
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

        $preEvent = new PreRunEvent($context, $task);
        $this->dispatcher?->dispatch($preEvent);

        if ($preEvent->isCancelled()) {
            $this->logger->info('Task {id} of schedule {schedule} was cancelled.', ['id' => $context->taskId, 'schedule' => $context->scheduleName]);

            return false;
        }

        foreach ($schedule->getBeforeListeners() as $listener) {
            $listener($context, $task);
        }

        return true;
    }

    private function executeTask(RecurringTask $task, TaskContext $context): void
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

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

    /**
     * Starts the task in a child process. The pre-run event and the before listeners run in
     * the parent, so cancellation never pays for a fork; the post-run and failure events are
     * dispatched back in the parent when the child is reaped.
     */
    private function forkTask(RecurringTask $task, TaskContext $context): void
    {
        if (!$this->beforeRun($task, $context)) {
            return;
        }

        $pid = pcntl_fork();

        if (-1 === $pid) {
            // Forking is refused (e.g. resource limits): run inline rather than drop the run.
            $this->executeTask($task, $context);

            return;
        }

        if (0 === $pid) {
            $this->runChild($task, $context);
        }

        $this->children[$pid] = [$task, $context];
    }

    /**
     * The child's whole lifespan: run the task and report through the exit code. The full
     * error cannot cross to the parent, so it is logged here, next to where it happened.
     *
     * Note the child shares the parent's open sockets and connections; handlers meant for the
     * daemon should acquire their own instead of reusing ones opened before the fork.
     */
    private function runChild(RecurringTask $task, TaskContext $context): never
    {
        try {
            $this->handler->handle($task->getTask(), $context);
        } catch (Throwable $error) {
            $this->logger->error(
                'Task {id} of schedule {schedule} failed: {error}',
                ['id' => $context->taskId, 'schedule' => $context->scheduleName, 'error' => $error->getMessage()],
            );

            exit(1);
        }

        exit(0);
    }

    /**
     * Turns a child's exit status into the same events an inline run would have produced.
     */
    private function handleChildOutcome(RecurringTask $task, TaskContext $context, int $status): void
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

        if (pcntl_wifexited($status) && 0 === pcntl_wexitstatus($status)) {
            // The result cannot cross the process boundary, so listeners see null here.
            $this->dispatcher?->dispatch(new PostRunEvent($context, $task, null));
            foreach ($schedule->getAfterListeners() as $listener) {
                $listener($context, $task, null);
            }

            return;
        }

        $error = new RuntimeException(
            pcntl_wifexited($status)
                ? sprintf('The task process exited with code %d.', pcntl_wexitstatus($status))
                : sprintf('The task process was terminated by signal %d.', pcntl_wtermsig($status)),
        );

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
    }

    /**
     * On the way out, asks the still-running children to finish and waits for them briefly,
     * so a reload or deploy does not strand task processes. Their outcomes are not turned
     * into events: a shutdown is not a task failure.
     */
    private function terminateChildren(): void
    {
        if ([] === $this->children || !self::supportsForking()) {
            return;
        }

        foreach (array_keys($this->children) as $pid) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
        }

        $deadline = hrtime(true) + 5_000_000_000;

        while ([] !== $this->children) {
            $pid = pcntl_wait($status, WNOHANG);

            if ($pid > 0) {
                unset($this->children[$pid]);
                continue;
            }

            if (hrtime(true) >= $deadline) {
                if (function_exists('posix_kill')) {
                    foreach (array_keys($this->children) as $pid) {
                        posix_kill($pid, SIGKILL);
                    }
                }

                while (($pid = pcntl_wait($status)) > 0) {
                    unset($this->children[$pid]);
                }

                break;
            }

            usleep(10_000);
        }
    }
}
