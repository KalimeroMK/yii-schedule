<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Trigger;

use DateInterval;
use DateTimeImmutable;
use Yiisoft\Schedule\Exception\LogicException;

use function is_int;
use function sprintf;

/**
 * A trigger firing at a fixed interval, anchored to a "from" date (by default the first time it is asked).
 */
final class PeriodicTrigger implements TriggerInterface
{
    private readonly ?int $seconds;

    /**
     * @param int|DateInterval $interval The interval between runs, in seconds or as a date interval.
     * @param DateTimeImmutable|null $from The anchor date. Defaults to the first time the trigger is asked.
     * @param DateTimeImmutable|null $until The date after which the trigger is exhausted.
     */
    public function __construct(
        private readonly int|DateInterval $interval,
        private readonly ?DateTimeImmutable $from = null,
        private readonly ?DateTimeImmutable $until = null,
    ) {
        if (is_int($interval)) {
            if ($interval <= 0) {
                throw new LogicException(sprintf('The interval must be greater than zero, "%d" given.', $interval));
            }
            $this->seconds = $interval;
        } else {
            $this->seconds = null;
        }
    }

    public function __toString(): string
    {
        if (null !== $this->seconds) {
            return sprintf('every(%d seconds)', $this->seconds);
        }

        return sprintf('every(%s)', $this->interval instanceof DateInterval ? $this->interval->format('P%yM%dDT%hH%iM%sS') : 'unknown');
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        if (null !== $this->until && $lastRun >= $this->until) {
            return null;
        }

        if (null === $this->from) {
            $next = $this->add($lastRun);
        } elseif ($lastRun < $this->from) {
            $next = $this->from;
        } elseif (null !== $this->seconds) {
            $elapsed = $lastRun->getTimestamp() - $this->from->getTimestamp();
            $next = $this->from->getTimestamp() + (int) ($elapsed / $this->seconds) * $this->seconds;
            $next = (new DateTimeImmutable())->setTimestamp($next)->setTimezone($lastRun->getTimezone());

            if ($next <= $lastRun) {
                $next = $next->modify(sprintf('+%d seconds', $this->seconds));
            }
        } else {
            $next = $this->from;
            $guard = 0;
            do {
                $next = $next->add($this->interval instanceof DateInterval ? $this->interval : new DateInterval('PT0S'));
                if (++$guard > 10000) {
                    throw new LogicException(
                        'The interval does not advance the date; use a cron expression for calendar-based schedules.',
                    );
                }
            } while ($next <= $lastRun);
        }

        if (null !== $this->until && $next > $this->until) {
            return null;
        }

        return $next;
    }

    private function add(DateTimeImmutable $date): DateTimeImmutable
    {
        return null !== $this->seconds
            ? $date->modify(sprintf('+%d seconds', $this->seconds))
            : $date->add($this->interval instanceof DateInterval ? $this->interval : new DateInterval('PT0S'));
    }
}
