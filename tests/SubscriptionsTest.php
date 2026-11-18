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

        $subscription = $this->paypal()->subscriptions()->get('I-ETC83DNY7VWA');

        $this->assertSame('/v1/billing/subscriptions/I-ETC83DNY7VWA', $this->sentPath());
        $this->assertSame([], $this->sentQuery());
        $this->assertSame(SubscriptionStatus::Active, $subscription->subscriptionStatus());
        $this->assertTrue($subscription->isActive());
        $this->assertSame('P-51P886905C292613SNL6PW5Q', $subscription->planId);
        $this->assertSame('harness-20261118T013634Z', $subscription->customId);
        $this->assertSame('Alex Example', $subscription->subscriber?->name());
        $this->assertSame('QYR5Z8XDVJNXQ', $subscription->subscriber->payerId);
        $this->assertNotNull($subscription->billing);
        $this->assertSame('10.0 USD', (string) $subscription->billing->lastPaymentAmount, 'one decimal place, as sent');
        $this->assertSame('2026-11-19T10:00:00+00:00', $subscription->billing->nextBillingAt?->format(DATE_ATOM));
        $this->assertSame(0, $subscription->billing->failedPaymentsCount);
        $this->assertNull($subscription->plan);
    }

    public function testAPayPalStandardSubscriptionHasNoPlanAndNoCustomId(): void
    {
        $this->pushFixture('subscription-standard');

        $subscription = $this->paypal()->subscriptions()->get('I-AKYE3A6CY2YH');

        $this->assertTrue($subscription->isActive());
        $this->assertNull($subscription->planId);
        $this->assertNull($subscription->customId);
        $this->assertSame('10.0 USD', (string) $subscription->billing?->lastPaymentAmount);
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
            'I-ETC83DNY7VWA',
            new \DateTimeImmutable('2026-11-17T00:00:00Z'),
            new \DateTimeImmutable('2026-11-19T00:00:00Z')
        );

        $this->assertSame('/v1/billing/subscriptions/I-ETC83DNY7VWA/transactions', $this->sentPath());
        $this->assertSame(['start_time' => '2026-11-17T00:00:00Z', 'end_time' => '2026-11-19T00:00:00Z'], $this->sentQuery());
        $this->assertCount(1, $transactions);
        $this->assertSame('3DR34135EM370681V', $transactions[0]->id, 'the T0002 row\'s id in transaction search');
        $this->assertSame(CaptureStatus::Completed, $transactions[0]->captureStatus());
        $this->assertSame('0.64', $transactions[0]->fee?->value, 'positive here, negative in transaction search');
        $this->assertSame('9.36', $transactions[0]->net?->value);
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
