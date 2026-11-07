<?php

declare(strict_types=1);

namespace App\Notifications\Application;

final readonly class Message
{
    public function __construct(
        public string $eventId,
        public string $recipient,
        public string $body,
    ) {}
}
