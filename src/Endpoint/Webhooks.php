<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Entity\Webhook;
use Hampel\PayPal\Api\Entity\WebhookEvent;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Request\WebhookNotification;
use Hampel\PayPal\Api\Result\ApiResponse;
use Hampel\PayPal\Api\Result\Page;
use Hampel\PayPal\Api\Support\Cast;

/**
 * Webhooks, at `/v1/notifications`: verifying a notification, and reading back what was sent.
 *
 * VERIFICATION IS BY POSTBACK - PayPal checks the signature and answers SUCCESS or FAILURE. PayPal
 * also documents verifying locally, with a CRC32 of the body and the certificate its header names;
 * that is not implemented here, because the certificate URL arrives in the notification and a
 * verifier that fetched it unchecked would trust whatever certificate the sender pointed at.
 *
 * Registering, changing and deleting webhooks are not wrapped: they are set up once, for an app,
 * by the application that receives them.
 */
final class Webhooks extends Endpoint
{
    /**
     * Whether PayPal signed this notification, for the webhook it names.
     *
     * TRUE ONLY FOR `SUCCESS`. A `FAILURE` is false; so is any other status PayPal might add. A
     * refused request raises, because "PayPal would not check" is not "the signature is bad".
     *
     * NOT FOR SIMULATED EVENTS. PayPal documents that an event sent by its webhook simulator cannot
     * be verified by postback, so a simulator test of a verifying handler fails here by design.
     *
     * @param  string  $webhookId  the id of the webhook the notification was sent to - see list()
     */
    public function verifySignature(WebhookNotification $notification, string $webhookId): bool
    {
        $response = $this->connection->postEncoded(
            'v1/notifications/verify-webhook-signature',
            $notification->verificationPayload($webhookId)
        );

        return self::verificationStatus($response) === 'SUCCESS';
    }

    /**
     * The webhooks registered for the app.
     *
     * @return list<Webhook>
     */
    public function list(): array
    {
        return array_map(Webhook::fromArray(...), $this->apiGet('v1/notifications/webhooks')->collection('webhooks'));
    }

    /**
     * One event PayPal sent. Raises NotFoundException when PayPal knows no such event.
     */
    public function event(string $id): WebhookEvent
    {
        return $this->apiObject('v1/notifications/webhooks-events/' . Cast::pathSegment($id, 'webhook event'), WebhookEvent::fromArray(...));
    }

    /**
     * One page of the events PayPal has sent the app - for finding one a handler missed. PayPal
     * documents both dates as optional and pages by a `next` link; see eachEvent().
     *
     * @return Page<WebhookEvent>
     */
    public function events(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $eventType = null,
        ?string $transactionId = null,
        int $pageSize = 10,
    ): Page {
        if ($pageSize < 1) {
            throw new InvalidArgumentException(sprintf('A page of webhook events holds at least one; %d was asked for.', $pageSize));
        }

        $response = $this->apiGet('v1/notifications/webhooks-events', [
            'start_time' => $from === null ? null : Cast::instantString($from),
            'end_time' => $to === null ? null : Cast::instantString($to),
            'event_type' => $eventType,
            'transaction_id' => $transactionId,
            'page_size' => $pageSize,
        ]);

        return self::eventPage($response, 1);
    }

    /**
     * Every event events() would find, following `next` links as far as it is consumed.
     *
     * @return \Generator<int, WebhookEvent>
     */
    public function eachEvent(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $eventType = null,
        ?string $transactionId = null,
        int $pageSize = 10,
    ): \Generator {
        $page = $this->events($from, $to, $eventType, $transactionId, $pageSize);

        while (true) {
            yield from $page->items;

            $next = $page->links->next();

            if ($page->isEmpty() || $next === null) {
                return;
            }

            $page = self::eventPage($this->connection->follow($next->href), $page->page + 1);
        }
    }

    /**
     * @return Page<WebhookEvent>
     */
    private static function eventPage(ApiResponse $response, int $page): Page
    {
        return new Page(
            array_map(WebhookEvent::fromArray(...), $response->collection('events')),
            $page,
            links: $response->links(),
        );
    }

    private static function verificationStatus(ApiResponse $response): string
    {
        $status = Cast::text($response->object()['verification_status'] ?? null);

        if ($status === null) {
            throw new MalformedResponseException(
                'PayPal answered a signature verification without a verification_status.',
                $response->status,
                null,
                (string) json_encode($response->body),
            );
        }

        return strtoupper($status);
    }
}
