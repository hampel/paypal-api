<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Enum\PlanStatus;
use Hampel\PayPal\Api\Support\Cast;

/**
 * A billing plan - the price and cycle a subscription is created against - an id beginning `P-`.
 */
final class Plan extends Entity
{
    /**
     * @param  list<BillingCycle>  $billingCycles
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $productId = null,
        public readonly ?string $name = null,
        public readonly ?string $status = null,
        public readonly ?string $description = null,
        public readonly array $billingCycles = [],
        public readonly ?bool $quantitySupported = null,
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
            Cast::text($row['product_id'] ?? null),
            Cast::text($row['name'] ?? null),
            Cast::text($row['status'] ?? null),
            Cast::text($row['description'] ?? null),
            Cast::objects($row['billing_cycles'] ?? null, BillingCycle::fromArray(...)),
            Cast::bool($row['quantity_supported'] ?? null),
            Cast::datetime($row['create_time'] ?? null),
            Cast::datetime($row['update_time'] ?? null),
            $row,
        );
    }

    public function planStatus(): ?PlanStatus
    {
        return Cast::enum(PlanStatus::class, $this->status);
    }

    /**
     * The regular billing cycle - the price a subscriber pays once any trial is over.
     */
    public function regularCycle(): ?BillingCycle
    {
        foreach ($this->billingCycles as $cycle) {
            if (!$cycle->isTrial()) {
                return $cycle;
            }
        }

        return null;
    }
}
