<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

use Hampel\PayPal\Api\Connection;
use Hampel\PayPal\Api\Endpoint\Authorisation;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A REST app's client id and secret, exchanged for a bearer token that is reused until it expires.
 *
 *     new ClientCredentials($clientId, $secret, $store)
 *
 * The token is obtained on the first request, kept in the TokenStore, and replaced when it expires
 * or when PayPal refuses it. THE STORE IS THE PART TO GET RIGHT - see TokenStore.
 *
 * THE STORE KEY NAMES THE HOST AS WELL AS THE CLIENT ID, so a sandbox token is never offered to
 * the live API from a store both share. The client id is hashed into it, so a cache listing does
 * not double as a list of the apps that have PayPal access.
 *
 * WHICH ACCOUNT THIS READS IS DECIDED BY THE APP. A REST app belongs to one PayPal account, and
 * its token reads that account's transactions; there is no account id to configure.
 */
final class ClientCredentials implements Authentication
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    private readonly TokenStore $store;

    /**
     * @param  (callable(): \DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $secret,
        ?TokenStore $store = null,
        ?callable $clock = null,
    ) {
        if (trim($clientId) === '' || trim($secret) === '') {
            throw new InvalidArgumentException('PayPal client credentials need both a client id and a secret.');
        }

        $this->store = $store ?? new InMemoryTokenStore();
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function keyFor(string $clientId, string $host): string
    {
        return 'paypal-oauth:' . hash('sha256', strtolower($host) . '|' . trim($clientId));
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function authenticate(RequestInterface $request, Connection $connection): RequestInterface
    {
        // Always "Bearer", whatever token_type said: it is the only scheme PayPal's data
        // endpoints document, and echoing an unexpected value would send it.
        return $request->withHeader('Authorization', 'Bearer ' . $this->currentGrant($connection)->accessToken);
    }

    /**
     * The grant in use, obtaining one if there is none or it has expired. Public for an
     * application that wants the scopes - is Transaction Search enabled? - without a data request.
     */
    public function currentGrant(Connection $connection): AccessGrant
    {
        $key = $this->key($connection);
        $current = $this->store->get($key);
        $now = ($this->clock)();

        if ($current !== null && !$current->isExpired($now)) {
            return $current;
        }

        $grant = (new Authorisation($connection))->token($this->clientId, $this->secret, $now);
        $this->store->put($key, $grant);

        return $grant;
    }

    public function recover(Connection $connection): bool
    {
        $this->store->forget($this->key($connection));

        return true;
    }

    /**
     * Forget the stored grant, so the next request asks PayPal for a token again.
     *
     * NOT A WAY TO PICK UP NEW SCOPES. PayPal answers the same token while it is unexpired -
     * measured 2026-09-15 - so after the app's features change, the answer carries the old ones
     * until that token's nine hours are up (measured 2026-11-21).
     */
    public function forget(Connection $connection): void
    {
        $this->store->forget($this->key($connection));
    }

    public function describe(): string
    {
        $length = strlen($this->clientId);

        return $length > 12
            ? sprintf('PayPal client credentials for app %s...%s', substr($this->clientId, 0, 4), substr($this->clientId, -4))
            : 'PayPal client credentials';
    }

    public function __toString(): string
    {
        return $this->describe();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['credential' => $this->describe()];
    }

    private function key(Connection $connection): string
    {
        return self::keyFor($this->clientId, $connection->config()->host());
    }
}
