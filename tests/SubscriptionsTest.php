<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Enum\CaptureStatus;
use Hampel\PayPal\Api\Enum\PlanStatus;
use Hampel\PayPal\Api\Enum\SubscriptionStatus;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;

final class SubscriptionsTest extends TestCase
{
    public function testASubscriptionIsReadWithItsSubscriberAndBilling(): void
    {
        $this->pushFixture('subscription');

        $subscription = $this->paypal()->subscriptions()->get('I-BW452GLLEP1G');

        $this->assertSame('/v1/billing/subscriptions/I-BW452GLLEP1G', $this->sentPath());
        $this->assertSame([], $this->sentQuery());
        $this->assertSame(SubscriptionStatus::Active, $subscription->subscriptionStatus());
        $this->assertTrue($subscription->isActive());
        $this->assertSame('P-5ML4271244454362WXNWU5NQ', $subscription->planId);
        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $subscription->customId);
        $this->assertSame('Alex Example', $subscription->subscriber?->name());
        $this->assertSame('6STWC2LSUYYYE', $subscription->subscriber->payerId);
        $this->assertNotNull($subscription->billing);
        $this->assertSame('110.00 USD', (string) $subscription->billing->lastPaymentAmount);
        $this->assertSame('2027-08-03', $subscription->billing->nextBillingAt?->format('Y-m-d'));
        $this->assertSame(0, $subscription->billing->failedPaymentsCount);
        $this->assertNull($subscription->plan);
    }

    public function testOptionalSectionsAreAskedForByName(): void
    {
        $this->pushFixture('subscription');

        $this->paypal()->subscriptions()->get('I-BW452GLLEP1G', ['plan', 'last_failed_payment']);

        $this->assertSame(['fields' => 'plan,last_failed_payment'], $this->sentQuery());
    }

    public function testAnUnknownSectionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paypal()->subscriptions()->get('I-BW452GLLEP1G', ['billing']);
    }

    public function testASubscriptionsPayments(): void
    {
        $this->pushFixture('subscription-transactions');

        $transactions = $this->paypal()->subscriptions()->transactions(
            'I-BW452GLLEP1G',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-09-01T00:00:00Z')
        );

        $this->assertSame('/v1/billing/subscriptions/I-BW452GLLEP1G/transactions', $this->sentPath());
        $this->assertSame(['start_time' => '2026-08-01T00:00:00Z', 'end_time' => '2026-09-01T00:00:00Z'], $this->sentQuery());
        $this->assertCount(1, $transactions);
        $this->assertSame(CaptureStatus::Completed, $transactions[0]->captureStatus());
        $this->assertSame('4.62', $transactions[0]->fee?->value);
        $this->assertSame('105.38', $transactions[0]->net?->value);
        $this->assertSame('Alex Example', $transactions[0]->payerName);
    }

    public function testAPlanListAsksForFullPlansAndATotal(): void
    {
        $this->pushFixture('plans');

        $page = $this->paypal()->plans()->list('PROD-XXCD1234QWER65782');

        $request = $this->client->lastRequest();

        $this->assertSame('return=representation', $request->getHeaderLine('Prefer'));
        $this->assertSame(['product_id' => 'PROD-XXCD1234QWER65782', 'page_size' => '20', 'page' => '1', 'total_required' => 'true'], $this->sentQuery());
        $this->assertTrue($page->hasMore());
        $this->assertSame(21, $page->totalItems);

        $plan = $page->items[0];

        $this->assertSame(PlanStatus::Active, $plan->planStatus());
        $this->assertCount(2, $plan->billingCycles);
        $this->assertTrue($plan->billingCycles[0]->isTrial());
        $regular = $plan->regularCycle();

        $this->assertNotNull($regular);
        $this->assertSame('110.00 USD', (string) $regular->fixedPrice);
        $this->assertSame('YEAR', $regular->intervalUnit);
    }

    public function testEachPlanWalksThePages(): void
    {
        $this->pushFixture('plans');
        $this->client->pushJson(200, ['plans' => [['id' => 'P-SECOND']], 'total_items' => 21, 'total_pages' => 2]);

        $ids = array_map(static fn ($plan) => $plan->id, iterator_to_array($this->paypal()->plans()->each(), false));

        $this->assertSame(['P-5ML4271244454362WXNWU5NQ', 'P-SECOND'], $ids);
        $this->assertSame('2', $this->sentQuery()['page']);
    }

    public function testAPlanPageLargerThanPayPalAllowsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paypal()->plans()->list(pageSize: 21);
    }
}
