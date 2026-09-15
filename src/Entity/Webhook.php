<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * A webhook the app has registered: where PayPal posts events, and which events.
 *
 * `id` is the webhook id signature verification needs - the id of the webhook the notification was
 * sent TO, which is why it cannot be read out of the notification itself.
 */
final class Webhook extends Entity
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $url = null,
        public readonly array $eventTypes = [],
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        return new self(
            Cast::text($row['id'] ?? null),
            Cast::text($row['url'] ?? null),
            array_values(array_filter(Cast::objects($row['event_types'] ?? null, static fn (array $type): ?string => Cast::text($type['name'] ?? null)))),
            $row,
        );
    }
}
