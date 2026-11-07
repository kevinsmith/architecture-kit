<?php

declare(strict_types=1);

namespace App\Billing\Application;

/** Success outcome, returned only after the transaction commits. */
final readonly class Voided
{
    public function __construct(
        public string $eventId,
    ) {}
}
