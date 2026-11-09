<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Access\Contracts\Authorizer;
use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Contracts\Recipient;
use DateTimeImmutable;

final readonly class VoidInvoice
{
    public function __construct(
        private InvoiceStore $store,
        private Authorizer $access,
    ) {}

    public function handle(string $actor, string $id, DateTimeImmutable $now): bool
    {
        $invoice = $this->store->load($id);
        if ($invoice === null || !$this->access->can($actor, $invoice->organizationId)) {
            return false;
        }
        $event = new InvoiceVoided($invoice->id, [new Recipient($invoice->contact)]);
        $this->store->run(static function (InvoiceTransaction $tx) use ($invoice, $now, $event): void {
            $tx->save($invoice->void($now));
            $tx->addEvent($event);
        });

        return true;
    }
}
