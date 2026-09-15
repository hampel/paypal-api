<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

use Hampel\PayPal\Api\Connection;
use Psr\Http\Message\RequestInterface;

/**
 * How a request proves who it is.
 *
 * PayPal's REST APIs take one kind of credential on a data request: a bearer token, obtained by
 * exchanging an app's client id and secret. ClientCredentials does that exchange and keeps the
 * token; AccessToken is a token obtained elsewhere.
 *
 * The connection is passed in because obtaining a token is a request of its own, and it must go
 * through the same transport as everything else.
 */
interface Authentication
{
    public function authenticate(RequestInterface $request, Connection $connection): RequestInterface;

    /**
     * PayPal answered a request with 401. Answer whether sending it again might now succeed.
     *
     * A credential that can obtain a fresh token answers true after discarding the one it used,
     * since a token can be revoked before it expires. A static token answers false. Connection
     * retries at most once, so a true here cannot loop.
     */
    public function recover(Connection $connection): bool;

    /**
     * What this credential is, for a log line or an exception message.
     *
     * MUST NOT INCLUDE A SECRET, or any part of one long enough to be useful.
     */
    public function describe(): string;
}
