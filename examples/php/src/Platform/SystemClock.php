<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;

/** Shared Infrastructure behind the vendor PSR-20 contract. Supplied to Application, never Domain. */
final readonly class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
