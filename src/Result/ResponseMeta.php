<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Result;

use Psr\Http\Message\ResponseInterface;

/**
 * What arrived alongside a response body. Keys are lowercased.
 *
 * The one PayPal header worth naming is `PayPal-Debug-Id`, the correlation id its support asks
 * for; debugId() reads it. Every header is kept, because what else PayPal sends - a rate-limit
 * header, for one - is unmeasured.
 */
final class ResponseMeta
{
    /**
     * @param  array<string, string>  $headers  lowercased name to comma-joined value
     */
    public function __construct(
        public readonly array $headers = [],
    ) {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $headers = [];

        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        return new self($headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function debugId(): ?string
    {
        return $this->header('PayPal-Debug-Id');
    }
}
