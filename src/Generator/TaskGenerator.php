<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Generator;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Yiisoft\Schedule\Exception\LogicException;
use Yiisoft\Schedule\RecurringTask;
use Yiisoft\Schedule\Schedule;
use Yiisoft\Schedule\TaskContext;

use function count;

/**
 * Computes which tasks of a schedule are due, in run order.
 *
 * The generator keeps a min-heap of pending runs seeded from the schedule checkpoint, so a
 * process that was down re-discovers every missed run, while runs already emitted before a
 * crash are skipped through their (time, index) position.
 *
 * @internal
 */
final class TaskGenerator
{
    private ?TriggerHeap $heap = null;
    private Checkpoint $checkpoint;
    /** @var array<string, int> Task id to insertion index. */
    private array $indices = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ClockInterface $clock,
    ) {
        $this->checkpoint = new Checkpoint($schedule->getName(), $schedule->getState(), $schedule->getMutex());
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    /**
     * Yields the due tasks as "TaskContext => RecurringTask" pairs.
     *
     * @return iterable<TaskContext, RecurringTask>
     */
    public function getTasks(): iterable
    {
        $now = $this->clock->now();

        if (!$this->checkpoint->acquire($now)) {
            return;
        }

        $emitted = false;

        try {
            $heap = $this->heap();

            while (!$heap->isEmpty() && $heap->top()[0] <= $now) {
                /** @var array{DateTimeImmutable, int, RecurringTask} $entry */
                $entry = $heap->extract();
                [$time, $index, $task] = $entry;

                $yield = true;

                // Skip the runs already emitted before an interruption.
                if (null !== ($lastTime = $this->checkpoint->time())) {
                    if ($time < $lastTime || ($time == $lastTime && $index <= $this->checkpoint->index())) {
                        $yield = false;
                    }
                }

                $nextTime = $task->getTrigger()->getNextRunDate($time);

                if (null !== $nextTime) {
                    if ($nextTime <= $time) {
                        throw new LogicException('A trigger must return a date strictly after the given one.');
                    }

                    $heap->insert([$nextTime, $index, $task]);

                    if ($nextTime <= $now && $this->schedule->shouldProcessOnlyLastMissedRun()) {
                        // A newer missed run exists; only the latest one fires.
                        $yield = false;
                    }
                }

                if (!$yield) {
                    $this->checkpoint->save($time, $index);
                    continue;
                }

                $context = new TaskContext($this->schedule->getName(), $task->getId(), $task->getTrigger(), $time, $nextTime);

                $emitted = true;

                try {
                    yield $context => $task;
                } finally {
                    $this->checkpoint->save($time, $index);
                }
            }

            if (!$emitted) {
                $this->checkpoint->markTick($now);
            }
        } finally {
            $this->checkpoint->release();
        }
    }

    private function heap(): TriggerHeap
    {
        if (null !== $this->heap) {
            return $this->heap;
        }

        $this->heap = new TriggerHeap();
        $lastTime = $this->checkpoint->time();

        // Probe one microsecond before the checkpoint so runs due exactly at the checkpoint time
        // are re-emitted and filtered by their index: only the unprocessed remainder survives.
        // An idle-tick marker (index -1) has no such remainder, so it seeds strictly after itself.
        $seedTime = null === $lastTime ? $this->clock->now() : $lastTime;
        if (null !== $lastTime && $this->checkpoint->index() >= 0) {
            $seedTime = $lastTime->modify('-1 microsecond');
        }

        foreach ($this->schedule->tasks() as $task) {
            $index = $this->indices[$task->getId()] ??= count($this->indices);

            if (null !== ($nextTime = $task->getTrigger()->getNextRunDate($seedTime))) {
                $this->heap->insert([$nextTime, $index, $task]);
            }
        }

        return $this->heap;
    }
}
