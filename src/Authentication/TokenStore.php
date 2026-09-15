<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

/**
 * Where ClientCredentials keeps the token it obtained, so the next process does not ask again.
 *
 * A PHP application is many short processes. With the default in-memory store, a web application
 * requests a token on every page view that touches PayPal and a queue worker on every restart:
 * a doubled request each time, against a token PayPal documents as lasting about nine hours. A
 * store backed by something shared - a cache, a database row - removes it.
 *
 * Implementations should treat the grant as a credential: it reads the account's transactions for
 * as long as it lasts. AccessGrant::toArray() and fromArray() are the portable form, for a store
 * that cannot hold objects.
 */
interface TokenStore
{
    public function get(string $key): ?AccessGrant;

    public function put(string $key, AccessGrant $grant): void;

    public function forget(string $key): void;
}
