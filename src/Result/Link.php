<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Result;

use Hampel\PayPal\Api\Support\Cast;

/**
 * One HATEOAS link: `{"href": "https://api-m.paypal.com/v2/payments/captures/2GG2...", "rel": "up",
 * "method": "GET"}`.
 */
final class Link implements \JsonSerializable
{
    public function __construct(
        public readonly string $rel,
        public readonly string $href,
        public readonly string $method = 'GET',
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $rel = Cast::text($row['rel'] ?? null);
        $href = Cast::text($row['href'] ?? null);

        if ($rel === null || $href === null) {
            return null;
        }

        return new self($rel, $href, strtoupper(Cast::text($row['method'] ?? null) ?? 'GET'));
    }

    /**
     * The last segment of the href's path - the id of whatever the link points at.
     */
    public function lastSegment(): ?string
    {
        $path = rtrim((string) parse_url($this->href, PHP_URL_PATH), '/');
        $segment = substr($path, (int) strrpos($path, '/') + 1);

        return $segment === '' ? null : rawurldecode($segment);
    }

    /**
     * @return array{rel: string, href: string, method: string}
     */
    public function jsonSerialize(): array
    {
        return ['href' => $this->href, 'rel' => $this->rel, 'method' => $this->method];
    }
}
