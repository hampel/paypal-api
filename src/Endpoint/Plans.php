<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Entity\Plan;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Result\Page;
use Hampel\PayPal\Api\Support\Cast;

/**
 * Billing plans, at `/v1/billing/plans`. Read-only.
 *
 * THE LIST IS MINIMAL UNLESS ASKED OTHERWISE. PayPal documents `Prefer: return=minimal` as the
 * default for a plan list - an id, a status and links, and no name or price - so every list request
 * here sends `Prefer: return=representation`.
 */
final class Plans extends Endpoint
{
    public const MAX_PAGE_SIZE = 20;

    /**
     * One page of plans, optionally for one product.
     *
     * @return Page<Plan>
     */
    public function list(?string $productId = null, int $page = 1, int $pageSize = self::MAX_PAGE_SIZE): Page
    {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf('PayPal lists plans %d at a time at most; %d was asked for.', self::MAX_PAGE_SIZE, $pageSize));
        }

        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('PayPal numbers plan pages from 1; page %d was asked for.', $page));
        }

        $response = $this->apiGet('v1/billing/plans', [
            'product_id' => $productId === null ? null : trim($productId),
            'page_size' => $pageSize,
            'page' => $page,
            'total_required' => true,
        ], ['Prefer' => 'return=representation']);

        $body = $response->object();

        return new Page(
            array_map(Plan::fromArray(...), $response->collection('plans')),
            $page,
            Cast::int($body['total_items'] ?? null),
            Cast::int($body['total_pages'] ?? null),
            $response->links(),
        );
    }

    /**
     * Every plan, a page at a time, as far as it is consumed.
     *
     * @return \Generator<int, Plan>
     */
    public function each(?string $productId = null): \Generator
    {
        $page = 1;

        do {
            $result = $this->list($productId, $page++);

            yield from $result->items;
        } while ($result->hasMore());
    }

    /**
     * One plan. Raises NotFoundException when PayPal knows no such plan.
     */
    public function get(string $id): Plan
    {
        return $this->apiObject($this->path($id), Plan::fromArray(...));
    }

    public function find(string $id): ?Plan
    {
        return $this->apiFind($this->path($id), Plan::fromArray(...));
    }

    private function path(string $id): string
    {
        return 'v1/billing/plans/' . Cast::pathSegment($id, 'plan');
    }
}
