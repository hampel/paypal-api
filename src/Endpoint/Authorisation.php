<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Support\Cast;

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
     * code - documented as `invalid_client`. A success with no token in it - an empty 200, `{}`, or
     * another service's answer behind a misdirected base URI - raises MalformedResponseException,
     * like every other unusable answer, rather than AccessGrant's InvalidArgumentException, which
     * would read as a bug in the caller.
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

        $response = $connection->send($request);

        if (Cast::text($response->object()['access_token'] ?? null) === null) {
            throw new MalformedResponseException(
                'PayPal answered the token request (POST v1/oauth2/token) without an access_token.',
                $response->status,
                null,
                $response->body === null ? '' : (string) json_encode($response->body),
                null,
                $response->meta->debugId(),
            );
        }

        return AccessGrant::fromResponse($response->object(), $issuedAt);
    }
}
