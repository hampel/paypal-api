<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * A subscription's last failed payment. PayPal returns it only when the subscription is read with
 * `fields=last_failed_payment` - see Subscriptions::get().
 */
final class FailedPayment extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Money $amount = null,
        public readonly ?\DateTimeImmutable $failedAt = null,
        public readonly ?string $reasonCode = null,
        public readonly ?\DateTimeImmutable $nextRetryAt = null,
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
            Cast::money($row['amount'] ?? null),
            Cast::datetime($row['time'] ?? null),
            Cast::text($row['reason_code'] ?? null),
            Cast::datetime($row['next_payment_retry_time'] ?? null),
            $row,
        );
    }
}
