<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Enum;

/**
 * A captured payment's status. A subscription transaction's status takes the same values.
 */
enum CaptureStatus: string
{
    case Completed = 'COMPLETED';
    case Declined = 'DECLINED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Pending = 'PENDING';
    case Refunded = 'REFUNDED';
    case Failed = 'FAILED';
}
