<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

/**
 * Billing publishes this subscription port. PHP classes must name the interface
 * they implement, so a subscriber depends on Billing; Billing never names it.
 */
interface InvoiceVoidedSubscriber
{
    public function deliver(InvoiceVoided $event): void;
}
