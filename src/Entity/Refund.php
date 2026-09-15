<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Enum\RefundStatus;
use Hampel\PayPal\Api\Support\Cast;

/**
 * A refund of a captured payment, from `GET /v2/payments/refunds/{id}`.
 *
 * THE CAPTURE IT REFUNDED IS NAMED ONLY BY A LINK. A refund carries no `capture_id` field; its
 * `up` link points at the capture, and captureId() reads the id off the end of it.
 */
final class Refund extends Entity
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
        public readonly ?string $noteToPayer = null,
        public readonly ?PayableBreakdown $breakdown = null,
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
            Cast::text(Cast::object($row['status_details'] ?? null)['reason'] ?? null),
            Cast::money($row['amount'] ?? null),
            Cast::text($row['invoice_id'] ?? null),
            Cast::text($row['custom_id'] ?? null),
            Cast::text($row['note_to_payer'] ?? null),
            Cast::nested($row['seller_payable_breakdown'] ?? null, PayableBreakdown::fromArray(...)),
            Cast::datetime($row['create_time'] ?? null),
            Cast::datetime($row['update_time'] ?? null),
            $row,
        );
    }

    public function refundStatus(): ?RefundStatus
    {
        return Cast::enum(RefundStatus::class, $this->status);
    }

    /**
     * The id of the capture this refunded, from the `up` link - or null when the link is absent or
     * does not point at a capture.
     */
    public function captureId(): ?string
    {
        $up = $this->links()->first('up');

        return $up !== null && str_contains($up->href, '/captures/') ? $up->lastSegment() : null;
    }
}
