<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

use Hampel\PayPal\Api\Connection;
use Psr\Http\Message\RequestInterface;

/**
 * No bearer token - for the request that obtains one, which carries its own Basic credential.
 */
final class Anonymous implements Authentication
{
    public function authenticate(RequestInterface $request, Connection $connection): RequestInterface
    {
        return $request;
    }

    public function recover(Connection $connection): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'no credential';
    }
}
