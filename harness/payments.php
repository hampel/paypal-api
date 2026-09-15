<?php

/**
 * Exercise: read the captures and refunds behind a window's transactions - reads only.
 *
 * QUESTIONS THIS SETTLES THAT THE SUITE CANNOT:
 *
 *   - IS A PAYMENT ROW'S TRANSACTION ID ITS CAPTURE ID? A reconciliation that starts from
 *     transaction search and wants the fee breakdown needs to get from one to the other. Every
 *     `T00nn` row is looked up as a capture, and the answer printed.
 *   - DOES THE CAPTURE'S BREAKDOWN AGREE WITH THE SEARCH ROW? Gross against `transaction_amount`,
 *     fee against `fee_amount` - including sign, which the two APIs may write differently.
 *   - FOR A PAYMENT IN ANOTHER CURRENCY, where are the fee and the net: in the payer's currency
 *     with a `receivable_amount` and `exchange_rate`, as the specification describes? This decides
 *     how a USD payment is booked against an AUD account.
 *   - is a `T1107` refund row's id the refund's id, and does its `up` link name the capture?
 *   - what does a capture id that does not exist answer - `RESOURCE_NOT_FOUND`, which find() turns
 *     into null, or something find() would raise?
 *
 * Needs PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET, and Transaction Search. PAYPAL_HARNESS_DAYS sets
 * the window. Sends one request per payment and refund found, at most PAYPAL_HARNESS_LOOKUPS (10).
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Filter\TransactionFilter;

require __DIR__ . '/lib/harness.php';

$io->title('paypal · payments');

$paypal = harness_client($io);
[$from, $to] = harness_window();
$limit = max(1, (int) (getenv('PAYPAL_HARNESS_LOOKUPS') ?: 10));
$failure = null;

$io->values(['window' => sprintf('%s to %s', harness_utc($from), harness_utc($to)), 'lookups at most' => (string) $limit]);
$io->line();

try {
    $io->info('a capture id that does not exist, raw');

    $response = harness_raw($paypal, 'v2/payments/captures/0ZZ00000ZZ0000000');
    $io->line('  ' . harness_describe($response));
    $io->values(['find() answers' => var_export($paypal->captures()->find('0ZZ00000ZZ0000000'), true)]);

    $lookups = 0;

    foreach ($paypal->transactions()->each($from, $to, TransactionFilter::make()->includingNonBalanceAffecting()) as $row) {
        if ($lookups >= $limit) {
            break;
        }

        if ($row->id === null || (!$row->isPayment() && !$row->isRefund())) {
            continue;
        }

        $lookups++;

        $io->line();
        $io->info(sprintf('%s %s - %s, fee %s, custom_field %s', $row->eventCode, $row->id, $row->amount ?? '-', $row->fee ?? '-', $row->customField ?? '-'));

        if ($row->isRefund()) {
            $refund = $paypal->refunds()->find($row->id);

            if ($refund === null) {
                $io->line('  NOT a refund id - refunds()->find() answered null');

                continue;
            }

            $io->values([
                '  refund status' => $refund->status ?? '-',
                '  amount' => (string) ($refund->amount ?? '-'),
                '  fee returned' => (string) ($refund->breakdown?->paypalFee ?? '-'),
                '  net' => (string) ($refund->breakdown?->net ?? '-'),
                '  net, receivable' => (string) ($refund->breakdown?->netInReceivableCurrency ?? '-'),
                '  total refunded' => (string) ($refund->breakdown?->totalRefunded ?? '-'),
                '  capture (up link)' => $refund->captureId() ?? '-',
            ]);

            continue;
        }

        $capture = $paypal->captures()->find($row->id);

        if ($capture === null) {
            $io->line('  NOT a capture id - captures()->find() answered null');

            continue;
        }

        $breakdown = $capture->breakdown;

        $io->values([
            '  capture status' => $capture->status ?? '-',
            '  custom_id' => $capture->customId ?? '-',
            '  invoice_id' => $capture->invoiceId ?? '-',
            '  gross' => (string) ($breakdown?->gross ?? '-'),
            '  paypal fee' => (string) ($breakdown?->paypalFee ?? '-'),
            '  fee, receivable' => (string) ($breakdown?->paypalFeeInReceivableCurrency ?? '-'),
            '  net' => (string) ($breakdown?->net ?? '-'),
            '  receivable' => (string) ($breakdown?->receivable ?? '-'),
            '  exchange rate' => $breakdown?->exchangeRate === null
                ? '-'
                : sprintf('%s to %s at %s', $breakdown->exchangeRate->sourceCurrency, $breakdown->exchangeRate->targetCurrency, $breakdown->exchangeRate->value),
            '  order' => $capture->orderId ?? '-',
        ]);

        if ($breakdown?->gross !== null && $row->amount !== null) {
            $io->values([
                '  gross = search amount' => $breakdown->gross->equals($row->amount) ? 'yes' : 'NO',
                '  fee = search fee' => $breakdown->paypalFee === null || $row->fee === null
                    ? 'one is missing'
                    : ($breakdown->paypalFee->equals($row->fee) ? 'yes, same sign' : ($breakdown->paypalFee->equals($row->fee->negate()) ? 'yes, OPPOSITE sign' : 'NO')),
            ]);
        }
    }

    if ($lookups === 0) {
        $io->warn('no payments or refunds in the window - make a sandbox purchase, or widen PAYPAL_HARNESS_DAYS');
    }
} catch (ExceptionInterface $e) {
    $failure = $e;
}

if ($failure !== null) {
    $io->error($failure::class);
    $io->error($failure->getMessage());

    exit(1);
}
