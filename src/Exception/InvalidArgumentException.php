<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * The caller asked for something that cannot be sent - a transaction search spanning more than the
 * 31 days PayPal allows, a page size past its maximum, a webhook notification missing a header.
 *
 * Raised before a request is made, which is the point of it.
 */
class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
