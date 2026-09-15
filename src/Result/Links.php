<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Result;

use Hampel\PayPal\Api\Support\Cast;

/**
 * A resource's `links`, and the one thing they are needed for here: `next` on a list, and `up` on a
 * refund, which is where the capture it refunded is named.
 *
 * @implements \IteratorAggregate<int, Link>
 */
final class Links implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * @param  list<Link>  $links
     */
    public function __construct(
        public readonly array $links = [],
    ) {
    }

    public static function from(mixed $value): self
    {
        return new self(array_values(array_filter(Cast::objects($value, Link::fromArray(...)))));
    }

    public function first(string $rel): ?Link
    {
        foreach ($this->links as $link) {
            if (strcasecmp($link->rel, $rel) === 0) {
                return $link;
            }
        }

        return null;
    }

    public function next(): ?Link
    {
        return $this->first('next');
    }

    public function count(): int
    {
        return count($this->links);
    }

    /**
     * @return \ArrayIterator<int, Link>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->links);
    }

    /**
     * @return list<Link>
     */
    public function jsonSerialize(): array
    {
        return $this->links;
    }
}
