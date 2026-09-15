<?php

/**
 * Exercise: obtain an OAuth token and print what it grants - reads only.
 *
 * QUESTIONS THIS SETTLES THAT THE SUITE CANNOT:
 *
 *   - what is the real `expires_in`? PayPal's authentication page shows 31668 seconds, which is one
 *     sample, not a lifetime.
 *   - DOES THE SCOPE NAME TRANSACTION SEARCH? AccessGrant::TRANSACTION_SEARCH_SCOPE is not in any
 *     specification. If the app has Transaction Search ticked and this prints "no", the constant is
 *     wrong and canSearchTransactions() lies.
 *   - does a second token request answer the SAME token? PayPal is commonly described as reissuing
 *     the unexpired token rather than minting another, which decides whether several processes
 *     without a shared TokenStore hold one token between them or one each.
 *   - what does a wrong secret answer - status, body, and whether it is the OAuth error shape
 *     NotAuthenticatedException::oauthError() reads?
 *   - does a response carry `PayPal-Debug-Id`, or anything describing a rate limit?
 *
 * Needs PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET. Sends three token requests.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Exception\NotAuthenticatedException;

require __DIR__ . '/lib/harness.php';

$io->title('paypal · token');

$paypal = harness_client($io);
$failure = null;

try {
    $grant = $paypal->verify();
    $seconds = $grant->expiresAt->getTimestamp() - time();

    $io->success('the client credentials grant worked');
    $io->values([
        'app id' => $grant->appId ?? '(none)',
        'token type' => $grant->tokenType,
        'expires in' => sprintf('about %d seconds (%.1f hours)', $seconds, $seconds / 3600),
        'scopes' => (string) count($grant->scopes()),
        'Transaction Search scope' => $grant->canSearchTransactions() ? 'yes' : 'NO - either the app lacks it, or the constant is wrong',
    ]);

    $io->line();
    $io->info('every scope');

    foreach ($grant->scopes() as $scope) {
        $io->line('  ' . $scope);
    }

    $io->line();
    $io->info('a second token request, bypassing the store - same token?');

    $second = $paypal->authorisation()->token((string) getenv('PAYPAL_CLIENT_ID'), (string) getenv('PAYPAL_CLIENT_SECRET'));

    $io->values([
        'access token' => $second->accessToken === $grant->accessToken ? 'SAME - PayPal reissues the unexpired token' : 'DIFFERENT - each request mints a new one',
        'expires in' => sprintf('about %d seconds', $second->expiresAt->getTimestamp() - time()),
    ]);

    $io->line();
    $io->info('a deliberately wrong secret, raw');

    $connection = $paypal->connection()->unauthenticated();
    $bad = $connection
        ->withForm($connection->request('POST', 'v1/oauth2/token'), ['grant_type' => 'client_credentials'])
        ->withHeader('Authorization', 'Basic ' . base64_encode(getenv('PAYPAL_CLIENT_ID') . ':not-the-secret'));
    $response = $connection->client()->sendRequest($bad);

    $io->line('  ' . harness_describe($response));

    try {
        $paypal->authorisation()->token((string) getenv('PAYPAL_CLIENT_ID'), 'not-the-secret');
        $io->error('  the package accepted a wrong secret');
    } catch (NotAuthenticatedException $e) {
        $io->line(sprintf('  as the package reads it: %s, oauthError() = %s', $e::class, $e->oauthError() ?? 'null'));
    }

    $io->line();
    $io->info('headers on the token response that might matter');

    $interesting = array_filter(
        array_keys($response->getHeaders()),
        static fn (string $name): bool => preg_match('/debug|rate|limit|quota|remaining|retry/i', $name) === 1
    );

    if ($interesting === []) {
        $io->line('  none');
    }

    foreach ($interesting as $name) {
        $io->values([$name => $response->getHeaderLine($name)]);
    }
} catch (ExceptionInterface $e) {
    $failure = $e;
}

if ($failure !== null) {
    $io->error($failure::class);
    $io->error($failure->getMessage());

    exit(1);
}
