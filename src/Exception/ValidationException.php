<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * PayPal refused the request as malformed - HTTP 400, `INVALID_REQUEST`.
 *
 * The useful part is issues(): each detail names the field and a stable issue code -
 * `INVALID_PARAMETER_SYNTAX`, `MISSING_REQUIRED_PARAMETER` - where the message is the same
 * sentence for every 400 PayPal sends.
 */
final class ValidationException extends ApiException
{
}
