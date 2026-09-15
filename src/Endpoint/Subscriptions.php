<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Entity\Subscription;
use Hampel\PayPal\Api\Entity\SubscriptionTransaction;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Support\Cast;

/**
 * Subscriptions, at `/v1/billing/subscriptions`.
 *
 * READ-ONLY. Cancelling, suspending and revising a subscription are things the application that
 * sold it does; nothing this package is for needs to, and none is wrapped.
 */
final class Subscriptions extends Endpoint
{
    /**
     * The optional sections a read can ask for.
     */
    public const FIELDS = ['last_failed_payment', 'plan'];

    /**
     * One subscription. Raises NotFoundException when PayPal knows no such subscription.
     *
     * @param  list<string>  $fields  some of FIELDS - `plan` to include the plan it bills against
     */
    public function get(string $id, array $fields = []): Subscription
    {
        return $this->apiObject($this->path($id), Subscription::fromArray(...), self::fieldsQuery($fields));
    }

    /**
     * One subscription, or null when PayPal knows no such subscription.
     *
     * @param  list<string>  $fields
     */
    public function find(string $id, array $fields = []): ?Subscription
    {
        return $this->apiFind($this->path($id), Subscription::fromArray(...), self::fieldsQuery($fields));
    }

    /**
     * The payments a subscription made between two moments.
     *
     * PayPal documents both ends as required and no maximum range - unlike transaction search - and
     * no paging: the response has totals and no page parameter. Both are unmeasured.
     *
     * @return list<SubscriptionTransaction>
     */
    public function transactions(string $id, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('A subscription transaction range cannot end before it starts.');
        }

        $response = $this->apiGet($this->path($id) . '/transactions', [
            'start_time' => Cast::instantString($from),
            'end_time' => Cast::instantString($to),
        ]);

        return array_map(SubscriptionTransaction::fromArray(...), $response->collection('transactions'));
    }

    private function path(string $id): string
    {
        return 'v1/billing/subscriptions/' . Cast::pathSegment($id, 'subscription');
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    private static function fieldsQuery(array $fields): array
    {
        $unknown = array_diff($fields, self::FIELDS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'A subscription read can ask for %s; %s is not one.',
                implode(' and ', self::FIELDS),
                implode(', ', $unknown)
            ));
        }

        return ['fields' => $fields === [] ? null : implode(',', array_unique($fields))];
    }
}
