<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Filter;

use Hampel\PayPal\Api\Enum\TransactionStatus;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;

/**
 * The optional parameters of a transaction search, built by name rather than by string.
 *
 *     TransactionFilter::make()->inCurrency('USD')->withStatus(TransactionStatus::Success)
 *
 * The dates are not here: they are required on every search, and Endpoint\Transactions takes them
 * as arguments so a search without them cannot be written.
 *
 * TWO DEFAULTS DIFFER FROM PAYPAL'S, deliberately:
 *
 *  - FIELDS DEFAULT TO `all`. PayPal's default is `transaction_info` alone, which leaves out the
 *    payer and the cart - the two sections a reconciliation matches on. onlyTransactionInfo()
 *    restores PayPal's default.
 *  - BALANCE-AFFECTING ROWS ONLY stays PayPal's default (`Y`); includingNonBalanceAffecting() widens
 *    it. Stated here because it decides whether a pending or denied payment is listed at all.
 *
 * AN OPTIONAL PARAMETER EMPTIES `ending_balance` on every row - PayPal documents this - so a
 * running balance is only read from an unfiltered search.
 *
 * Immutable: every method returns a new filter.
 */
final class TransactionFilter
{
    public const FIELDS = ['transaction_info', 'payer_info', 'shipping_info', 'auction_info', 'cart_info', 'incentive_info', 'store_info'];

    /** @var array<string, string> */
    private array $parameters = ['fields' => 'all'];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * The parameters as they will be sent, without the dates or the page.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return $this->parameters;
    }

    /**
     * Rows with this transaction id. A transaction id is not unique - PayPal documents one id
     * appearing once as balance-affecting and once not.
     */
    public function withTransactionId(string $transactionId): self
    {
        return $this->withText('transaction_id', $transactionId);
    }

    /**
     * Rows with this event code - `T0006` - which PayPal's parameter calls `transaction_type`.
     */
    public function withEventCode(string $eventCode): self
    {
        return $this->withText('transaction_type', strtoupper($eventCode));
    }

    public function withStatus(TransactionStatus $status): self
    {
        return $this->withParameter('transaction_status', $status->value);
    }

    public function inCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a three-letter ISO 4217 currency code.', $currency));
        }

        return $this->withParameter('transaction_currency', $currency);
    }

    /**
     * Rows whose gross amount falls in this range, IN MINOR UNITS - cents - as PayPal's parameter
     * takes them: `amountBetween(500, 1005)` is $5.00 to $10.05.
     */
    public function amountBetween(int $minimum, int $maximum): self
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException(sprintf('An amount range cannot end (%d) below where it starts (%d).', $maximum, $minimum));
        }

        return $this->withParameter('transaction_amount', sprintf('[%d TO %d]', $minimum, $maximum));
    }

    /**
     * The sections each row carries - some of FIELDS - rather than `all`.
     */
    public function withFields(string ...$fields): self
    {
        $unknown = array_diff($fields, self::FIELDS);

        if ($fields === [] || $unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Transaction search fields are some of %s; %s is not.',
                implode(', ', self::FIELDS),
                $fields === [] ? 'nothing' : implode(', ', $unknown)
            ));
        }

        return $this->withParameter('fields', implode(',', array_unique($fields)));
    }

    /**
     * PayPal's own default: the transaction info alone, without the payer or the cart.
     */
    public function onlyTransactionInfo(): self
    {
        return $this->withFields('transaction_info');
    }

    /**
     * Rows that do not change the balance as well as those that do - PayPal's `N`.
     */
    public function includingNonBalanceAffecting(): self
    {
        return $this->withParameter('balance_affecting_records_only', 'N');
    }

    /**
     * `CREDITCARD` or `DEBITCARD` - card transactions only.
     */
    public function withPaymentInstrumentType(string $type): self
    {
        return $this->withText('payment_instrument_type', strtoupper($type));
    }

    public function withStoreId(string $storeId): self
    {
        return $this->withText('store_id', $storeId);
    }

    public function withTerminalId(string $terminalId): self
    {
        return $this->withText('terminal_id', $terminalId);
    }

    /**
     * Set a parameter this class has no method for - one added to the API after this release. Null
     * removes it.
     */
    public function withParameter(string $name, ?string $value): self
    {
        if (in_array($name, ['start_date', 'end_date', 'page', 'page_size'], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s is not a filter: Endpoint\Transactions sets the dates and the page itself.',
                $name
            ));
        }

        $filter = clone $this;

        if ($value === null) {
            unset($filter->parameters[$name]);
        } else {
            $filter->parameters[$name] = $value;
        }

        return $filter;
    }

    private function withText(string $name, string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s filter needs a value.', $name));
        }

        return $this->withParameter($name, $value);
    }
}
