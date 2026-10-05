<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Schedule\Scheduler;

use function extension_loaded;
use function get_debug_type;
use function is_numeric;
use function is_scalar;
use function sprintf;

use const SIGINT;
use const SIGTERM;

/**
 * Runs the scheduler as a daemon, evaluating the schedules about once per second.
 */
#[AsCommand('schedule:work', 'Runs the scheduler as a long-running process.')]
final class WorkCommand extends Command implements SignalableCommandInterface
{
    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'sleep',
            's',
            InputOption::VALUE_REQUIRED,
            'The seconds to sleep between ticks when no task is due.',
            1,
        );
    }

    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->scheduler->stop();

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var mixed $sleep */
        $sleep = $input->getOption('sleep');

        // A non-numeric or non-positive value would silently cast to zero and spin the loop
        // at full speed instead of sleeping between ticks.
        if (!is_numeric($sleep) || (float) $sleep <= 0) {
            $output->writeln(sprintf(
                '<error>The --sleep option must be a number greater than zero, "%s" given.</error>',
                is_scalar($sleep) ? (string) $sleep : get_debug_type($sleep),
            ));

            return Command::INVALID;
        }

        $output->writeln('<info>Scheduler started.</info>');

        $this->scheduler->run((float) $sleep);

        $output->writeln('<info>Scheduler stopped.</info>');

        return Command::SUCCESS;
    }
}
