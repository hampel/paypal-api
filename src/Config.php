<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api;

use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Result\TransactionPage;

/**
 * Which PayPal we talk to, and how requests are shaped.
 *
 *     new Config(Environment::Sandbox)
 *
 * Everything but the environment is optional and plain values, so an application's config array
 * maps onto it directly.
 */
final class Config
{
    public readonly string $baseUri;

    /**
     * @param  int  $pageSize  transactions per search request, 1 to 500. The default is the
     *                         specification's MAXIMUM rather than its default of 100: a
     *                         reconciliation reads every row, and five times fewer requests is the
     *                         whole of the difference
     * @param  string|null  $baseUri  for a recorded fixture or an outbound proxy, and nothing else
     */
    public function __construct(
        public readonly Environment $environment,
        public readonly int $pageSize = TransactionPage::MAX_PAGE_SIZE,
        ?string $baseUri = null,
    ) {
        TransactionPage::assertValidPageSize($pageSize);

        $baseUri = trim($baseUri ?? $environment->baseUri());

        if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
            throw new InvalidArgumentException(sprintf('The PayPal API base URI must be absolute, with a scheme: "%s" is not.', $baseUri));
        }

        $this->baseUri = rtrim($baseUri, '/');
    }

    public function withPageSize(int $pageSize): self
    {
        return new self($this->environment, $pageSize, $this->baseUri);
    }

    public function isSandbox(): bool
    {
        return $this->environment === Environment::Sandbox;
    }

    /**
     * Turn a path into an absolute URI. An absolute URI passes through untouched, so a link out of
     * a response can be fed straight back in - see ownsUri() for why that is then checked.
     *
     * Query values of null are dropped, booleans are written `true`/`false`, and everything else is
     * RFC 3986 encoded - so the `+` in a date's offset arrives as a `+` and not as a space.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function resolve(string $path, array $query = []): string
    {
        $uri = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $this->baseUri . '/' . ltrim($path, '/');

        $parameters = [];

        foreach ($query as $name => $value) {
            if ($value !== null) {
                $parameters[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        if ($parameters !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    public function host(): string
    {
        return parse_url($this->baseUri, PHP_URL_HOST) ?: 'api-m.paypal.com';
    }

    /**
     * Whether a URI points at the API we are configured for - scheme and host both.
     *
     * Every request carries a bearer token that can read the account's whole transaction history,
     * and some requests follow a link out of a response body. Connection::request() asks this of
     * every URI it builds, so a link pointing elsewhere is refused rather than handed the token.
     */
    public function ownsUri(string $uri): bool
    {
        $parts = parse_url($uri);
        $base = parse_url($this->baseUri);

        if (!is_array($parts) || !is_array($base)) {
            return false;
        }

        return isset($parts['host'], $base['host'])
            && strcasecmp($parts['host'], $base['host']) === 0
            && strcasecmp($parts['scheme'] ?? '', $base['scheme'] ?? '') === 0
            && ($parts['port'] ?? null) === ($base['port'] ?? null);
    }
}
