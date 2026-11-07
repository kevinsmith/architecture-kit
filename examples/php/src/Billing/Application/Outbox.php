<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Contracts\InvoiceVoided;

interface Outbox
{
    /** @return list<InvoiceVoided> committed, unacknowledged intents in order */
    public function pending(): array;

    public function acknowledge(string $eventId): void;
}
