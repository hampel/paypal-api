<?php

/**
 * Exercise: probe transaction search's documented limits and print a window's rows - reads only.
 *
 * QUESTIONS THIS SETTLES THAT THE SUITE CANNOT - all documented and unmeasured when it was written;
 * CLAUDE.md's Measured section has the answers so far:
 *
 *   - IS TRANSACTION SEARCH ENABLED, and if not, what does the refusal look like?
 *   - IS 31 DAYS THE LIMIT? A range of exactly 31 days, and one of 31 days and a second, raw -
 *     ending an hour before the last refresh, since a later end is cut back and proves nothing.
 *   - IS 500 THE LARGEST PAGE? 500 and 501, raw.
 *   - IS A FILTER HONOURED? The same window unfiltered and filtered by a transaction id that cannot
 *     exist. The dangerous answer is not an error: it is the unfiltered count again.
 *   - IS `end_date` INCLUSIVE? A window ending on a transaction's own initiation second - is the
 *     transaction in it? Transactions::each() steps its windows a second apart on the assumption
 *     that it is.
 *   - is the response's `page` zero- or one-relative? The specification says zero, the request
 *     counts from one.
 *   - WHAT SIGN IS A FEE, and in which currency - the payer's or the account's?
 *   - HOW IS A FOREIGN-CURRENCY PAYMENT LAID OUT? Every row sharing a reference id is printed
 *     together, so a USD payment into an AUD account shows beside its conversion rows.
 *   - which date form comes back - `+0000` or `+00:00` - and in which zone?
 *   - how many rows does including non-balance-affecting records add?
 *   - how far behind is `last_refreshed_datetime`?
 *
 * Needs PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET, and the app's Transaction Search permission.
 * PAYPAL_HARNESS_DAYS sets the window, 31 by default. Sends about twelve requests.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Exception\RuntimeException;
use Hampel\PayPal\Api\Filter\TransactionFilter;

require __DIR__ . '/lib/harness.php';

$io->title('paypal · transactions');

$paypal = harness_client($io);
[$from, $to] = harness_window();
$failure = null;

$io->values(['window' => sprintf('%s to %s', harness_utc($from), harness_utc($to))]);
$io->line();

try {
    $grant = $paypal->verify();

    $io->values(['Transaction Search scope' => $grant->canSearchTransactions() ? 'yes' : 'no']);
    $io->line();

    $io->info('one raw search of the window, before the package reads it');

    $raw = harness_raw($paypal, 'v1/reporting/transactions', [
        'start_date' => harness_utc($from),
        'end_date' => harness_utc($to),
        'fields' => 'all',
        'page_size' => 500,
        'page' => 1,
    ]);

    $io->line('  ' . harness_describe($raw, 300));

    if ($raw->getStatusCode() !== 200) {
        throw new RuntimeException('the search was refused, and everything below needs it');
    }

    $body = harness_json($raw);

    $io->values([
        'page (response)' => var_export($body['page'] ?? null, true) . ' - the request asked for 1',
        'total_items' => var_export($body['total_items'] ?? null, true),
        'total_pages' => var_export($body['total_pages'] ?? null, true),
        'start_date' => var_export($body['start_date'] ?? null, true),
        'end_date' => var_export($body['end_date'] ?? null, true),
        'last_refreshed_datetime' => var_export($body['last_refreshed_datetime'] ?? null, true),
    ]);

    $page = $paypal->transactions()->search($from, $to);

    if ($page->lastRefreshedAt !== null) {
        $io->values(['reporting lag' => sprintf('%.1f hours behind now', (time() - $page->lastRefreshedAt->getTimestamp()) / 3600)]);
    }

    $io->line();
    $io->info('the 31-day limit, raw');

    // Anchored at the last refresh, not at now: PayPal cuts an end later than its last refresh back
    // to it (measured 2026-11-18), which turned the first run's "31 days and a second" into less
    // than 31 days and settled nothing. Each probe prints the dates PayPal answered with, so a cut
    // that still happens is visible rather than read as an accepted range.
    $anchor = $page->lastRefreshedAt !== null
        ? (new DateTimeImmutable('@' . $page->lastRefreshedAt->getTimestamp()))->modify('-1 hour')
        : $to->modify('-4 hours');
    $start = $anchor->modify('-31 days');

    $io->values(['anchored at' => harness_utc($anchor) . ' - an hour before the last refresh']);

    foreach (['exactly 31 days' => $anchor, '31 days and 1 second' => $anchor->modify('+1 second')] as $label => $end) {
        $response = harness_raw($paypal, 'v1/reporting/transactions', ['start_date' => harness_utc($start), 'end_date' => harness_utc($end), 'page_size' => 1]);
        $answer = $response->getStatusCode() === 200 ? harness_json($response) : [];

        $io->values([
            $label => sprintf('asked %s to %s', harness_utc($start), harness_utc($end)),
            '  answered' => $response->getStatusCode() === 200
                ? sprintf('HTTP 200, %s to %s', var_export($answer['start_date'] ?? null, true), var_export($answer['end_date'] ?? null, true))
                : harness_describe($response, 240),
        ]);
    }

    $io->line();
    $io->info('the largest page, raw');

    foreach ([500, 501] as $size) {
        $response = harness_raw($paypal, 'v1/reporting/transactions', ['start_date' => harness_utc($from), 'end_date' => harness_utc($to), 'page_size' => $size]);
        $io->values(['page_size ' . $size => harness_describe($response, 240)]);
    }

    $io->line();
    $io->info('is a filter honoured? the same window, unfiltered and filtered by an id that cannot exist');

    $needle = strtoupper(substr('ZZ' . bin2hex(random_bytes(9)), 0, 17));
    $baseline = $paypal->transactions()->search($from, $to, pageSize: 1);
    $filtered = $paypal->transactions()->search($from, $to, TransactionFilter::make()->withTransactionId($needle), pageSize: 1);

    $io->values([
        'unfiltered total_items' => var_export($baseline->totalItems, true),
        'filtered total_items' => var_export($filtered->totalItems, true) . sprintf(' (transaction_id=%s)', $needle),
    ]);

    $withPending = $paypal->transactions()->search($from, $to, TransactionFilter::make()->includingNonBalanceAffecting(), pageSize: 1);

    $io->values(['with non-balance-affecting' => var_export($withPending->totalItems, true)]);

    $rows = $page->transactions;

    if ($rows === []) {
        throw new RuntimeException('no transactions in the window - make a sandbox purchase, or widen PAYPAL_HARNESS_DAYS');
    }

    $io->line();
    $io->info('is end_date inclusive? a one-second window ending on a transaction\'s own initiation second');

    $probeId = null;
    $at = null;

    foreach ($rows as $row) {
        if ($row->id !== null && $row->initiatedAt !== null) {
            [$probeId, $at] = [$row->id, $row->initiatedAt];

            break;
        }
    }

    if ($probeId !== null) {
        $byId = TransactionFilter::make()->withTransactionId($probeId)->includingNonBalanceAffecting();
        $ending = $paypal->transactions()->search($at->modify('-1 second'), $at, $byId);
        $starting = $paypal->transactions()->search($at, $at->modify('+1 second'), $byId);

        $io->values([
            'transaction' => sprintf('%s initiated %s', $probeId, $at->format(DATE_ATOM)),
            'window ending on it' => count($ending) > 0 ? 'FOUND - end_date is inclusive' : 'not found - end_date is exclusive, and each() has a one-second gap',
            'window starting on it' => count($starting) > 0 ? 'FOUND - start_date is inclusive' : 'not found - start_date is exclusive',
        ]);
    }

    $io->line();
    $io->info('every row, grouped by reference id - a payment beside its fee and conversion rows');

    /** @var array<string, list<Transaction>> $groups */
    $groups = [];

    // A row that references another transaction (`TXN`) belongs with it; any other row - a payment
    // whose reference is its order or subscription - heads its own group.
    foreach ($rows as $row) {
        $key = $row->referenceIdType === 'TXN' && $row->referenceId !== null ? $row->referenceId : ($row->id ?? '?');
        $groups[$key][] = $row;
    }

    foreach ($groups as $reference => $members) {
        $io->line();
        $io->line('  transaction ' . $reference);

        foreach ($members as $row) {
            $io->values([
                '    ' . ($row->eventCode ?? '?') . ' ' . ($row->id ?? '?') => sprintf(
                    '%s  amount %s  fee %s  status %s  %s',
                    $row->raw['transaction_info']['transaction_initiation_date'] ?? '?',
                    $row->amount ?? '-',
                    $row->fee ?? '-',
                    $row->status ?? '-',
                    $row->referenceIdType ?? ''
                ),
            ]);

            if ($row->customField !== null || $row->invoiceId !== null || $row->payer !== null) {
                $io->values([
                    '      custom_field' => $row->customField ?? '-',
                    '      invoice_id' => $row->invoiceId ?? '-',
                    '      payer' => $row->payer === null ? '-' : sprintf('%s <%s>', $row->payer->name() ?? '?', $row->payer->email ?? '?'),
                    '      ending_balance' => $row->endingBalance ?? '-',
                    '      cart items' => (string) count($row->cartItems),
                ]);
            }
        }
    }

    $io->line();
    $io->info('balances');

    foreach ($paypal->transactions()->balances()->balances as $balance) {
        $io->values([
            '  ' . ($balance->currency ?? '?') => sprintf(
                'total %s, available %s, withheld %s%s',
                $balance->total ?? '-',
                $balance->available ?? '-',
                $balance->withheld ?? '-',
                $balance->primary === true ? ' (primary)' : ''
            ),
        ]);
    }
} catch (ExceptionInterface $e) {
    $failure = $e;
}

if ($failure !== null) {
    $io->error($failure::class);
    $io->error($failure->getMessage());

    exit(1);
}
