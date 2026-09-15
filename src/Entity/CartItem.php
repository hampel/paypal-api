<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * One line of a transaction search row's `cart_info.item_details` - what was bought, when the
 * merchant said.
 */
final class CartItem extends Entity
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $quantity = null,
        public readonly ?Money $unitPrice = null,
        public readonly ?Money $amount = null,
        public readonly ?Money $total = null,
        public readonly ?string $invoiceNumber = null,
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
            Cast::text($row['item_code'] ?? null),
            Cast::text($row['item_name'] ?? null),
            Cast::text($row['item_description'] ?? null),
            Cast::text($row['item_quantity'] ?? null),
            Cast::money($row['item_unit_price'] ?? null),
            Cast::money($row['item_amount'] ?? null),
            Cast::money($row['total_item_amount'] ?? null),
            Cast::text($row['invoice_number'] ?? null),
            $row,
        );
    }
}
