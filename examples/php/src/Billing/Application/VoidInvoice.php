<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Access\Contracts\Authorizer;
use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Contracts\Permissions;
use App\Billing\Contracts\Recipient;
use App\Billing\Domain\Refusal;
use Psr\Clock\ClockInterface;

/** Holds no invocation state, so one instance can serve concurrent or long-lived workers. */
final readonly class VoidInvoice
{
    public function __construct(
        private InvoiceStore $store,
        private Authorizer $access,
        private ClockInterface $clock,
    ) {}

    /**
     * @param string $actor trusted identity established by a driving adapter
     */
    public function handle(string $actor, VoidCommand $command): Voided|Denial|Refusal
    {
        $invoice = $this->store->load($command->invoiceId);
        if ($invoice === null) {
            return Denial::NotFound;
        }
        // The scope comes from the stored invoice; the caller's claim must match it.
        if ($actor === '' || $command->organizationId !== $invoice->organizationId) {
            return Denial::Forbidden;
        }
        if (!$this->access->can($actor, Permissions::VOID_INVOICE, $invoice->organizationId)) {
            return Denial::Forbidden;
        }
        $now = $this->clock->now();
        $next = $invoice->void($now);
        if ($next instanceof Refusal) {
            return $next;
        }
        $event = new InvoiceVoided(
            id: "{$invoice->id}:void:{$next->version}",
            invoiceId: $invoice->id,
            organizationId: $invoice->organizationId,
            occurredAt: (int) $now->format('U') * 1_000_000_000 + (int) $now->format('u') * 1_000,
            recipients: [new Recipient($invoice->contact)],
        );
        try {
            $this->store->run(static function (InvoiceTransaction $tx) use ($invoice, $next, $event): void {
                if (!$tx->save($invoice, $next)) {
                    throw new ConcurrentChange("invoice {$invoice->id} changed concurrently");
                }
                $tx->addEvent($event);
            });
        } catch (ConcurrentChange) {
            return Denial::Conflict;
        }

        return new Voided($event->id);
    }
}
