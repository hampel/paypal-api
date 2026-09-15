<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Result;

use Hampel\PayPal\Api\Support\Cast;

/**
 * A successful response: the decoded body, the status, and the headers that came with it.
 *
 * PAYPAL DOES NOT WRAP A SINGLE RESOURCE. `GET /v2/payments/captures/{id}` answers with the
 * capture's fields at the top level - use object(). A list answers with the items under a key
 * named for the resource - `transaction_details`, `plans`, `events`, `webhooks` - use collection().
 */
final class ApiResponse implements \JsonSerializable
{
    /** @var array<mixed> */
    public readonly array $data;

    public function __construct(
        public readonly mixed $body,
        public readonly int $status,
        public readonly ResponseMeta $meta = new ResponseMeta(),
    ) {
        $this->data = is_array($body) ? $body : [];
    }

    /**
     * The body as an object, or the object under a key.
     *
     * @return array<string, mixed>
     */
    public function object(?string $key = null): array
    {
        return Cast::object($key === null ? $this->data : ($this->data[$key] ?? null));
    }

    /**
     * The list under a key, with anything that is not an object dropped.
     *
     * @return list<array<string, mixed>>
     */
    public function collection(string $key): array
    {
        return Cast::objects($this->data[$key] ?? null, static fn (array $row): array => $row);
    }

    public function links(): Links
    {
        return Links::from($this->data['links'] ?? null);
    }

    public function jsonSerialize(): mixed
    {
        return $this->body;
    }
}
