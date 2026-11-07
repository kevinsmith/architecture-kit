<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notifications\Application\Mailer;
use App\Notifications\Application\Message;
use RuntimeException;

/** Records sent messages; fails the next $failures sends. */
final class Mailbox implements Mailer
{
    /** @var list<Message> */
    public array $messages = [];

    public int $failures = 0;

    public function send(Message $message): void
    {
        if ($this->failures > 0) {
            $this->failures--;
            throw new RuntimeException('mail unavailable');
        }
        $this->messages[] = $message;
    }

    /** @return list<string> */
    public function eventIds(): array
    {
        return array_map(static fn(Message $message): string => $message->eventId, $this->messages);
    }
}
