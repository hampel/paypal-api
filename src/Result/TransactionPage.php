<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Result;

use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Support\Cast;

/**
 * One page of a transaction search.
 *
 *     {"transaction_details": [...], "account_number": "...", "start_date": "...",
 *      "end_date": "...", "last_refreshed_datetime": "...", "page": 1, "total_items": 212,
 *      "total_pages": 1, "links": [...]}
 *
 * UNLIKE MOST LISTS, A SEARCH HAS A TOTAL, so hasMore() is arithmetic on the page that was asked for
 * against `total_pages`. The response's own `page` is kept as `reportedPage`: the specification
 * calls it "a zero-relative index", but the sandbox was measured on 2026-11-18 writing 1 for the
 * first page, as the request counts.
 *
 * `lastRefreshedAt` is when PayPal's reporting data was last brought up to date. A transaction
 * newer than that is not in the answer yet - PayPal documents the lag as up to three hours. MEASURED:
 * a search whose end is later than that comes back with `endDate` cut back to it, without an error,
 * so `endDate` - not the end that was asked for - is how far the answer reaches.
 *
 * @implements \IteratorAggregate<int, Transaction>
 */
final class TransactionPage implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * Measured on 2026-11-18: 501 answers 400 INVALID_REQUEST.
     */
    public const MAX_PAGE_SIZE = 500;

    /**
     * What PayPal sends when a request does not say. This package always says - see Config.
     */
    public const PAYPAL_DEFAULT_PAGE_SIZE = 100;

    /**
     * @param  list<Transaction>  $transactions
     */
    public function __construct(
        public readonly array $transactions,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly ?int $totalItems = null,
        public readonly ?int $totalPages = null,
        public readonly ?int $reportedPage = null,
        public readonly ?string $accountNumber = null,
        public readonly ?\DateTimeImmutable $startDate = null,
        public readonly ?\DateTimeImmutable $endDate = null,
        public readonly ?\DateTimeImmutable $lastRefreshedAt = null,
        public readonly Links $links = new Links(),
    ) {
    }

    public static function fromResponse(ApiResponse $response, int $page, int $pageSize): self
    {
        $body = $response->object();

        return new self(
            array_map(Transaction::fromArray(...), $response->collection('transaction_details')),
            $page,
            $pageSize,
            Cast::int($body['total_items'] ?? null),
            Cast::int($body['total_pages'] ?? null),
            Cast::int($body['page'] ?? null),
            Cast::text($body['account_number'] ?? null),
            Cast::datetime($body['start_date'] ?? null),
            Cast::datetime($body['end_date'] ?? null),
            Cast::datetime($body['last_refreshed_datetime'] ?? null),
            $response->links(),
        );
    }

    /**
     * Whether a later page holds more - by `total_pages` when PayPal sent it, and otherwise by
     * whether this page came back full.
     */
    public function hasMore(): bool
    {
        if ($this->totalPages !== null) {
            return $this->page < $this->totalPages;
        }

        return $this->transactions !== [] && count($this->transactions) >= $this->pageSize;
    }

    public function isEmpty(): bool
    {
        return $this->transactions === [];
    }

    public function count(): int
    {
        return count($this->transactions);
    }

    /**
     * @return \ArrayIterator<int, Transaction>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->transactions);
    }

    /**
     * @return list<Transaction>
     */
    public function jsonSerialize(): array
    {
        return $this->transactions;
    }

    public static function assertValidPageSize(int $pageSize): void
    {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'Transaction search accepts a page size between 1 and %d; %d was asked for.',
                self::MAX_PAGE_SIZE,
                $pageSize
            ));
        }
    }
}
