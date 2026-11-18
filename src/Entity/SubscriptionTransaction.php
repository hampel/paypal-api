<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Enum\CaptureStatus;
use Hampel\PayPal\Api\Support\Cast;

/**
 * One payment a subscription made, from `GET /v1/billing/subscriptions/{id}/transactions`.
 *
 * Its `id` is the payment's transaction id - the same id transaction search reports for the
 * `T0002` row, measured on 2026-11-18. Its fee is positive here; transaction search's is negative.
 */
final class SubscriptionTransaction extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $status = null,
        public readonly ?Money $gross = null,
        public readonly ?Money $fee = null,
        public readonly ?Money $net = null,
        public readonly ?Money $shipping = null,
        public readonly ?Money $tax = null,
        public readonly ?Money $totalItem = null,
        public readonly ?string $payerEmail = null,
        public readonly ?string $payerName = null,
        public readonly ?\DateTimeImmutable $time = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        $amounts = Cast::object($row['amount_with_breakdown'] ?? null);
        $name = Cast::object($row['payer_name'] ?? null);
        $fullName = trim(implode(' ', array_filter(
            [Cast::text($name['given_name'] ?? null), Cast::text($name['surname'] ?? null)],
            static fn (?string $part): bool => $part !== null
        )));

        return new self(
            Cast::text($row['id'] ?? null),
            Cast::text($row['status'] ?? null),
            Cast::money($amounts['gross_amount'] ?? null),
            Cast::money($amounts['fee_amount'] ?? null),
            Cast::money($amounts['net_amount'] ?? null),
            Cast::money($amounts['shipping_amount'] ?? null),
            Cast::money($amounts['tax_amount'] ?? null),
            Cast::money($amounts['total_item_amount'] ?? null),
            Cast::text($row['payer_email'] ?? null),
            Cast::text($name['full_name'] ?? null) ?? ($fullName === '' ? null : $fullName),
            Cast::datetime($row['time'] ?? null),
            $row,
        );
    }

    public function captureStatus(): ?CaptureStatus
    {
        return Cast::enum(CaptureStatus::class, $this->status);
    }
}
