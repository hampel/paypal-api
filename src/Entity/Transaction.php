<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Enum\TransactionStatus;
use Hampel\PayPal\Api\Support\Cast;

/**
 * One row of a transaction search - one movement of money, or one event beside one.
 *
 * The row PayPal sends is `transaction_detail`: a `transaction_info` object plus optional
 * `payer_info`, `cart_info`, `shipping_info`, `store_info`, `auction_info` and `incentive_info`,
 * present when the search asked for them. The transaction info is flattened onto this class, the
 * payer and the cart are mapped, and the rest stay on `raw`.
 *
 * `payer` IS NULL WHEN `payer_info` NAMES NOBODY - no account id, email or name. A conversion row
 * carries an address status, an empty name and an address, measured on 2026-11-18, and that is
 * not a payer; the section is still on `raw`.
 *
 * A PAYMENT IS OFTEN MORE THAN ONE ROW. Measured on 2026-11-18, a USD payment into an AUD account
 * set to convert it was the payment in USD, its fee negative and in USD on the same row, then two
 * `T0200` rows - the net out of USD and into AUD - whose `referenceId` is the payment's `id`, not
 * the payment's own `referenceId`. A payment the account keeps in its own currency is one row.
 *
 * A TRANSACTION ID IS NOT UNIQUE in the reporting system - PayPal documents that one id can appear
 * twice, once balance-affecting and once not. Do not key a map by `id` alone.
 *
 * `customField` is what the merchant attached at checkout: `custom_id` on an order or a REST
 * subscription. It is how a payment is traced back to whatever the merchant's own system sold.
 * A PAYPAL STANDARD SUBSCRIPTION'S `custom` DOES NOT ARRIVE: measured on 2026-11-18, its `T0002`
 * rows had no `custom_field`, and its `item_name` was the `subject`.
 */
final class Transaction extends Entity
{
    /**
     * @param  list<CartItem>  $cartItems
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $eventCode = null,
        public readonly ?string $status = null,
        public readonly ?\DateTimeImmutable $initiatedAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?Money $amount = null,
        public readonly ?Money $fee = null,
        public readonly ?Money $discount = null,
        public readonly ?Money $salesTax = null,
        public readonly ?Money $shipping = null,
        public readonly ?string $referenceId = null,
        public readonly ?string $referenceIdType = null,
        public readonly ?string $counterpartyAccountId = null,
        public readonly ?string $invoiceId = null,
        public readonly ?string $customField = null,
        public readonly ?string $subject = null,
        public readonly ?string $note = null,
        public readonly ?Money $endingBalance = null,
        public readonly ?Money $availableBalance = null,
        public readonly ?string $protectionEligibility = null,
        public readonly ?string $instrumentType = null,
        public readonly ?string $instrumentSubType = null,
        public readonly ?Payer $payer = null,
        public readonly array $cartItems = [],
        public readonly ?string $paypalInvoiceId = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): static
    {
        $info = Cast::object($row['transaction_info'] ?? null);
        $cart = Cast::object($row['cart_info'] ?? null);
        $payer = Cast::nested($row['payer_info'] ?? null, Payer::fromArray(...));

        return new self(
            Cast::text($info['transaction_id'] ?? null),
            Cast::text($info['transaction_event_code'] ?? null),
            Cast::text($info['transaction_status'] ?? null),
            Cast::datetime($info['transaction_initiation_date'] ?? null),
            Cast::datetime($info['transaction_updated_date'] ?? null),
            Cast::money($info['transaction_amount'] ?? null),
            Cast::money($info['fee_amount'] ?? null),
            Cast::money($info['discount_amount'] ?? null),
            Cast::money($info['sales_tax_amount'] ?? null),
            Cast::money($info['shipping_amount'] ?? null),
            Cast::text($info['paypal_reference_id'] ?? null),
            Cast::text($info['paypal_reference_id_type'] ?? null),
            Cast::text($info['paypal_account_id'] ?? null),
            Cast::text($info['invoice_id'] ?? null),
            Cast::text($info['custom_field'] ?? null),
            Cast::text($info['transaction_subject'] ?? null),
            Cast::text($info['transaction_note'] ?? null),
            Cast::money($info['ending_balance'] ?? null),
            Cast::money($info['available_balance'] ?? null),
            Cast::text($info['protection_eligibility'] ?? null),
            Cast::text($info['instrument_type'] ?? null),
            Cast::text($info['instrument_sub_type'] ?? null),
            $payer !== null && ($payer->accountId ?? $payer->email ?? $payer->name()) !== null ? $payer : null,
            Cast::objects($cart['item_details'] ?? null, CartItem::fromArray(...)),
            Cast::text($cart['paypal_invoice_id'] ?? null),
            $row,
        );
    }

    public function transactionStatus(): ?TransactionStatus
    {
        return Cast::enum(TransactionStatus::class, $this->status);
    }

    /**
     * The event code's group - `T00` for a payment, `T01` for a fee - which is what classifies a
     * row. PayPal's list of codes is at "Transaction event codes" in its Transaction Search guide.
     */
    public function eventGroup(): ?string
    {
        return $this->eventCode === null || strlen($this->eventCode) < 3 ? null : strtoupper(substr($this->eventCode, 0, 3));
    }

    /**
     * `T00nn` - a payment received or sent: checkout, subscription, donation, and the rest.
     */
    public function isPayment(): bool
    {
        return $this->eventGroup() === 'T00';
    }

    /**
     * `T0002` - a payment made by a subscription.
     */
    public function isSubscriptionPayment(): bool
    {
        return strtoupper((string) $this->eventCode) === 'T0002';
    }

    /**
     * `T01nn` - a fee that is a transaction of its own, rather than one carried on a payment's
     * `fee_amount`.
     */
    public function isFee(): bool
    {
        return $this->eventGroup() === 'T01';
    }

    /**
     * `T0200` to `T0202` - a currency conversion: general, user-initiated, or to cover a negative
     * balance.
     */
    public function isCurrencyConversion(): bool
    {
        return in_array(strtoupper((string) $this->eventCode), ['T0200', 'T0201', 'T0202'], true);
    }

    /**
     * `T11nn` - a reversal, refunds among them.
     */
    public function isReversal(): bool
    {
        return $this->eventGroup() === 'T11';
    }

    /**
     * `T1107` - a payment refund.
     */
    public function isRefund(): bool
    {
        return strtoupper((string) $this->eventCode) === 'T1107';
    }
}
