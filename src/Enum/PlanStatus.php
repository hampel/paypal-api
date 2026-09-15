<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Enum;

enum PlanStatus: string
{
    case Created = 'CREATED';
    case Inactive = 'INACTIVE';
    case Active = 'ACTIVE';
}
