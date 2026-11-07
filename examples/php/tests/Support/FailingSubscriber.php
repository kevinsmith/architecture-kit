<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Contracts\InvoiceVoidedSubscriber;
use Throwable;

final readonly class FailingSubscriber implements InvoiceVoidedSubscriber
{
    public function __construct(
        private InvoiceVoidedSubscriber $inner,
        private string $eventId,
        private Throwable $failure,
    ) {}

    public function deliver(InvoiceVoided $event): void
    {
        if ($event->id === $this->eventId) {
            throw $this->failure;
        }
        $this->inner->deliver($event);
    }
}
