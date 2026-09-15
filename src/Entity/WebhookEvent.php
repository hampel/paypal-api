<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * A webhook event - `PAYMENT.CAPTURE.COMPLETED`, `BILLING.SUBSCRIPTION.CANCELLED` - with the resource
 * it is about.
 *
 * `resource` is left as an array, deliberately. Its shape is whatever the event is about - a
 * capture, a subscription, a dispute, a sale from the older v1 API - and varies with
 * `resourceVersion`; map it with the entity that fits, `Capture::fromArray($event->resource)`.
 *
 * AN EVENT BUILT FROM A NOTIFICATION IS UNVERIFIED until Webhooks::verifySignature() says otherwise.
 * Anybody can post JSON to a webhook URL.
 */
final class WebhookEvent extends Entity
{
    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $eventType = null,
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceVersion = null,
        public readonly ?string $summary = null,
        public readonly array $resource = [],
        public readonly ?\DateTimeImmutable $createdAt = null,
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
            Cast::text($row['event_type'] ?? null),
            Cast::text($row['resource_type'] ?? null),
            Cast::text($row['resource_version'] ?? null),
            Cast::text($row['summary'] ?? null),
            Cast::object($row['resource'] ?? null),
            Cast::datetime($row['create_time'] ?? null),
            $row,
        );
    }

    /**
     * The merchant's own reference on the resource, wherever this kind of resource keeps it.
     *
     * THREE PLACES, BECAUSE THREE GENERATIONS OF API. A v2 capture or a subscription carries
     * `custom_id`; a v2 order carries it on its first purchase unit; a v1 sale carries `custom`.
     */
    public function customId(): ?string
    {
        $units = $this->resource['purchase_units'] ?? null;
        $firstUnit = is_array($units) ? Cast::object($units[0] ?? null) : [];

        return Cast::text($this->resource['custom_id'] ?? null)
            ?? Cast::text($firstUnit['custom_id'] ?? null)
            ?? Cast::text($this->resource['custom'] ?? null);
    }

    /**
     * The id of the resource the event is about - the capture id of a capture event, the `I-` id of
     * a subscription event.
     */
    public function resourceId(): ?string
    {
        return Cast::text($this->resource['id'] ?? null);
    }
}
