<?php

/**
 * Exercise: read subscriptions, their payments and the plans behind a window - reads only.
 *
 * QUESTIONS THIS SETTLES THAT THE SUITE CANNOT:
 *
 *   - IS A SUBSCRIPTION PAYMENT'S REFERENCE ID THE SUBSCRIPTION ID? A `T0002` row is looked up by
 *     its `paypal_reference_id` as a subscription.
 *   - DOES THE SUBSCRIPTION'S `custom_id` MATCH THE PAYMENT ROW'S `custom_field`? That equality is
 *     what lets a payment be traced back to what was sold without the subscription read at all.
 *   - is a subscription transaction's id the same id transaction search reports for the payment?
 *   - does the subscription transaction list take a range longer than 31 days, and does it page?
 *   - does a plan list without `Prefer: return=representation` really come back minimal?
 *
 * Needs PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET, and Transaction Search. PAYPAL_SUBSCRIPTION_ID
 * names a subscription to read when the window holds no subscription payment. Sends about ten
 * requests.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\PayPal\Api\Entity\Transaction;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Filter\TransactionFilter;

require __DIR__ . '/lib/harness.php';

$io->title('paypal · subscriptions');

$paypal = harness_client($io);
[$from, $to] = harness_window();
$failure = null;

try {
    $io->info('plans, raw, without Prefer - minimal or not?');

    $minimal = harness_json(harness_raw($paypal, 'v1/billing/plans', ['page_size' => 1]));
    $plans = is_array($minimal['plans'] ?? null) ? $minimal['plans'] : [];
    $first = is_array($plans[0] ?? null) ? $plans[0] : [];

    $io->values(['keys on the first plan' => $first === [] ? '(no plans)' : implode(', ', array_keys($first))]);

    $io->line();
    $io->info('plans, through the package');

    foreach ($paypal->plans()->each() as $plan) {
        $regular = $plan->regularCycle();

        $io->values([
            '  ' . ($plan->id ?? '?') => sprintf(
                '%s - %s, %s every %s %s',
                $plan->name ?? '?',
                $plan->status ?? '?',
                $regular?->fixedPrice ?? '-',
                $regular?->intervalCount ?? '?',
                $regular?->intervalUnit ?? '?'
            ),
        ]);
    }

    $io->line();
    $io->info('subscription payments in the window');

    /** @var Transaction|null $payment */
    $payment = null;

    foreach ($paypal->transactions()->search($from, $to, TransactionFilter::make()->withEventCode('T0002')) as $row) {
        $io->line(sprintf('  %s %s  %s  reference %s (%s)  custom_field %s', $row->eventCode, $row->id, $row->amount ?? '-', $row->referenceId ?? '-', $row->referenceIdType ?? '-', $row->customField ?? '-'));
        $payment ??= $row;
    }

    $subscriptionId = $payment?->referenceId ?? (getenv('PAYPAL_SUBSCRIPTION_ID') ?: null);

    if ($subscriptionId === null) {
        $io->warn('no subscription payment in the window, and PAYPAL_SUBSCRIPTION_ID is not set - nothing more to read');
    } else {
        $io->line();
        $io->info('subscription ' . $subscriptionId);

        $subscription = $paypal->subscriptions()->find($subscriptionId, ['plan', 'last_failed_payment']);

        if ($subscription === null) {
            $io->error('  not a subscription id - find() answered null');
        } else {
            $io->values([
                '  status' => $subscription->status ?? '-',
                '  plan' => sprintf('%s (%s)', $subscription->planId ?? '-', $subscription->plan?->name ?? 'plan not returned'),
                '  custom_id' => $subscription->customId ?? '-',
                '  = payment custom_field' => $payment === null ? '(no payment to compare)' : ($payment->customField === $subscription->customId ? 'yes' : 'NO'),
                '  subscriber' => sprintf('%s <%s>', $subscription->subscriber?->name() ?? '?', $subscription->subscriber?->email ?? '?'),
                '  last payment' => sprintf('%s at %s', $subscription->billing?->lastPaymentAmount ?? '-', $subscription->billing?->lastPaymentAt?->format(DATE_ATOM) ?? '-'),
                '  next billing' => $subscription->billing?->nextBillingAt?->format(DATE_ATOM) ?? '-',
                '  failed payments' => (string) ($subscription->billing?->failedPaymentsCount ?? '-'),
            ]);

            $io->line();
            $io->info('its transactions over 90 days - longer than a transaction search may span');

            $response = harness_raw($paypal, 'v1/billing/subscriptions/' . rawurlencode($subscriptionId) . '/transactions', [
                'start_time' => harness_utc($to->modify('-90 days')),
                'end_time' => harness_utc($to),
            ]);
            $io->line('  ' . harness_describe($response, 160));

            $body = harness_json($response);
            $io->values([
                '  total_items' => var_export($body['total_items'] ?? null, true),
                '  total_pages' => var_export($body['total_pages'] ?? null, true),
                '  links' => json_encode($body['links'] ?? null) ?: '-',
            ]);

            foreach ($paypal->subscriptions()->transactions($subscriptionId, $to->modify('-90 days'), $to) as $transaction) {
                $io->values([
                    '  ' . ($transaction->id ?? '?') => sprintf(
                        '%s  gross %s  fee %s  net %s  %s',
                        $transaction->time?->format(DATE_ATOM) ?? '-',
                        $transaction->gross ?? '-',
                        $transaction->fee ?? '-',
                        $transaction->net ?? '-',
                        $payment !== null && $transaction->id === $payment->id ? '(the search row\'s id)' : ''
                    ),
                ]);
            }
        }
    }
} catch (ExceptionInterface $e) {
    $failure = $e;
}

if ($failure !== null) {
    $io->error($failure::class);
    $io->error($failure->getMessage());

    exit(1);
}
