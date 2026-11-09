<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use DateTimeImmutable;

final readonly class Invoice
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $contact,
        public ?DateTimeImmutable $voidedAt,
    ) {}

    public function void(DateTimeImmutable $now): self
    {
        return new self($this->id, $this->organizationId, $this->contact, $now);
    }
}
