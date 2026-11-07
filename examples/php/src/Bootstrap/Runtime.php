<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Access\Application\Authorization;
use App\Access\Contracts\Authorizer;
use App\Access\Infrastructure\PdoGrants;
use App\Billing\Application\Dispatcher;
use App\Billing\Application\VoidInvoice;
use App\Billing\Infrastructure\PdoInvoiceStore;
use App\Notifications\Application\InvoiceVoidedNotifier;
use App\Notifications\Application\Mailer;
use App\Notifications\Presentation\MessageInput;
use App\Platform\SystemClock;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Composition root. Exposes entry points, never adapters or the connection, so
 * callers cannot bypass use cases. Every service is shared and holds no
 * invocation state, which keeps it safe in long-lived workers.
 */
final readonly class Runtime
{
    private function __construct(
        public VoidInvoice $voidInvoice,
        public Authorizer $access,
        public Dispatcher $dispatcher,
        public MessageInput $messages,
    ) {}

    public static function open(string $path, Mailer $mailer, ?ClockInterface $clock = null): self
    {
        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
        $invoices = new PdoInvoiceStore($pdo);
        $grants = new PdoGrants($pdo);
        $invoices->createSchema();
        $grants->createSchema();
        $access = new Authorization($grants);
        $notifier = new InvoiceVoidedNotifier($mailer);

        return new self(
            voidInvoice: new VoidInvoice($invoices, $access, $clock ?? new SystemClock()),
            access: $access,
            dispatcher: new Dispatcher($invoices, $notifier),
            messages: new MessageInput($notifier),
        );
    }
}
