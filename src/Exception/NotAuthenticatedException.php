<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * PayPal did not accept the credential - HTTP 401.
 *
 * TWO BODIES, FROM TWO KINDS OF ENDPOINT. The token endpoint answers a bad secret with an OAuth
 * error - `{"error": "invalid_client", "error_description": "Client Authentication failed"}`, and
 * nothing else, measured 2026-09-15 - which oauthError() reads. A data endpoint answers a missing,
 * expired or revoked token with `AUTHENTICATION_FAILURE` in PayPal's usual error shape, which is
 * documented and unmeasured.
 */
final class NotAuthenticatedException extends ApiException
{
    /**
     * The OAuth error code, when the token endpoint sent one - `invalid_client`.
     */
    public function oauthError(): ?string
    {
        return $this->error?->oauthError;
    }
}
