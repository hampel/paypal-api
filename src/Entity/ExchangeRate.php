<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * The rate PayPal converted at: `value` units of the target currency for one of the source.
 *
 * The value stays a string, for the reason Money's does - the specification allows fifteen decimal
 * places, which a float does not keep.
 */
final class ExchangeRate extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $sourceCurrency = null,
        public readonly ?string $targetCurrency = null,
        public readonly ?string $value = null,
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
            Cast::text($row['source_currency'] ?? null),
            Cast::text($row['target_currency'] ?? null),
            Cast::text($row['value'] ?? null),
            $row,
        );
    }
}
