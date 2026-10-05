<?php

declare(strict_types=1);

use Yiisoft\Schedule\Command\ListCommand;
use Yiisoft\Schedule\Command\RunCommand;
use Yiisoft\Schedule\Command\WorkCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'schedule:run' => RunCommand::class,
            'schedule:work' => WorkCommand::class,
            'schedule:list' => ListCommand::class,
        ],
    ],
    'yiisoft/schedule' => [
        'tasks' => [],
    ],
];
