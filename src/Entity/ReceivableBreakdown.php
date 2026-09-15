<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * What a capture was worth to the merchant: `seller_receivable_breakdown`.
 *
 * THE FEE AND NET ARE IN THE TRANSACTION'S CURRENCY. `gross`, `paypalFee` and `net` are all in the
 * currency the payer paid in. When the merchant's account receives in another currency, PayPal adds
 * `receivable` - the net converted - and the `exchangeRate` it used; `paypalFeeInReceivableCurrency`
 * appears only when the fee itself was charged in that currency. So for a USD payment into an AUD
 * account the documented shape is a USD gross, fee and net, then an AUD receivable at a rate. That
 * is from the specification's field descriptions, and unmeasured.
 *
 * PayPal documents this breakdown as absent while a capture is pending.
 */
final class ReceivableBreakdown extends Entity
{
    /**
     * @param  list<Money>  $platformFees
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Money $gross = null,
        public readonly ?Money $paypalFee = null,
        public readonly ?Money $paypalFeeInReceivableCurrency = null,
        public readonly ?Money $net = null,
        public readonly ?Money $receivable = null,
        public readonly ?ExchangeRate $exchangeRate = null,
        public readonly array $platformFees = [],
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
            Cast::money($row['gross_amount'] ?? null),
            Cast::money($row['paypal_fee'] ?? null),
            Cast::money($row['paypal_fee_in_receivable_currency'] ?? null),
            Cast::money($row['net_amount'] ?? null),
            Cast::money($row['receivable_amount'] ?? null),
            Cast::nested($row['exchange_rate'] ?? null, ExchangeRate::fromArray(...)),
            self::platformFees($row['platform_fees'] ?? null),
            $row,
        );
    }

    /**
     * Whether the net was converted into another currency before it reached the account.
     */
    public function isConverted(): bool
    {
        return $this->exchangeRate !== null || $this->receivable !== null;
    }

    /**
     * @return list<Money>
     */
    public static function platformFees(mixed $value): array
    {
        return array_values(array_filter(Cast::objects($value, static fn (array $fee): ?Money => Cast::money($fee['amount'] ?? null))));
    }
}
