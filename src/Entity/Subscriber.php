<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * The PayPal account paying for a subscription.
 */
final class Subscriber extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $payerId = null,
        public readonly ?string $email = null,
        public readonly ?string $givenName = null,
        public readonly ?string $surname = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        $name = Cast::object($row['name'] ?? null);

        return new self(
            Cast::text($row['payer_id'] ?? null),
            Cast::text($row['email_address'] ?? null),
            Cast::text($name['given_name'] ?? null),
            Cast::text($name['surname'] ?? null),
            $row,
        );
    }

    public function name(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->givenName, $this->surname], static fn (?string $part): bool => $part !== null)));

        return $name === '' ? null : $name;
    }
}
