<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Entity\Capture;
use Hampel\PayPal\Api\Support\Cast;

/**
 * Captured payments, at `/v2/payments/captures`.
 *
 * READ-ONLY. Refunding a capture moves money, and nothing this package is for needs to; the API's
 * refund operation is deliberately not wrapped.
 */
final class Captures extends Endpoint
{
    /**
     * One capture. Raises NotFoundException when PayPal knows no such capture.
     */
    public function get(string $id): Capture
    {
        return $this->apiObject($this->path($id), Capture::fromArray(...));
    }

    /**
     * One capture, or null when PayPal knows no such capture.
     */
    public function find(string $id): ?Capture
    {
        return $this->apiFind($this->path($id), Capture::fromArray(...));
    }

    private function path(string $id): string
    {
        return 'v2/payments/captures/' . Cast::pathSegment($id, 'capture');
    }
}
