<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * PayPal accepted the credential and refused the operation - HTTP 403.
 *
 * THE LIKELY CAUSE IS THE APP, NOT THE CODE. A REST app needs the feature ticked in PayPal's
 * developer dashboard, and the name differs by API - measured 2026-11-21:
 *
 * - transaction search and balances answer `NOT_AUTHORIZED` without Transaction Search, which
 *   `AccessGrant::canSearchTransactions()` answers without a request;
 * - a subscription read answers `PERMISSION_DENIED` without Subscriptions, which
 *   `AccessGrant::canReadSubscriptions()` answers.
 *
 * A token obtained before the feature was ticked does not carry it, and PayPal reissues an
 * unexpired token rather than minting another - so it takes until that token expires, about nine
 * hours. A new app's first token has its features at once.
 */
final class NotPermittedException extends ApiException
{
}
