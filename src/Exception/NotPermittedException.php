<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * PayPal accepted the credential and refused the operation - HTTP 403, `NOT_AUTHORIZED`.
 *
 * THE LIKELY CAUSE ON TRANSACTION SEARCH IS THE APP, NOT THE CODE. A REST app must have the
 * Transaction Search feature ticked in PayPal's developer dashboard, and a token issued before it
 * was ticked does not carry it: the scope is fixed when the token is issued, so forget the stored
 * grant after changing the app. `AccessGrant::canSearchTransactions()` answers without a request.
 * What the refusal looks like is documented as this 403, and unmeasured.
 */
final class NotPermittedException extends ApiException
{
}
