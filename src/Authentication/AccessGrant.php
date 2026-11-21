<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Authentication;

use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Support\Cast;

/**
 * What PayPal's token endpoint answers with: a bearer token, when it stops working, and what the
 * app it was issued to may do.
 *
 *     {"scope": "https://uri.paypal.com/services/reporting/search/read ...",
 *      "access_token": "A21AAF...", "token_type": "Bearer", "app_id": "APP-80W284485P519543T",
 *      "expires_in": 31668, "nonce": "2026-09-15T..."}
 *
 * THERE IS NO REFRESH TOKEN. The client credentials grant issues none; an expired token is
 * replaced by asking again with the client id and secret.
 *
 * THE SCOPE IS WHAT THE APP MAY DO - a space-separated list of URIs for the features ticked for the
 * REST app in PayPal's developer dashboard, thirty of them for a sandbox app measured 2026-09-15.
 * That makes it the cheapest answer to "is Transaction Search enabled".
 *
 * IT IS NOT A LIST OF WHAT THE APP CAN CHANGE BEING ABSENT. Measured 2026-11-21, an app with only
 * Transaction Search and Subscriptions ticked still carried `payments/refund` and other write
 * scopes; PayPal has no read-only app. What keeps this package read-only is that it wraps no write.
 *
 * IT IS STALE AFTER THE DASHBOARD CHANGES, until the token expires. Measured 2026-11-21:
 * changing an existing app's features did not reach its token, and asking again answered the same
 * unexpired token with the old scopes - about nine hours. A new app's first token carries its
 * features at once.
 *
 * The expiry is stored as an instant rather than as `expires_in`, because a number of seconds
 * means nothing once the grant has sat in a cache for an hour.
 */
final class AccessGrant implements \JsonSerializable
{
    /**
     * How long before the recorded expiry a token is treated as expired, so a request is not
     * sent with a token that dies in transit.
     */
    public const EXPIRY_LEEWAY = 60;

    /**
     * The scope a token carries when its app has Transaction Search enabled. Not in any of the
     * specifications; measured 2026-09-15 on a sandbox app with the feature ticked.
     */
    public const TRANSACTION_SEARCH_SCOPE = 'https://uri.paypal.com/services/reporting/search/read';

    /**
     * The scope a token carries when its app has Subscriptions enabled, which reading a
     * subscription needs: measured 2026-11-21, a token with Transaction Search and without this was
     * refused `GET /v1/billing/subscriptions/{id}` with 403 `PERMISSION_DENIED`.
     */
    public const SUBSCRIPTIONS_SCOPE = 'https://uri.paypal.com/services/subscriptions';

    public function __construct(
        #[\SensitiveParameter] public readonly string $accessToken,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly string $tokenType = 'Bearer',
        public readonly string $scope = '',
        public readonly ?string $appId = null,
    ) {
        if (trim($accessToken) === '') {
            throw new InvalidArgumentException('An access grant needs an access token.');
        }
    }

    /**
     * @param  array<string, mixed>  $body  the token endpoint's decoded response
     */
    public static function fromResponse(array $body, \DateTimeImmutable $issuedAt): self
    {
        $token = Cast::text($body['access_token'] ?? null);

        if ($token === null) {
            throw new InvalidArgumentException('PayPal answered the token request without an access_token.');
        }

        $expiresIn = max(0, Cast::int($body['expires_in'] ?? null) ?? 0);

        return new self(
            $token,
            $issuedAt->modify(sprintf('+%d seconds', $expiresIn)),
            Cast::text($body['token_type'] ?? null) ?? 'Bearer',
            Cast::string($body['scope'] ?? null) ?? '',
            Cast::text($body['app_id'] ?? null),
        );
    }

    /**
     * The portable form written by toArray(), for a store that holds arrays or JSON.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = Cast::datetime($data['expires_at'] ?? null);
        $token = Cast::text($data['access_token'] ?? null);

        if ($token === null || $expiresAt === null) {
            throw new InvalidArgumentException('A stored access grant needs access_token and expires_at.');
        }

        return new self(
            $token,
            $expiresAt,
            Cast::text($data['token_type'] ?? null) ?? 'Bearer',
            Cast::string($data['scope'] ?? null) ?? '',
            Cast::text($data['app_id'] ?? null),
        );
    }

    public function isExpired(\DateTimeImmutable $now, int $leeway = self::EXPIRY_LEEWAY): bool
    {
        return $now->modify(sprintf('+%d seconds', $leeway)) >= $this->expiresAt;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->scope)) ?: [], static fn (string $scope): bool => $scope !== ''));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes(), true);
    }

    /**
     * Whether the app had Transaction Search enabled when this token was issued.
     */
    public function canSearchTransactions(): bool
    {
        return $this->hasScope(self::TRANSACTION_SEARCH_SCOPE);
    }

    /**
     * Whether the app had Subscriptions enabled when this token was issued, which `Subscriptions`
     * needs. `Plans` is the same API and presumably the same feature; that is unmeasured.
     */
    public function canReadSubscriptions(): bool
    {
        return $this->hasScope(self::SUBSCRIPTIONS_SCOPE);
    }

    /**
     * THE PORTABLE FORM, AND IT CONTAINS THE TOKEN. This is what a TokenStore persists, so it is a
     * credential.
     *
     * @return array{access_token: string, token_type: string, scope: string, app_id: string|null, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
            'app_id' => $this->appId,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * Without the token - so a grant passed to a logger or json_encode() by mistake does not leak
     * it. toArray() is the deliberate form.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => sprintf('(%d characters)', strlen($this->accessToken)),
            'tokenType' => $this->tokenType,
            'scope' => $this->scope,
            'appId' => $this->appId,
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
