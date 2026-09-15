<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * Where a subscription's billing stands: what was last paid and when, when it bills next, and what
 * is owed. PayPal documents these as populated only once the subscription has been active.
 */
final class SubscriptionBilling extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Money $outstandingBalance = null,
        public readonly ?Money $lastPaymentAmount = null,
        public readonly ?\DateTimeImmutable $lastPaymentAt = null,
        public readonly ?\DateTimeImmutable $nextBillingAt = null,
        public readonly ?\DateTimeImmutable $finalPaymentAt = null,
        public readonly ?int $failedPaymentsCount = null,
        public readonly ?FailedPayment $lastFailedPayment = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        $last = Cast::object($row['last_payment'] ?? null);

        return new self(
            Cast::money($row['outstanding_balance'] ?? null),
            Cast::money($last['amount'] ?? null),
            Cast::datetime($last['time'] ?? null),
            Cast::datetime($row['next_billing_time'] ?? null),
            Cast::datetime($row['final_payment_time'] ?? null),
            Cast::int($row['failed_payments_count'] ?? null),
            Cast::nested($row['last_failed_payment'] ?? null, FailedPayment::fromArray(...)),
            $row,
        );
    }
}
