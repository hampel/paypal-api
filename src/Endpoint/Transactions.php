<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Entity\AccountBalances;
use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Filter\TransactionFilter;
use Hampel\PayPal\Api\Result\TransactionPage;
use Hampel\PayPal\Api\Support\Cast;

/**
 * Transaction search and balances, at `/v1/reporting`.
 *
 * FOUR DOCUMENTED LIMITS SHAPE THIS CLASS, all from PayPal's specification and none yet measured:
 *
 *  - ONE SEARCH SPANS AT MOST 31 DAYS. search() refuses a wider range before sending it; each()
 *    walks any range in 31-day windows.
 *  - HISTORY GOES BACK THREE YEARS. Nothing older is returned, and nothing here pretends otherwise.
 *  - A TRANSACTION TAKES UP TO THREE HOURS TO APPEAR. A run that searches up to "now" has not seen
 *    the last three hours, so a reconciliation looks back over a window it has already covered
 *    rather than starting where the last run stopped. TransactionPage::$lastRefreshedAt says how
 *    current an answer is.
 *  - THE APP NEEDS TRANSACTION SEARCH ENABLED in PayPal's developer dashboard, or the search is
 *    refused - see NotPermittedException.
 */
final class Transactions extends Endpoint
{
    public const MAX_RANGE_DAYS = 31;

    /**
     * How far back PayPal keeps transactions for search.
     */
    public const HISTORY_YEARS = 3;

    private const PATH = 'v1/reporting/transactions';

    /**
     * One page of one search, of at most 31 days.
     */
    public function search(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?TransactionFilter $filter = null,
        int $page = 1,
        ?int $pageSize = null,
    ): TransactionPage {
        [$from, $to] = self::range($from, $to);

        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_RANGE_DAYS * 86400) {
            throw new InvalidArgumentException(sprintf(
                'A PayPal transaction search spans at most %d days, and %s to %s is longer. Use each(), '
                    . 'which walks a longer range in windows.',
                self::MAX_RANGE_DAYS,
                Cast::instantString($from),
                Cast::instantString($to)
            ));
        }

        $pageSize ??= $this->connection->config()->pageSize;
        TransactionPage::assertValidPageSize($pageSize);

        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('PayPal numbers search pages from 1; page %d was asked for.', $page));
        }

        $query = ['start_date' => Cast::instantString($from), 'end_date' => Cast::instantString($to)]
            + ($filter ?? TransactionFilter::make())->toQuery()
            + ['page_size' => $pageSize, 'page' => $page];

        return TransactionPage::fromResponse($this->apiGet(self::PATH, $query), $page, $pageSize);
    }

    /**
     * Every matching transaction from $from to $to, of any length, fetched as it is consumed.
     *
     * THE RANGE IS WALKED IN 31-DAY WINDOWS, each ending one second before the next begins, and
     * each window page by page. Every page is a request; stopping early - a `break` in the foreach -
     * stops the requests. Transactions arrive window by window, in whatever order PayPal lists each.
     *
     * A WINDOW'S END IS TREATED AS INCLUSIVE, which is what makes the one-second step gapless. Whether
     * PayPal's `end_date` is inclusive is unmeasured; `vendor/bin/rig transactions` asks.
     *
     * @return \Generator<int, Transaction>
     */
    public function each(\DateTimeInterface $from, \DateTimeInterface $to, ?TransactionFilter $filter = null, ?int $pageSize = null): \Generator
    {
        foreach (self::windows($from, $to) as [$start, $end]) {
            $page = 1;

            do {
                $result = $this->search($start, $end, $filter, $page++, $pageSize);

                yield from $result->transactions;
            } while ($result->hasMore());
        }
    }

    /**
     * Every row with this transaction id between two dates - a list, because PayPal documents that
     * a transaction id is not unique in its reporting system.
     *
     * @return list<Transaction>
     */
    public function withId(string $transactionId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $filter = TransactionFilter::make()->withTransactionId($transactionId)->includingNonBalanceAffecting();

        return iterator_to_array($this->each($from, $to, $filter), false);
    }

    /**
     * The account's balance in every currency it holds - now, or as of a moment in the last three
     * years.
     */
    public function balances(?\DateTimeInterface $asOf = null, ?string $currency = null): AccountBalances
    {
        return $this->apiObject('v1/reporting/balances', AccountBalances::fromArray(...), [
            'as_of_time' => $asOf === null ? null : Cast::instantString($asOf),
            'currency_code' => $currency === null ? null : strtoupper(trim($currency)),
        ]);
    }

    /**
     * A range split into windows no wider than a single search allows: each `[start, end]`, the end
     * one second before the next start, and the last ending at $to.
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    public static function windows(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        [$from, $to] = self::range($from, $to);

        $windows = [];
        $start = $from;

        while ($start <= $to) {
            $next = $start->modify(sprintf('+%d seconds', self::MAX_RANGE_DAYS * 86400));
            $end = min($next->modify('-1 second'), $to);
            $windows[] = [$start, $end];
            $start = $next;
        }

        return $windows;
    }

    /**
     * Both ends in UTC, in whole seconds - PayPal's date parameters take nothing finer here.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function range(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $utc = new \DateTimeZone('UTC');
        $from = (new \DateTimeImmutable('@' . $from->getTimestamp()))->setTimezone($utc);
        $to = (new \DateTimeImmutable('@' . $to->getTimestamp()))->setTimezone($utc);

        if ($from > $to) {
            throw new InvalidArgumentException(sprintf(
                'A transaction search cannot end (%s) before it starts (%s).',
                Cast::instantString($to),
                Cast::instantString($from)
            ));
        }

        return [$from, $to];
    }
}
