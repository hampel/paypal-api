<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Endpoint\Transactions;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Enum\TransactionStatus;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Filter\TransactionFilter;

final class TransactionsTest extends TestCase
{
    private static function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('UTC'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function page(array $rows, int $totalPages = 1): array
    {
        return ['transaction_details' => $rows, 'total_items' => count($rows), 'total_pages' => $totalPages, 'page' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $id): array
    {
        return ['transaction_info' => ['transaction_id' => $id, 'transaction_event_code' => 'T0006']];
    }

    public function testASearchSendsTheDatesInUtcWithTheLargestPageAndEveryField(): void
    {
        $this->pushFixture('transactions');

        $this->paypal()->transactions()->search(
            new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('Australia/Sydney')),
            self::at('2026-08-31 23:59:59')
        );

        $this->assertSame('/v1/reporting/transactions', $this->sentPath());
        $this->assertSame([
            'start_date' => '2026-08-01T00:00:00Z',
            'end_date' => '2026-08-31T23:59:59Z',
            'fields' => 'all',
            'page_size' => '500',
            'page' => '1',
        ], $this->sentQuery());
    }

    public function testFiltersAreSentByPayPalsNames(): void
    {
        $this->pushFixture('transactions');

        $filter = TransactionFilter::make()
            ->withTransactionId('5TY05013RG002845M')
            ->withEventCode('t0002')
            ->withStatus(TransactionStatus::Success)
            ->inCurrency('usd')
            ->amountBetween(500, 1005)
            ->withFields('transaction_info', 'payer_info')
            ->includingNonBalanceAffecting();

        $this->paypal()->transactions()->search(self::at('2026-08-01'), self::at('2026-08-02'), $filter, 2, 50);

        $query = $this->sentQuery();

        $this->assertSame('5TY05013RG002845M', $query['transaction_id']);
        $this->assertSame('T0002', $query['transaction_type']);
        $this->assertSame('S', $query['transaction_status']);
        $this->assertSame('USD', $query['transaction_currency']);
        $this->assertSame('[500 TO 1005]', $query['transaction_amount']);
        $this->assertSame('transaction_info,payer_info', $query['fields']);
        $this->assertSame('N', $query['balance_affecting_records_only']);
        $this->assertSame('2', $query['page']);
        $this->assertSame('50', $query['page_size']);
    }

    public function testAFilterIsImmutable(): void
    {
        $base = TransactionFilter::make();
        $narrowed = $base->inCurrency('AUD');

        $this->assertArrayNotHasKey('transaction_currency', $base->toQuery());
        $this->assertSame('AUD', $narrowed->toQuery()['transaction_currency']);
        $this->assertSame('transaction_info', $base->onlyTransactionInfo()->toQuery()['fields']);
    }

    public function testAFilterCannotSetTheDatesOrThePage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionFilter::make()->withParameter('start_date', '2026-01-01T00:00:00Z');
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionFilter::make()->withFields('transaction_info', 'payer');
    }

    public function testThirtyOneDaysIsAcceptedAndASecondMoreIsRefusedUnsent(): void
    {
        $this->pushFixture('transactions');

        $this->paypal()->transactions()->search(self::at('2026-08-01 00:00:00'), self::at('2026-09-01 00:00:00'));

        try {
            $this->paypal()->transactions()->search(self::at('2026-08-01 00:00:00'), self::at('2026-09-01 00:00:01'));
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('each()', $e->getMessage());
        }

        $this->assertCount(1, $this->client->requests);
    }

    public function testARangeThatEndsBeforeItStartsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paypal()->transactions()->search(self::at('2026-08-02'), self::at('2026-08-01'));
    }

    public function testAPageSizePastFiveHundredIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paypal()->transactions()->search(self::at('2026-08-01'), self::at('2026-08-02'), pageSize: 501);
    }

    public function testWindowsAreGaplessAndNoneIsWiderThanASearchAllows(): void
    {
        $from = self::at('2026-01-01 00:00:00');
        $to = self::at('2026-04-15 12:00:00');

        $windows = Transactions::windows($from, $to);

        $this->assertCount(4, $windows);
        $this->assertEquals($from, $windows[0][0]);
        $this->assertEquals($to, $windows[3][1]);

        foreach ($windows as $index => [$start, $end]) {
            $this->assertLessThanOrEqual(Transactions::MAX_RANGE_DAYS * 86400, $end->getTimestamp() - $start->getTimestamp());

            if (isset($windows[$index + 1])) {
                $this->assertSame(1, $windows[$index + 1][0]->getTimestamp() - $end->getTimestamp());
            }
        }
    }

    public function testARangeOfExactlyOneSecondIsOneWindow(): void
    {
        $this->assertCount(1, Transactions::windows(self::at('2026-01-01 00:00:00'), self::at('2026-01-01 00:00:00')));
    }

    public function testEachWalksEveryWindowAndEveryPage(): void
    {
        // window one: two pages; window two: one page
        $this->client->pushJson(200, self::page([self::row('A'), self::row('B')], 2));
        $this->client->pushJson(200, self::page([self::row('C')], 2));
        $this->client->pushJson(200, self::page([self::row('D')]));

        $ids = [];

        foreach ($this->paypal()->transactions()->each(self::at('2026-01-01'), self::at('2026-02-10')) as $transaction) {
            $ids[] = $transaction->id;
        }

        $this->assertSame(['A', 'B', 'C', 'D'], $ids);
        $this->assertCount(3, $this->client->requests);

        parse_str($this->client->requests[1]->getUri()->getQuery(), $second);
        parse_str($this->client->requests[2]->getUri()->getQuery(), $third);

        $this->assertSame('2', $second['page']);
        $this->assertSame('2026-01-31T23:59:59Z', $second['end_date']);
        $this->assertSame('2026-02-01T00:00:00Z', $third['start_date']);
        $this->assertSame('1', $third['page']);
    }

    public function testStoppingEarlyStopsTheRequests(): void
    {
        $this->client->pushJson(200, self::page([self::row('A'), self::row('B')], 5));

        foreach ($this->paypal()->transactions()->each(self::at('2025-01-01'), self::at('2026-01-01')) as $transaction) {
            break;
        }

        $this->assertCount(1, $this->client->requests);
    }

    public function testWithoutATotalAFullPageMeansAnotherIsAskedFor(): void
    {
        $this->client->pushJson(200, ['transaction_details' => [self::row('A'), self::row('B')]]);
        $this->client->pushJson(200, ['transaction_details' => [self::row('C')]]);

        $paypal = $this->paypal(new Config(Environment::Sandbox, 2));
        $ids = array_map(static fn ($row) => $row->id, iterator_to_array($paypal->transactions()->each(self::at('2026-01-01'), self::at('2026-01-02')), false));

        $this->assertSame(['A', 'B', 'C'], $ids);
    }

    public function testARowIsMappedWithItsPayerAndCart(): void
    {
        $this->pushFixture('transactions');

        $page = $this->paypal()->transactions()->search(self::at('2026-08-01'), self::at('2026-08-31'));
        $payment = $page->transactions[0];

        $this->assertSame(3, $page->totalItems);
        $this->assertFalse($page->hasMore());
        $this->assertSame('XZXSPECPDZHZU', $page->accountNumber);
        $this->assertSame('2026-09-15T09:59:59+00:00', $page->lastRefreshedAt?->format(DATE_ATOM));

        $this->assertSame('5TY05013RG002845M', $payment->id);
        $this->assertSame(TransactionStatus::Success, $payment->transactionStatus());
        $this->assertSame('110.00 USD', (string) $payment->amount);
        $this->assertSame('-4.62', $payment->fee?->value);
        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $payment->customField);
        $this->assertSame('INV-2026-0042', $payment->invoiceId);
        $this->assertSame('I-BW452GLLEP1G', $payment->referenceId);
        $this->assertSame('SUB', $payment->referenceIdType);
        $this->assertSame('2026-08-03', $payment->initiatedAt?->format('Y-m-d'));

        $payer = $payment->payer;

        $this->assertNotNull($payer);
        $this->assertSame('buyer@example.com', $payer->email);
        $this->assertSame('Alex Example', $payer->name());
        $this->assertTrue($payer->verified);
        $this->assertFalse($payer->addressVerified);

        $this->assertCount(1, $payment->cartItems);
        $this->assertSame('premium-annual', $payment->cartItems[0]->code);
        $this->assertSame('110.00', $payment->cartItems[0]->total?->value);

        $this->assertSame(['shipping_info' => ['name' => 'Alex Example']], array_intersect_key($payment->raw, ['shipping_info' => true]));
    }

    public function testEventCodesClassifyARow(): void
    {
        $this->pushFixture('transactions');

        $rows = $this->paypal()->transactions()->search(self::at('2026-08-01'), self::at('2026-08-31'))->transactions;
        $payment = $rows[0];
        $conversionOut = $rows[1];
        $conversionIn = $rows[2];

        $this->assertTrue($payment->isPayment());
        $this->assertTrue($payment->isSubscriptionPayment());
        $this->assertFalse($payment->isCurrencyConversion());
        $this->assertSame('T00', $payment->eventGroup());

        $this->assertTrue($conversionOut->isCurrencyConversion());
        $this->assertFalse($conversionOut->isPayment());
        $this->assertSame($payment->id, $conversionIn->referenceId, 'the rows that belong together share a reference id');
        $this->assertNull($conversionOut->payer, 'an empty payer_info is no payer');
    }

    public function testBalances(): void
    {
        $this->pushFixture('balances');

        $balances = $this->paypal()->transactions()->balances(self::at('2026-09-15 09:00:00'), 'aud');

        $this->assertSame(['as_of_time' => '2026-09-15T09:00:00Z', 'currency_code' => 'AUD'], $this->sentQuery());
        $this->assertSame('1543.21', $balances->primary()?->total?->value);
        $this->assertSame('43.21', $balances->inCurrency('aud')?->withheld?->value);
        $this->assertNull($balances->inCurrency('USD')?->available);
        $this->assertNull($balances->inCurrency('EUR'));
    }

    public function testWithIdIncludesNonBalanceAffectingRows(): void
    {
        $this->client->pushJson(200, self::page([self::row('5TY05013RG002845M'), self::row('5TY05013RG002845M')]));

        $rows = $this->paypal()->transactions()->withId('5TY05013RG002845M', self::at('2026-08-01'), self::at('2026-08-20'));

        $this->assertCount(2, $rows);
        $this->assertSame('N', $this->sentQuery()['balance_affecting_records_only']);
    }
}
