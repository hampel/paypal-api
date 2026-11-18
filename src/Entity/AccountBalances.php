<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Support\Cast;

/**
 * Every currency balance the account holds, as of a moment.
 *
 * `asOf` is the moment asked for, or the latest PayPal could serve; `lastRefreshedAt` is when the
 * figures were last brought up to date. PayPal documents the same three-hour lag as for
 * transaction search, and on 2026-11-18 the sandbox answered "now" with both set to the search's
 * last refresh: these are reporting figures, not a live balance.
 */
final class AccountBalances extends Entity
{
    /**
     * @param  list<Balance>  $balances
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $balances = [],
        public readonly ?string $accountId = null,
        public readonly ?\DateTimeImmutable $asOf = null,
        public readonly ?\DateTimeImmutable $lastRefreshedAt = null,
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
            Cast::objects($row['balances'] ?? null, Balance::fromArray(...)),
            Cast::text($row['account_id'] ?? null),
            Cast::datetime($row['as_of_time'] ?? null),
            Cast::datetime($row['last_refresh_time'] ?? null),
            $row,
        );
    }

    public function inCurrency(string $currency): ?Balance
    {
        foreach ($this->balances as $balance) {
            if ($balance->currency !== null && strcasecmp($balance->currency, $currency) === 0) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * The balance flagged `primary`, as the specification describes. The sandbox sent no such flag
     * on 2026-11-18, so this answered null there; inCurrency() does not depend on it.
     */
    public function primary(): ?Balance
    {
        foreach ($this->balances as $balance) {
            if ($balance->primary === true) {
                return $balance;
            }
        }

        return null;
    }
}
