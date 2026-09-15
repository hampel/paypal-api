<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * HTTP 429, `RATE_LIMIT_REACHED`.
 *
 * MAPPED DEFENSIVELY. PayPal publishes no request limit for these APIs and nothing here has
 * observed one. `retryAfter` is the `Retry-After` header in seconds, when one was sent.
 */
final class TooManyRequestsException extends ApiException
{
}
