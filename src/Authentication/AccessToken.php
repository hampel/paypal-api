<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

use Hampel\PayPal\Api\Connection;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A bearer token the caller already holds, sent as it is and never renewed.
 *
 * For an application that obtains PayPal's tokens itself. It EXPIRES, and nothing here notices
 * until PayPal answers 401. ClientCredentials is the credential that renews itself.
 */
final class AccessToken implements Authentication
{
    private readonly string $token;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('A PayPal access token is required.');
        }

        $this->token = $token;
    }

    public function authenticate(RequestInterface $request, Connection $connection): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    public function recover(Connection $connection): bool
    {
        return false;
    }

    public function describe(): string
    {
        return sprintf('a PayPal access token of %d characters', strlen($this->token));
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
        return ['token' => $this->describe()];
    }
}
