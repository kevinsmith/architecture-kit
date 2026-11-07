<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use JsonException;
use JsonSerializable;
use Override;

/**
 * Billing's published event. The constructor rejects incomplete values, so every
 * instance satisfies the schema. The JSON form matches the Go fixture: occurred_at
 * is UTC Unix nanoseconds encoded as a decimal string.
 */
final readonly class InvoiceVoided implements JsonSerializable
{
    private const array FIELDS = ['id', 'invoice_id', 'organization_id', 'occurred_at', 'recipients'];

    /**
     * @param list<Recipient> $recipients
     */
    public function __construct(
        public string $id,
        public string $invoiceId,
        public string $organizationId,
        public int $occurredAt,
        public array $recipients,
    ) {
        if ($id === '' || $invoiceId === '' || $organizationId === '' || $occurredAt <= 0 || $recipients === []) {
            throw new MalformedEvent('incomplete invoice-voided event');
        }
    }

    /**
     * Strictly decodes one event: exactly the published fields, no others. Objects
     * decode as objects, so a JSON object cannot pass as a list.
     *
     * @throws MalformedEvent
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = self::fields(\json_decode($json, false, 4, \JSON_THROW_ON_ERROR), self::FIELDS);
        } catch (JsonException $error) {
            throw new MalformedEvent('invalid JSON: ' . $error->getMessage(), 0, $error);
        }

        return new self(
            self::text($data['id'], 'id'),
            self::text($data['invoice_id'], 'invoice_id'),
            self::text($data['organization_id'], 'organization_id'),
            self::nanoseconds($data['occurred_at']),
            \array_map(
                static fn(mixed $recipient): Recipient => new Recipient(self::text(self::fields($recipient, ['address'])['address'], 'address')),
                self::items($data['recipients']),
            ),
        );
    }

    /**
     * @return array{id: string, invoice_id: string, organization_id: string, occurred_at: string, recipients: list<array{address: string}>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoiceId,
            'organization_id' => $this->organizationId,
            'occurred_at' => (string) $this->occurredAt,
            'recipients' => \array_map(static fn(Recipient $recipient): array => ['address' => $recipient->address], $this->recipients),
        ];
    }

    /**
     * @param list<string> $fields
     *
     * @return array<mixed>
     */
    private static function fields(mixed $value, array $fields): array
    {
        if (!\is_object($value)) {
            throw new MalformedEvent('expected an object with the fields ' . \implode(', ', $fields));
        }
        $value = \get_object_vars($value);
        $keys = \array_keys($value);
        \sort($keys);
        \sort($fields);
        if ($keys !== $fields) {
            throw new MalformedEvent('expected exactly the fields ' . \implode(', ', $fields));
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            throw new MalformedEvent('recipients must be a list');
        }

        return $value;
    }

    private static function text(mixed $value, string $field): string
    {
        if (!\is_string($value)) {
            throw new MalformedEvent("{$field} must be a string");
        }

        return $value;
    }

    private static function nanoseconds(mixed $value): int
    {
        if (!\is_string($value) || \preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1 || (string) (int) $value !== $value) {
            throw new MalformedEvent('occurred_at must be a decimal string of Unix nanoseconds');
        }

        return (int) $value;
    }
}
