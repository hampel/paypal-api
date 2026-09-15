<?php

/**
 * Not an exercise. Discovery is glob('harness/*.php'), top level only, so a file down here is
 * required by the exercises that need it rather than listed as one of them.
 *
 * EVERY EXERCISE HERE READS. None captures, refunds, cancels or registers anything - the package
 * wraps nothing that would. What needs guarding is not a side effect but WHOSE ACCOUNT is read:
 * the sandbox is disposable, and the live account is somebody's real money and their customers'
 * names and emails. So the three layers guard the environment:
 *
 *   1. rig itself does not load the package's .env when CLAUDECODE is set.
 *   2. PAYPAL_ENVIRONMENT defaults to sandbox; live must be asked for, with PAYPAL_LIVE=1 as well.
 *   3. this: PAYPAL_LIVE is refused under an agent unless PAYPAL_AGENT_MAY_READ_LIVE=1 is on the
 *      command line for that run.
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Environment;
use Hampel\Rig\Io;
use Psr\Http\Message\ResponseInterface;

/**
 * A client built from the environment, or an explanation and an exit. Prints the environment
 * ABOVE the work.
 */
function harness_client(Io $io): Client
{
    $clientId = getenv('PAYPAL_CLIENT_ID') ?: '';
    $secret = getenv('PAYPAL_CLIENT_SECRET') ?: '';

    if ($clientId === '' || $secret === '') {
        $io->error('PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET are not both set.');
        $io->line();
        $io->line('If you are a person: copy .env.example to .env beside the package and fill it in.');
        $io->line('If you are an agent: this is the guard working. Do not go looking for the');
        $io->line('credentials, and do not edit .env - ask.');

        exit(1);
    }

    $environment = harness_environment($io);
    $factory = new HttpFactory();

    return new Client(
        new Config($environment),
        new ClientCredentials($clientId, $secret),
        new Guzzle(['timeout' => 60]),
        $factory,
        $factory,
    );
}

function harness_environment(Io $io): Environment
{
    $name = getenv('PAYPAL_ENVIRONMENT') ?: 'sandbox';

    try {
        $environment = Environment::fromName($name);
    } catch (\InvalidArgumentException $e) {
        $io->error($e->getMessage());

        exit(1);
    }

    if ($environment === Environment::Sandbox) {
        $io->warn('environment: sandbox - ' . $environment->baseUri());
        $io->line();

        return $environment;
    }

    if (getenv('PAYPAL_LIVE') !== '1') {
        $io->error('PAYPAL_ENVIRONMENT is live, and PAYPAL_LIVE=1 is not set - refusing to read the live account.');

        exit(1);
    }

    if (getenv('CLAUDECODE') !== false && getenv('PAYPAL_AGENT_MAY_READ_LIVE') !== '1') {
        $io->error('PAYPAL_LIVE is set, and is ignored in an agent session - refusing to read the live account.');
        $io->line('PAYPAL_AGENT_MAY_READ_LIVE=1 on the command line unlocks it for one run, when the account owner has said so.');

        exit(1);
    }

    $io->warn('environment: LIVE - reading the real account at ' . $environment->baseUri());
    $io->line('      nothing is changed, but what is printed is real payers and real money');
    $io->line();

    return $environment;
}

/**
 * A GET sent with the client's own token, answered RAW - status and body as they arrived, with
 * nothing turned into an exception.
 *
 * The package maps statuses to exception types, and when the question is which status PayPal
 * sends, the mapping hides the answer.
 *
 * @param  array<string, scalar|null>  $query
 */
function harness_raw(Client $paypal, string $path, array $query = []): ResponseInterface
{
    $connection = $paypal->connection();
    $request = $connection->authentication()->authenticate($connection->request('GET', $path, $query), $connection);

    return $connection->client()->sendRequest($request);
}

/**
 * The body of a raw response as an array, or an empty one.
 *
 * @return array<string, mixed>
 */
function harness_json(ResponseInterface $response): array
{
    $response->getBody()->rewind();
    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * One line describing a raw response, with the body cut short.
 */
function harness_describe(ResponseInterface $response, int $excerpt = 200): string
{
    $response->getBody()->rewind();
    $body = trim((string) $response->getBody());

    return sprintf(
        'HTTP %d, %s, %d bytes%s',
        $response->getStatusCode(),
        $response->getHeaderLine('Content-Type') ?: 'no content type',
        strlen($body),
        $body === '' ? '' : ': ' . str_replace(["\r", "\n"], ' ', substr($body, 0, $excerpt))
    );
}

/**
 * The window an exercise searches: the last PAYPAL_HARNESS_DAYS days, 31 by default - one search.
 *
 * @return array{\DateTimeImmutable, \DateTimeImmutable}
 */
function harness_window(): array
{
    $days = max(1, (int) (getenv('PAYPAL_HARNESS_DAYS') ?: 31));
    $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    return [$to->modify(sprintf('-%d days', $days)), $to];
}

function harness_utc(\DateTimeInterface $when): string
{
    return \DateTimeImmutable::createFromInterface($when)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
