<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * A 4xx this package has no more specific type for - a 409, a 415.
 */
final class ClientException extends ApiException
{
}
