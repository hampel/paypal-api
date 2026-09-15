<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * The request was well-formed and PayPal still would not act on it - HTTP 422,
 * `UNPROCESSABLE_ENTITY`, a business rule rather than a syntax error. The issue code says which
 * rule.
 */
final class UnprocessableException extends ApiException
{
}
