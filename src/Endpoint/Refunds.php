<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Entity\Refund;
use Hampel\PayPal\Api\Support\Cast;

/**
 * Refunds of captured payments, at `/v2/payments/refunds`. Read-only.
 */
final class Refunds extends Endpoint
{
    /**
     * One refund. Raises NotFoundException when PayPal knows no such refund.
     */
    public function get(string $id): Refund
    {
        return $this->apiObject($this->path($id), Refund::fromArray(...));
    }

    /**
     * One refund, or null when PayPal knows no such refund.
     */
    public function find(string $id): ?Refund
    {
        return $this->apiFind($this->path($id), Refund::fromArray(...));
    }

    private function path(string $id): string
    {
        return 'v2/payments/refunds/' . Cast::pathSegment($id, 'refund');
    }
}
