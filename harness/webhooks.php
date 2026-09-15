<?php

/**
 * Exercise: list webhooks and events, and probe a forged signature - reads only.
 *
 * QUESTIONS THIS SETTLES THAT THE SUITE CANNOT:
 *
 *   - WHAT DOES A BAD SIGNATURE ANSWER? A notification built from a real event with made-up
 *     signature headers is posted for verification. verifySignature() assumes `200 FAILURE`; if
 *     PayPal answers 400 instead, a handler sees an exception where it expected false.
 *   - where does each event type keep the merchant's reference? WebhookEvent::customId() looks in
 *     three places; each recent event prints what it found.
 *   - does the event list page by a `next` link, as eachEvent() assumes?
 *
 * NOT SETTLED HERE, AND WORTH SAYING SO: whether verifying a GENUINE notification succeeds with the
 * raw body spliced in. That needs a notification PayPal actually delivered, headers and all, which
 * only a deployed listener receives - PayPal documents that simulator events cannot be verified by
 * postback.
 *
 * Needs PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET. Sends about four requests, and verifies
 * against the first registered webhook, or PAYPAL_WEBHOOK_ID.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\PayPal\Api\Exception\ApiException;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Request\WebhookNotification;

require __DIR__ . '/lib/harness.php';

$io->title('paypal · webhooks');

$paypal = harness_client($io);
$failure = null;

try {
    $io->info('registered webhooks');

    $webhooks = $paypal->webhooks()->list();

    foreach ($webhooks as $webhook) {
        $io->values(['  ' . ($webhook->id ?? '?') => sprintf('%s - %s', $webhook->url ?? '?', implode(', ', $webhook->eventTypes))]);
    }

    if ($webhooks === []) {
        $io->line('  none');
    }

    $io->line();
    $io->info('recent events, one page of five, raw');

    $response = harness_raw($paypal, 'v1/notifications/webhooks-events', ['page_size' => 5]);
    $io->line('  ' . harness_describe($response, 120));

    $body = harness_json($response);
    $io->values(['  count' => var_export($body['count'] ?? null, true), '  links' => json_encode($body['links'] ?? null) ?: '-']);

    $page = $paypal->webhooks()->events(pageSize: 5);

    foreach ($page->items as $event) {
        $io->values([
            '  ' . ($event->id ?? '?') => sprintf(
                '%s  %s  resource %s v%s  custom id %s',
                $event->createdAt?->format(DATE_ATOM) ?? '-',
                $event->eventType ?? '-',
                $event->resourceType ?? '-',
                $event->resourceVersion ?? '-',
                $event->customId() ?? '-'
            ),
        ]);
    }

    $webhookId = getenv('PAYPAL_WEBHOOK_ID') ?: ($webhooks[0]->id ?? null);
    $event = $page->items[0] ?? null;

    $io->line();

    if ($webhookId === null || $event === null) {
        $io->warn('no webhook id or no event to forge a notification from - the signature probe is skipped');
    } else {
        $io->info(sprintf('a forged notification for webhook %s - expected: FAILURE, not an exception', $webhookId));

        $forged = WebhookNotification::fromHeaders([
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => $paypal->config()->isSandbox()
                ? 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77'
                : 'https://api.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77',
            'PAYPAL-TRANSMISSION-ID' => sprintf('%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(6))),
            'PAYPAL-TRANSMISSION-SIG' => base64_encode(random_bytes(256)),
            'PAYPAL-TRANSMISSION-TIME' => harness_utc(new \DateTimeImmutable()),
        ], (string) json_encode($event->raw, JSON_UNESCAPED_SLASHES));

        try {
            $verified = $paypal->webhooks()->verifySignature($forged, $webhookId);
            $io->values(['  verifySignature()' => $verified ? 'TRUE - A FORGED SIGNATURE WAS ACCEPTED' : 'false']);
        } catch (ApiException $e) {
            $io->values(['  verifySignature() raised' => sprintf('%s: %s', $e::class, $e->getMessage())]);
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
