<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Schedule\Clock\SystemClock;
use Yiisoft\Schedule\Handler\CallableTaskHandler;
use Yiisoft\Schedule\Handler\QueueTaskHandler;
use Yiisoft\Schedule\Handler\TaskHandlerInterface;
use Yiisoft\Schedule\Schedule;
use Yiisoft\Schedule\Scheduler;
use Yiisoft\Queue\QueueProducerInterface;
use Yiisoft\Schedule\RecurringTask;

/** @var array $params */

return [
    ClockInterface::class => SystemClock::class,

    TaskHandlerInterface::class => static function (ContainerInterface $container): TaskHandlerInterface {
        if (interface_exists(QueueProducerInterface::class) && $container->has(QueueProducerInterface::class)) {
            return $container->get(QueueTaskHandler::class);
        }

        return $container->get(CallableTaskHandler::class);
    },

    Scheduler::class => static function (ContainerInterface $container) use ($params): Scheduler {
        $schedule = new Schedule();

        /** @var RecurringTask $task */
        foreach ($params['yiisoft/schedule']['tasks'] as $task) {
            $schedule->task($task);
        }

        return new Scheduler(
            [$schedule],
            $container->get(TaskHandlerInterface::class),
            $container->get(ClockInterface::class),
            $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );
    },
];
