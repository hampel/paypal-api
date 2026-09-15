<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Enum\CaptureStatus;
use Hampel\PayPal\Api\Support\Cast;

/**
 * A captured payment - money actually taken, as opposed to an order approved or an authorisation
 * held - from `GET /v2/payments/captures/{id}`.
 *
 * `breakdown` carries the fee, the net and any conversion; see ReceivableBreakdown. `customId` is
 * the merchant's own reference, set on the order's purchase unit at checkout.
 */
final class Capture extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $status = null,
        public readonly ?string $statusReason = null,
        public readonly ?Money $amount = null,
        public readonly ?string $invoiceId = null,
        public readonly ?string $customId = null,
        public readonly ?bool $finalCapture = null,
        public readonly ?ReceivableBreakdown $breakdown = null,
        public readonly ?string $orderId = null,
        public readonly ?string $authorizationId = null,
        public readonly ?string $payeeEmail = null,
        public readonly ?string $payeeMerchantId = null,
        public readonly ?string $sellerProtection = null,
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
        $related = Cast::object(Cast::object($row['supplementary_data'] ?? null)['related_ids'] ?? null);
        $payee = Cast::object($row['payee'] ?? null);

        return new self(
            Cast::text($row['id'] ?? null),
            Cast::text($row['status'] ?? null),
            Cast::text(Cast::object($row['status_details'] ?? null)['reason'] ?? null),
            Cast::money($row['amount'] ?? null),
            Cast::text($row['invoice_id'] ?? null),
            Cast::text($row['custom_id'] ?? null),
            Cast::bool($row['final_capture'] ?? null),
            Cast::nested($row['seller_receivable_breakdown'] ?? null, ReceivableBreakdown::fromArray(...)),
            Cast::text($related['order_id'] ?? null),
            Cast::text($related['authorization_id'] ?? null),
            Cast::text($payee['email_address'] ?? null),
            Cast::text($payee['merchant_id'] ?? null),
            Cast::text(Cast::object($row['seller_protection'] ?? null)['status'] ?? null),
            Cast::datetime($row['create_time'] ?? null),
            Cast::datetime($row['update_time'] ?? null),
            $row,
        );
    }

    public function captureStatus(): ?CaptureStatus
    {
        return Cast::enum(CaptureStatus::class, $this->status);
    }

    public function fee(): ?Money
    {
        return $this->breakdown?->paypalFee;
    }

    public function net(): ?Money
    {
        return $this->breakdown?->net;
    }
}
