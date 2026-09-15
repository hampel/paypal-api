<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * PayPal failed - HTTP 5xx. Not the caller's mistake, and not necessarily a transient one. The
 * debug id is what PayPal's support asks for.
 */
final class ServerException extends ApiException
{
}
