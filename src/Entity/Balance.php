<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * What the account holds in one currency.
 */
final class Balance extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $currency = null,
        public readonly ?bool $primary = null,
        public readonly ?Money $total = null,
        public readonly ?Money $available = null,
        public readonly ?Money $withheld = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        return new self(
            Cast::text($row['currency'] ?? null),
            Cast::bool($row['primary'] ?? null),
            Cast::money($row['total_balance'] ?? null),
            Cast::money($row['available_balance'] ?? null),
            Cast::money($row['withheld_balance'] ?? null),
            $row,
        );
    }
}
