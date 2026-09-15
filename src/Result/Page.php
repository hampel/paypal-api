<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Result;

/**
 * One page of a list other than transaction search - plans, webhook events.
 *
 * Whether there is another page is read from `total_pages` where PayPal sent one, and from a `next`
 * link where it did not. Plans send the total only when asked, and Endpoint\Plans always asks.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * @param  list<T>  $items
     * @param  int  $page  the page that was asked for, from 1
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page = 1,
        public readonly ?int $totalItems = null,
        public readonly ?int $totalPages = null,
        public readonly Links $links = new Links(),
    ) {
    }

    public function hasMore(): bool
    {
        if ($this->totalPages !== null) {
            return $this->page < $this->totalPages;
        }

        return $this->items !== [] && $this->links->next() !== null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<T>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
