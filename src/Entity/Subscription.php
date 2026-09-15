<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Enum\SubscriptionStatus;
use Hampel\PayPal\Api\Support\Cast;

/**
 * A subscription, from `GET /v1/billing/subscriptions/{id}` - an id beginning `I-`.
 *
 * `customId` is the merchant's reference, given when the subscription was created, and it is the
 * same value the subscription's payments carry as `custom_field` in transaction search. Each payment
 * is a `T0002` row whose `referenceId` is this subscription's id.
 *
 * `plan` is present only when the subscription was read with the plan asked for.
 */
final class Subscription extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $status = null,
        public readonly ?string $statusChangeNote = null,
        public readonly ?\DateTimeImmutable $statusUpdatedAt = null,
        public readonly ?string $planId = null,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?string $quantity = null,
        public readonly ?string $customId = null,
        public readonly ?bool $planOverridden = null,
        public readonly ?Subscriber $subscriber = null,
        public readonly ?SubscriptionBilling $billing = null,
        public readonly ?Plan $plan = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
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
            Cast::text($row['id'] ?? null),
            Cast::text($row['status'] ?? null),
            Cast::text($row['status_change_note'] ?? null),
            Cast::datetime($row['status_update_time'] ?? null),
            Cast::text($row['plan_id'] ?? null),
            Cast::datetime($row['start_time'] ?? null),
            Cast::text($row['quantity'] ?? null),
            Cast::text($row['custom_id'] ?? null),
            Cast::bool($row['plan_overridden'] ?? null),
            Cast::nested($row['subscriber'] ?? null, Subscriber::fromArray(...)),
            Cast::nested($row['billing_info'] ?? null, SubscriptionBilling::fromArray(...)),
            Cast::nested($row['plan'] ?? null, Plan::fromArray(...)),
            Cast::datetime($row['create_time'] ?? null),
            Cast::datetime($row['update_time'] ?? null),
            $row,
        );
    }

    public function subscriptionStatus(): ?SubscriptionStatus
    {
        return Cast::enum(SubscriptionStatus::class, $this->status);
    }

    public function isActive(): bool
    {
        return $this->subscriptionStatus() === SubscriptionStatus::Active;
    }
}
