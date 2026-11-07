<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Contracts\InvoiceVoidedSubscriber;
use Override;

/** Builds each message from the event alone; it queries no other module. */
final readonly class InvoiceVoidedNotifier implements InvoiceVoidedSubscriber
{
    public function __construct(
        private Mailer $mailer,
    ) {}

    #[Override]
    public function deliver(InvoiceVoided $event): void
    {
        foreach ($event->recipients as $recipient) {
            $this->mailer->send(new Message($event->id, $recipient->address, "Invoice {$event->invoiceId} was voided."));
        }
    }
}
