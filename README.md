<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii">
    </a>
    <h1 align="center">Yii Schedule</h1>
    <br>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/schedule/v)](https://packagist.org/packages/yiisoft/schedule)
[![Total Downloads](https://poser.pugx.org/yiisoft/schedule/downloads)](https://packagist.org/packages/yiisoft/schedule)
[![Build status](https://github.com/yiisoft/schedule/actions/workflows/build.yml/badge.svg)](https://github.com/yiisoft/schedule/actions/workflows/build.yml)
[![Code Coverage](https://codecov.io/gh/yiisoft/schedule/graph/badge.svg)](https://codecov.io/gh/yiisoft/schedule)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fyiisoft%2Fschedule%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/yiisoft/schedule/master)
[![static analysis](https://github.com/yiisoft/schedule/actions/workflows/static.yml/badge.svg?branch=master)](https://github.com/yiisoft/schedule/actions/workflows/static.yml?query=branch%3Amaster)

Recurring tasks and cron-style scheduling for PHP applications: define the tasks in code,
and let a single cron entry or a long-running worker run them when due — with missed-run
catch-up, overlap prevention and failure handling included.

## Features

- Cron expressions and fixed intervals through a single `TriggerInterface` contract
- Tasks as callables, console commands, or queue messages (via [yiisoft/queue](https://github.com/yiisoft/queue))
- Catch-up of missed runs and crash-resume through persisted checkpoints (any PSR-16 cache)
- Overlap prevention across processes through [yiisoft/mutex](https://github.com/yiisoft/mutex)
- PSR-14 events and per-schedule listeners (before / after / onFailure)
- `schedule:run` (single tick, for system cron), `schedule:work` (daemon), `schedule:list` commands

## Requirements

- PHP 8.1 - 8.5.

## Installation

```shell
composer require yiisoft/schedule
```

## General usage

Declare tasks in the configuration:

```php
// config/params.php
use Yiisoft\Schedule\RecurringTask;

return [
    'yiisoft/schedule' => [
        'tasks' => [
            RecurringTask::cron('0 5 * * *', 'report:daily'),
            RecurringTask::every('10 minutes', static fn () => $cleaner->purgeExpiredTokens()),
            RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('metrics.collect', [])),
        ],
    ],
];
```

and run them, either with a single system cron entry:

```shell
* * * * * php yii schedule:run
```

or as a daemon:

```shell
php yii schedule:work
```

The daemon sleeps until the next scheduled run instead of polling on a fixed interval, and
(with `ext-pcntl`) forks a child process per due task, so tasks sharing a due time run in
parallel instead of blocking one another. Pass `--sequential` to run due tasks one after
another as before.

Two things to know about the forked mode:

- a task's return value cannot cross the process boundary, so `PostRunEvent` and the `after`
  listeners receive `null` as the result; a failure is reported as the child's exit status;
- a child inherits the parent's open connections and sockets, so a task that talks to a
  database or similar should acquire its own connection rather than reuse one opened before
  the fork — or push the work to the queue, which is fast enough to do in the parent.

`schedule:run` exits between runs, so a persisted checkpoint is the only way for it to tell
which runs already happened: it requires a stateful schedule and reports an error without one.
The bundled configuration makes the schedule stateful automatically when the application has a
PSR-16 cache. `schedule:work` keeps the position in memory and works either way, though a
checkpoint also lets it compensate the runs missed while it was down.

When building a schedule by hand, persist its checkpoint, and add a mutex to prevent
overlapping runs across processes:

```php
use Yiisoft\Schedule\Schedule;

$schedule = (new Schedule())
    ->stateful($cache) // any PSR-16 implementation
    ->lock($mutex)     // any yiisoft/mutex implementation
    ->task(...);
```

## Documentation

- [Internals](docs/internals.md)

If you need help or have questions, [contact us](https://www.yiiframework.com/community/).

## License

The Yii Schedule is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)
