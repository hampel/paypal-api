<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api;

use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Authentication\Authentication;
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Authentication\TokenStore;
use Hampel\PayPal\Api\Endpoint\Authorisation;
use Hampel\PayPal\Api\Endpoint\Captures;
use Hampel\PayPal\Api\Endpoint\Endpoint;
use Hampel\PayPal\Api\Endpoint\Plans;
use Hampel\PayPal\Api\Endpoint\Refunds;
use Hampel\PayPal\Api\Endpoint\Subscriptions;
use Hampel\PayPal\Api\Endpoint\Transactions;
use Hampel\PayPal\Api\Endpoint\Webhooks;
use Hampel\PayPal\Api\Exception\RuntimeException;
use Hampel\PayPal\Api\Support\Psr17Discovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point.
 *
 *     $paypal = Client::withClientCredentials($clientId, $secret, Environment::Sandbox, new GuzzleHttp\Client());
 *
 *     $paypal->verify()->canSearchTransactions();
 *
 *     foreach ($paypal->transactions()->each(new DateTimeImmutable('-90 days'), new DateTimeImmutable()) as $row) {
 *         echo $row->id, ' ', $row->amount, ' ', $row->customField, "\n";
 *     }
 *
 * IT READS. Every operation wrapped here reads the account - transactions, balances, captures,
 * refunds, subscriptions, plans, webhook events - except signature verification, which changes
 * nothing either. Nothing here captures, refunds or cancels; see README.md for why.
 */
final class Client
{
    private readonly Connection $connection;

    /** @var array<class-string<Endpoint>, Endpoint> */
    private array $endpoints = [];

    /**
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17. Leave both null and the
     *         package finds one - Guzzle's, Nyholm's or Diactoros' - see Psr17Discovery
     */
    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($requestFactory === null || $streamFactory === null) {
            [$foundRequest, $foundStream] = Psr17Discovery::find();

            $requestFactory ??= $foundRequest;
            $streamFactory ??= $foundStream;
        }

        $this->connection = new Connection($config, $authentication, $client, $requestFactory, $streamFactory, $logger);
    }

    /**
     * A REST app's client id and secret, from PayPal's developer dashboard - exchanged for a token
     * that is reused until it expires. Pass a TokenStore for anything longer-lived than one
     * process.
     */
    public static function withClientCredentials(
        string $clientId,
        #[\SensitiveParameter] string $secret,
        Environment $environment,
        ClientInterface $client,
        ?TokenStore $store = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            new Config($environment),
            new ClientCredentials($clientId, $secret, $store),
            $client,
            logger: $logger ?? new NullLogger(),
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    /**
     * Whether the credential is accepted, and what the app may do - answered with the grant, whose
     * scopes list the features enabled for the app.
     *
     * One token request, or none when the store already holds a token. A refused client id or
     * secret raises NotAuthenticatedException.
     */
    public function verify(): AccessGrant
    {
        if (!$this->authentication instanceof ClientCredentials) {
            throw new RuntimeException(sprintf(
                'verify() reads the grant ClientCredentials obtains, and this client authenticates with %s.',
                $this->authentication->describe()
            ));
        }

        return $this->authentication->currentGrant($this->connection);
    }

    /**
     * The same transport and configuration, under a different credential - a new client rather than
     * a mutation, so two apps in one long-running process cannot leak into each other's requests.
     */
    public function withCredential(Authentication $authentication): self
    {
        return new self(
            $this->config,
            $authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger,
        );
    }

    /**
     * The same credential and transport, under a different configuration.
     */
    public function withConfig(Config $config): self
    {
        return new self(
            $config,
            $this->authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger,
        );
    }

    /**
     * Any Endpoint subclass, constructed and memoised - the extension point.
     *
     * @template T of Endpoint
     * @param  class-string<T>  $class
     * @return T
     */
    public function endpoint(string $class): Endpoint
    {
        if (!isset($this->endpoints[$class])) {
            Endpoint::assertConstructible($class);

            $this->endpoints[$class] = new $class($this->connection);
        }

        /** @var T $endpoint */
        $endpoint = $this->endpoints[$class];

        return $endpoint;
    }

    /**
     * Transaction search and balances.
     */
    public function transactions(): Transactions
    {
        return $this->endpoint(Transactions::class);
    }

    public function captures(): Captures
    {
        return $this->endpoint(Captures::class);
    }

    public function refunds(): Refunds
    {
        return $this->endpoint(Refunds::class);
    }

    public function subscriptions(): Subscriptions
    {
        return $this->endpoint(Subscriptions::class);
    }

    public function plans(): Plans
    {
        return $this->endpoint(Plans::class);
    }

    /**
     * Signature verification, registered webhooks, and the events PayPal sent.
     */
    public function webhooks(): Webhooks
    {
        return $this->endpoint(Webhooks::class);
    }

    /**
     * The token endpoint, called directly. Authentication\ClientCredentials uses it itself.
     */
    public function authorisation(): Authorisation
    {
        return $this->endpoint(Authorisation::class);
    }

    /**
     * For anything the endpoints do not cover.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
