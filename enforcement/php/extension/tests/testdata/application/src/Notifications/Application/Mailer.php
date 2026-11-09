<?php

declare(strict_types=1);

namespace App\Notifications\Application;

interface Mailer
{
    public function send(string $address, string $text): void;
}
