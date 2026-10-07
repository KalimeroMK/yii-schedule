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

use const SIGCHLD;
use const SIGINT;
use const SIGTERM;

/**
 * Runs the scheduler as a daemon, sleeping until the next scheduled run instead of polling
 * on a fixed interval. With ext-pcntl, each due task runs in its own forked process, so
 * tasks sharing a due time start together instead of blocking one another.
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
            'The seconds to sleep when no task has a pending run.',
            1,
        );
        $this->addOption(
            'sequential',
            null,
            InputOption::VALUE_NONE,
            'Run due tasks one after another instead of forking a process per task.',
        );
    }

    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [SIGTERM, SIGINT, SIGCHLD] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (SIGCHLD === $signal) {
            // A task process finished; collect its outcome without leaving the loop.
            $this->scheduler->reapChildren();

            return false;
        }

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

        $this->scheduler->run((float) $sleep, !(bool) $input->getOption('sequential'));

        $output->writeln('<info>Scheduler stopped.</info>');

        return Command::SUCCESS;
    }
}
