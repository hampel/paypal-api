<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request never got an answer: DNS, TLS, a timeout, a refused connection.
 *
 * Distinct from every ApiException, all of which mean PayPal replied and the reply was not a
 * success. Every operation this package wraps reads rather than moves money, so a retry here is
 * safe - which would not be true of a capture or a refund, and is one reason neither is wrapped.
 */
final class RequestException extends PayPalException
{
    public static function for(string $method, string $uri, ClientExceptionInterface $previous): self
    {
        return new self(
            sprintf('Could not reach the PayPal API for %s %s: %s', $method, $uri, $previous->getMessage()),
            0,
            $previous
        );
    }
}
