<?php

declare(strict_types=1);

namespace App\Notifications\Presentation;

use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Contracts\MalformedEvent;
use App\Notifications\Application\InvoiceVoidedNotifier;

/** Driving adapter for a message consumer: validates the untrusted body, then invokes Application. */
final readonly class MessageInput
{
    public function __construct(
        private InvoiceVoidedNotifier $notifier,
    ) {}

    /** @throws MalformedEvent */
    public static function decode(string $body): InvoiceVoided
    {
        return InvoiceVoided::fromJson($body);
    }

    /** @throws MalformedEvent */
    public function receive(string $body): void
    {
        $this->notifier->deliver(self::decode($body));
    }
}
