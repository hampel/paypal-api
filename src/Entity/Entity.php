<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Result\Links;

/**
 * The base of every record read from PayPal.
 *
 * ENTITIES ARE DATA AND HOLD NO CONNECTION. Anything that needs a request goes on an endpoint.
 *
 * EVERY ENTITY KEEPS `raw`, the record exactly as it arrived. A field PayPal adds after this release
 * is readable there without waiting for one, the sections of a transaction this package does not
 * map - shipping, auction, store, incentives - are still there, and jsonSerialize() round-trips what
 * PayPal sent rather than what this package understood.
 */
abstract class Entity implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build the entity from a record as PayPal sent it.
     *
     * @param  array<string, mixed>  $row
     */
    abstract public static function fromArray(array $row): static;

    public function links(): Links
    {
        return Links::from($this->raw['links'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
