<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Enum;

enum SubscriptionStatus: string
{
    case ApprovalPending = 'APPROVAL_PENDING';
    case Approved = 'APPROVED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
}
