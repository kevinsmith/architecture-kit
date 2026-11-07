<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Fiber;
use Psr\Clock\ClockInterface;

/** Suspends the calling Fiber before each clock read, so other invocations run in between. */
final readonly class SuspendingClock implements ClockInterface
{
    public function __construct(
        private ClockInterface $inner,
    ) {}

    public function now(): DateTimeImmutable
    {
        Fiber::suspend();

        return $this->inner->now();
    }
}
