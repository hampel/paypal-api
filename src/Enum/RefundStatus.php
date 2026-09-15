<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Enum;

enum RefundStatus: string
{
    case Cancelled = 'CANCELLED';
    case Failed = 'FAILED';
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
}
