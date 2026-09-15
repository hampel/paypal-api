<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * One billing cycle of a plan - a trial or the regular cycle - with its price and how often it bills.
 */
final class BillingCycle extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $tenureType = null,
        public readonly ?int $sequence = null,
        public readonly ?int $totalCycles = null,
        public readonly ?string $intervalUnit = null,
        public readonly ?int $intervalCount = null,
        public readonly ?Money $fixedPrice = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        $frequency = Cast::object($row['frequency'] ?? null);

        return new self(
            Cast::text($row['tenure_type'] ?? null),
            Cast::int($row['sequence'] ?? null),
            Cast::int($row['total_cycles'] ?? null),
            Cast::text($frequency['interval_unit'] ?? null),
            Cast::int($frequency['interval_count'] ?? null),
            Cast::money(Cast::object($row['pricing_scheme'] ?? null)['fixed_price'] ?? null),
            $row,
        );
    }

    public function isTrial(): bool
    {
        return strtoupper((string) $this->tenureType) === 'TRIAL';
    }
}
