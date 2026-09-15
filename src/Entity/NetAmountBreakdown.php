<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * How a refund's net was taken from an account holding another currency: the amount payable, what
 * it was converted to, and the rate.
 */
final class NetAmountBreakdown extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Money $payable = null,
        public readonly ?Money $converted = null,
        public readonly ?ExchangeRate $exchangeRate = null,
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
            Cast::money($row['payable_amount'] ?? null),
            Cast::money($row['converted_amount'] ?? null),
            Cast::nested($row['exchange_rate'] ?? null, ExchangeRate::fromArray(...)),
            $row,
        );
    }
}
