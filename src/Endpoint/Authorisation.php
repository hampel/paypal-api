<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;

/**
 * Obtaining a token, at `POST /v1/oauth2/token`.
 *
 * Most applications never call this directly - Authentication\ClientCredentials does it for them.
 *
 * The client id and secret travel as HTTP Basic credentials, and the body is the form
 * `grant_type=client_credentials`, as PayPal documents. The request is sent without a bearer token.
 */
final class Authorisation extends Endpoint
{
    /**
     * Exchange an app's client id and secret for a grant.
     *
     * A refused id or secret raises NotAuthenticatedException, whose oauthError() is PayPal's
     * code - documented as `invalid_client`.
     */
    public function token(string $clientId, #[\SensitiveParameter] string $secret, ?\DateTimeImmutable $now = null): AccessGrant
    {
        if (trim($clientId) === '' || trim($secret) === '') {
            throw new InvalidArgumentException('A PayPal token request needs both a client id and a secret.');
        }

        $issuedAt = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $connection = $this->connection->unauthenticated();

        $request = $connection
            ->withForm($connection->request('POST', 'v1/oauth2/token'), ['grant_type' => 'client_credentials'])
            ->withHeader('Authorization', 'Basic ' . base64_encode(trim($clientId) . ':' . trim($secret)));

        return AccessGrant::fromResponse($connection->send($request)->object(), $issuedAt);
    }
}
