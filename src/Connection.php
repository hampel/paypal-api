<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api;

use Hampel\PayPal\Api\Authentication\Anonymous;
use Hampel\PayPal\Api\Authentication\Authentication;
use Hampel\PayPal\Api\Exception\ApiException;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\RequestException;
use Hampel\PayPal\Api\Exception\RuntimeException;
use Hampel\PayPal\Api\Result\ApiResponse;
use Hampel\PayPal\Api\Result\ResponseMeta;
use Hampel\PayPal\Api\Support\Json;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything that touches HTTP, in one place.
 *
 * The client is injected as a PSR-18 ClientInterface rather than a concrete one. A host application
 * that routes all outbound traffic through its own stack - for a proxy, for SSRF protection, for
 * `Http::fake()` in a Laravel test - implements sendRequest() over it and shares this code, instead
 * of writing a second PayPal client because this one hard-coded the wrong library.
 *
 * A FAILURE IS RAISED, NEVER LOGGED. Requests are logged at `debug` and that is all. Whether an
 * exception is a failure is decided by whoever catches it, and this package catches some of its
 * own - Endpoint::apiFind() turns a 404 into null - so an `error` written before the throw would
 * report ordinary answers as faults, and make every failure a consumer logs arrive twice.
 *
 * The extension point of last resort - anything the endpoints do not wrap:
 *
 *     $paypal->connection()->get('v1/reporting/balances', ['currency_code' => 'USD'])->object();
 */
final class Connection
{
    public const JSON_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    public function client(): ClientInterface
    {
        return $this->client;
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * The same transport, sending no bearer token - for the token request itself.
     */
    public function unauthenticated(): self
    {
        return new self($this->config, new Anonymous(), $this->client, $this->requestFactory, $this->streamFactory, $this->logger);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     */
    public function get(string $path, array $query = [], array $headers = []): ApiResponse
    {
        return $this->send(self::withHeaders($this->request('GET', $path, $query), $headers));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('POST', $path), $payload));
    }

    /**
     * A POST whose body is JSON the caller has already encoded, sent byte for byte.
     *
     * For the one request where re-encoding changes the answer: webhook signature verification,
     * whose event must reach PayPal exactly as PayPal sent it. See WebhookNotification.
     */
    public function postEncoded(string $path, string $json): ApiResponse
    {
        [$decoded] = Json::decode($json);

        if (!$decoded) {
            throw new InvalidArgumentException(sprintf('The body for POST %s is not JSON.', $path));
        }

        return $this->send(
            $this->request('POST', $path)
                ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
                ->withBody($this->streamFactory->createStream($json))
        );
    }

    /**
     * Follow a URI out of a response's `links` - refused, by request(), unless it points at the
     * configured API.
     */
    public function follow(string $uri): ApiResponse
    {
        return $this->send($this->request('GET', $uri));
    }

    /**
     * Build a request without sending it. Accept is applied; the credential is not, because
     * applying it may mean obtaining a token - send() does that.
     *
     * AN ABSOLUTE URI THAT IS NOT THE CONFIGURED API IS REFUSED, HERE. Every request this package
     * makes is built by this method, so the check cannot be skipped by a caller that hands a link
     * out of a response body to get() - Config::resolve() lets an absolute URI through unchanged,
     * and the request would carry the bearer token wherever the link pointed.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function request(string $method, string $path, array $query = []): RequestInterface
    {
        $uri = $this->config->resolve($path, $query);

        if (!$this->config->ownsUri($uri)) {
            throw new RuntimeException(sprintf(
                'Refusing to request "%s": the PayPal API is configured as %s, and every request '
                    . 'carries the bearer token.',
                $uri,
                $this->config->baseUri
            ));
        }

        return $this->requestFactory
            ->createRequest($method, $uri)
            ->withHeader('Accept', self::JSON_CONTENT_TYPE);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withJson(RequestInterface $request, array $payload): RequestInterface
    {
        return $request
            ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
            ->withBody($this->streamFactory->createStream(Json::encode($payload)));
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function withForm(RequestInterface $request, array $fields): RequestInterface
    {
        return $request
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(http_build_query($fields, '', '&', PHP_QUERY_RFC1738)));
    }

    /**
     * Send a request built elsewhere, with this connection's credential and error handling.
     */
    public function send(RequestInterface $request): ApiResponse
    {
        [$response, $body, $sent] = $this->exchange($request);

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($sent->getMethod(), (string) $sent->getUri(), $response, $body);
        }

        $meta = ResponseMeta::fromResponse($response);
        [$decoded, $value] = Json::decode($body);

        if ($decoded) {
            return new ApiResponse($value, $status, $meta);
        }

        // A 204 is empty on purpose, and so is a write that answered with nothing. A GET that
        // answered with nothing is not: read as an empty search it would reach a reconciliation
        // as "no payments in this window".
        if ($status === 204 || (trim($body) === '' && $sent->getMethod() !== 'GET')) {
            return new ApiResponse(null, $status, $meta);
        }

        throw MalformedResponseException::forResponse($sent->getMethod(), (string) $sent->getUri(), $response, $body);
    }

    /**
     * Authenticate, send, and on a 401 give the credential one chance to recover.
     *
     * ONE RETRY, AND ONLY FOR A 401. A 401 means PayPal refused the request before doing anything
     * with it, and a stored token can be revoked before its recorded expiry. Nothing else is
     * retried here.
     *
     * @return array{ResponseInterface, string, RequestInterface}  the response, its body, and the
     *         request as actually sent
     */
    private function exchange(RequestInterface $request): array
    {
        $sent = $this->authentication->authenticate($request, $this);
        $response = $this->dispatch($sent);
        $body = (string) $response->getBody();

        if ($response->getStatusCode() === 401 && $this->authentication->recover($this)) {
            $this->logger->debug('PayPal API refused the token; retrying once with a fresh one', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'credential' => $this->authentication->describe(),
            ]);

            $stream = $request->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $sent = $this->authentication->authenticate($request, $this);
            $response = $this->dispatch($sent);
            $body = (string) $response->getBody();
        }

        return [$response, $body, $sent];
    }

    /**
     * Send, and keep a transport failure distinct from an HTTP status.
     *
     * The catch is ClientExceptionInterface and not \Throwable, deliberately. Laravel's
     * StrayRequestException, raised by `Http::preventStrayRequests()`, is a plain
     * RuntimeException, and it reaches the consumer's test naming the URL only because it passes
     * through here untouched.
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        $this->logger->debug('PayPal API request', ['method' => $method, 'uri' => $uri]);

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw RequestException::for($method, $uri, $e);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function withHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
