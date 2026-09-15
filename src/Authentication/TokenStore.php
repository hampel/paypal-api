<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

/**
 * Where ClientCredentials keeps the token it obtained, so the next process does not ask again.
 *
 * A PHP application is many short processes. With the default in-memory store, a web application
 * requests a token on every page view that touches PayPal and a queue worker on every restart: a
 * doubled request each time, for a token that lasts nine hours - `expires_in` was about 32400, measured
 * 2026-09-15. A store backed by something shared - a cache, a database row - removes it.
 *
 * THE COST IS REQUESTS, NOT TOKENS. Measured the same day, asking again while a token is unexpired
 * answers the same token, so processes without a shared store still hold one token between them.
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
