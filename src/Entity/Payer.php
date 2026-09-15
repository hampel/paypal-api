<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * Who paid, from a transaction search row's `payer_info` - present when the search asked for it.
 *
 * THE EMAIL IS THE PAYPAL ACCOUNT'S, not whatever the payer uses anywhere else. Matching a payment
 * to a customer by it misses everyone whose PayPal login differs from the address they gave the
 * merchant, which is why `Transaction::$customField` exists.
 */
final class Payer extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $accountId = null,
        public readonly ?string $email = null,
        public readonly ?string $givenName = null,
        public readonly ?string $surname = null,
        public readonly ?string $fullName = null,
        public readonly ?string $alternateFullName = null,
        public readonly ?string $countryCode = null,
        public readonly ?bool $verified = null,
        public readonly ?bool $addressVerified = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        $name = Cast::object($row['payer_name'] ?? null);

        return new self(
            Cast::text($row['account_id'] ?? null),
            Cast::text($row['email_address'] ?? null),
            Cast::text($name['given_name'] ?? null),
            Cast::text($name['surname'] ?? null),
            Cast::text($name['full_name'] ?? null),
            Cast::text($name['alternate_full_name'] ?? null),
            Cast::text($row['country_code'] ?? null),
            Cast::bool($row['payer_status'] ?? null),
            Cast::bool($row['address_status'] ?? null),
            $row,
        );
    }

    /**
     * The best name PayPal gave: the full name, the given name and surname, or the alternate name a
     * business account carries.
     */
    public function name(): ?string
    {
        $parts = trim(implode(' ', array_filter([$this->givenName, $this->surname], static fn (?string $part): bool => $part !== null)));

        return $this->fullName ?? ($parts !== '' ? $parts : null) ?? $this->alternateFullName;
    }
}
