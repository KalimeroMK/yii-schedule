<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Event;

use Yiisoft\Schedule\RecurringTask;
use Yiisoft\Schedule\TaskContext;

/**
 * Dispatched after a task ran successfully.
 */
final class PostRunEvent
{
    public function __construct(
        public readonly TaskContext $context,
        public readonly RecurringTask $task,
        public readonly mixed $result,
    ) {}
}
