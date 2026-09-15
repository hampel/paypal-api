<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * What a refund cost the merchant: `seller_payable_breakdown`.
 *
 * `paypalFee` is the fee PayPal RETURNED with the refund, which PayPal documents "might not match
 * the PayPal fee that the payee paid when the payment was captured" - so a refund's fee is read
 * here, never assumed from the capture. `totalRefunded` is every refund against the capture so far,
 * this one included.
 */
final class PayableBreakdown extends Entity
{
    /**
     * @param  list<Money>  $platformFees
     * @param  list<NetAmountBreakdown>  $netAmountBreakdown
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Money $gross = null,
        public readonly ?Money $paypalFee = null,
        public readonly ?Money $paypalFeeInReceivableCurrency = null,
        public readonly ?Money $net = null,
        public readonly ?Money $netInReceivableCurrency = null,
        public readonly ?Money $totalRefunded = null,
        public readonly array $platformFees = [],
        public readonly array $netAmountBreakdown = [],
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
            Cast::money($row['net_amount_in_receivable_currency'] ?? null),
            Cast::money($row['total_refunded_amount'] ?? null),
            ReceivableBreakdown::platformFees($row['platform_fees'] ?? null),
            Cast::objects($row['net_amount_breakdown'] ?? null, NetAmountBreakdown::fromArray(...)),
            $row,
        );
    }
}
