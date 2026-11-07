<?php

declare(strict_types=1);

namespace App\Notifications\Application;

/** External effect. Called only after the triggering transaction commits. */
interface Mailer
{
    public function send(Message $message): void;
}
