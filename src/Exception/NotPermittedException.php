<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * PayPal accepted the credential and refused the operation - HTTP 403, `NOT_AUTHORIZED`.
 *
 * THE LIKELY CAUSE ON TRANSACTION SEARCH IS THE APP, NOT THE CODE. A REST app must have the
 * Transaction Search feature ticked in PayPal's developer dashboard, and
 * `AccessGrant::canSearchTransactions()` answers whether it had, without a request. A token
 * obtained before the feature was ticked may not carry it, and PayPal reissues an unexpired token
 * rather than minting another - so it can take until that token expires. What the refusal looks
 * like is documented as this 403, and unmeasured.
 */
final class NotPermittedException extends ApiException
{
}
