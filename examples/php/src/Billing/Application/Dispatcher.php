<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Contracts\InvoiceVoidedSubscriber;

/**
 * Delivers committed intents at least once. Fail-fast: the first delivery or
 * acknowledgment failure stops the run and leaves later intents pending.
 */
final readonly class Dispatcher
{
    public function __construct(
        private Outbox $outbox,
        private InvoiceVoidedSubscriber $subscriber,
    ) {}

    public function dispatch(): void
    {
        foreach ($this->outbox->pending() as $event) {
            $this->subscriber->deliver($event);
            $this->outbox->acknowledge($event->id);
        }
    }
}
