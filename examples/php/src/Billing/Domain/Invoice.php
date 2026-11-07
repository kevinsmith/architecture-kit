<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use DateTimeImmutable;

final readonly class Invoice
{
    /**
     * @param string $status stored status; an unrecognized value is an invalid state, not a load failure
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $status,
        public DateTimeImmutable $deadline,
        public int $version,
        public string $contact,
    ) {}

    /** Decides a void at the supplied time. Returns the next state or the reason for refusal. */
    public function void(DateTimeImmutable $now): self|Refusal
    {
        $refusal = match (Status::tryFrom($this->status)) {
            Status::Voided => Refusal::AlreadyVoided,
            Status::Settled => Refusal::Settled,
            Status::Open => $now < $this->deadline ? null : Refusal::Expired,
            null => Refusal::InvalidState,
        };

        return $refusal ?? \clone($this, ['status' => Status::Voided->value, 'version' => $this->version + 1]);
    }
}
