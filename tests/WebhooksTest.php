<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use Hampel\PayPal\Api\Entity\Capture;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\ValidationException;
use Hampel\PayPal\Api\Request\WebhookNotification;

final class WebhooksTest extends TestCase
{
    private const WEBHOOK_ID = '40Y916089Y8324740';

    /**
     * A body written the way no PHP encoder would write it again: escaped slashes, a trailing zero,
     * spacing. Anything that decodes and re-encodes it changes the bytes PayPal signed.
     */
    private const BODY = '{"id": "WH-1", "event_type": "PAYMENT.CAPTURE.COMPLETED", "resource": {"id": "42311647XV020574X", '
        . '"custom_id": "abc", "amount": {"currency_code": "USD", "value": "1.10"}, "rate": 1.10, '
        . '"href": "https:\/\/api-m.sandbox.paypal.com\/v2\/payments\/captures\/42311647XV020574X"}}';

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Paypal-Auth-Algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77',
            'PAYPAL-TRANSMISSION-ID' => '103e3700-8b0c-11ea-8bba-0f4ea2dbe3f8',
            'PayPal-Transmission-Sig' => 'NhSu/ybi5b0Pr6x1Uu8cMGrGlBTE5VnVX7XJHF/LMBGZ9sSwS==',
            'PayPal-Transmission-Time' => '2026-08-03T22:15:10Z',
            'Content-Type' => 'application/json',
        ];
    }

    public function testTheEventIsSplicedIntoTheVerificationByteForByte(): void
    {
        $this->client->pushJson(200, ['verification_status' => 'SUCCESS']);

        $notification = WebhookNotification::fromHeaders(self::headers(), self::BODY);

        $this->assertTrue($this->paypal()->webhooks()->verifySignature($notification, self::WEBHOOK_ID));

        $sent = (string) $this->client->lastRequest()->getBody();

        $this->assertSame('/v1/notifications/verify-webhook-signature', $this->sentPath());
        $this->assertStringEndsWith(',"webhook_event":' . self::BODY . '}', $sent);
        $this->assertSame([
            'auth_algo' => 'SHA256withRSA',
            'cert_url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-a5cafa77',
            'transmission_id' => '103e3700-8b0c-11ea-8bba-0f4ea2dbe3f8',
            'transmission_sig' => 'NhSu/ybi5b0Pr6x1Uu8cMGrGlBTE5VnVX7XJHF/LMBGZ9sSwS==',
            'transmission_time' => '2026-08-03T22:15:10Z',
            'webhook_id' => self::WEBHOOK_ID,
            'webhook_event' => json_decode(self::BODY, true),
        ], json_decode($sent, true), 'the spliced body is still one well-formed request');
    }

    public function testAFailureIsFalse(): void
    {
        $this->client->pushJson(200, ['verification_status' => 'FAILURE']);

        $this->assertFalse($this->paypal()->webhooks()->verifySignature(WebhookNotification::fromHeaders(self::headers(), self::BODY), self::WEBHOOK_ID));
    }

    public function testARefusedVerificationRaisesRatherThanAnsweringFalse(): void
    {
        $this->client->pushJson(400, ['name' => 'VALIDATION_ERROR', 'message' => 'Invalid data provided']);

        $this->expectException(ValidationException::class);

        $this->paypal()->webhooks()->verifySignature(WebhookNotification::fromHeaders(self::headers(), self::BODY), self::WEBHOOK_ID);
    }

    public function testAnAnswerWithoutAStatusIsMalformed(): void
    {
        $this->client->pushJson(200, ['something' => 'else']);

        $this->expectException(MalformedResponseException::class);

        $this->paypal()->webhooks()->verifySignature(WebhookNotification::fromHeaders(self::headers(), self::BODY), self::WEBHOOK_ID);
    }

    public function testServerStyleHeadersAreRead(): void
    {
        $server = [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'id-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-08-03T22:15:10Z',
            'REQUEST_METHOD' => 'POST',
        ];

        $notification = WebhookNotification::fromHeaders($server, self::BODY);

        $this->assertSame('id-1', $notification->transmissionId);
        $this->assertSame('2026-08-03T22:15:10+00:00', $notification->transmittedAt()?->format(DATE_ATOM));
    }

    public function testAPsr7RequestIsRead(): void
    {
        $request = new ServerRequest('POST', 'https://example.com/paypal/webhook', self::headers(), self::BODY);
        $request->getBody()->getContents();

        $notification = WebhookNotification::fromServerRequest($request);

        $this->assertSame(self::BODY, $notification->body, 'the stream is rewound, not read from where a framework left it');
    }

    public function testAMissingHeaderIsRefusedByName(): void
    {
        $headers = self::headers();
        unset($headers['PayPal-Transmission-Sig']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYPAL-TRANSMISSION-SIG');

        WebhookNotification::fromHeaders($headers, self::BODY);
    }

    public function testABodyThatIsNotAJsonObjectIsRefused(): void
    {
        foreach (['', 'nope', '[1, 2]', '"string"'] as $body) {
            try {
                WebhookNotification::fromHeaders(self::headers(), $body);
                $this->fail('expected a refusal for ' . $body);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The webhook id is interpolated into nothing, but it is spliced beside the body, so anything
     * but letters and digits is refused rather than escaped.
     */
    public function testAWebhookIdThatIsNotAlphanumericIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebhookNotification::fromHeaders(self::headers(), self::BODY)->verificationPayload('abc","webhook_event":{}}');
    }

    public function testTheEventDecodesAndFindsTheCustomIdWhereverTheResourceKeepsIt(): void
    {
        $event = WebhookNotification::fromHeaders(self::headers(), self::BODY)->event();

        $this->assertSame('PAYMENT.CAPTURE.COMPLETED', $event->eventType);
        $this->assertSame('abc', $event->customId());
        $this->assertSame('42311647XV020574X', $event->resourceId());

        $order = WebhookNotification::fromHeaders(self::headers(), '{"resource": {"purchase_units": [{"custom_id": "from-order"}]}}')->event();
        $sale = WebhookNotification::fromHeaders(self::headers(), '{"resource": {"custom": "from-sale"}}')->event();

        $this->assertSame('from-order', $order->customId());
        $this->assertSame('from-sale', $sale->customId());
    }

    public function testTheNotificationDoesNotPrintItsBody(): void
    {
        $printed = print_r(WebhookNotification::fromHeaders(self::headers(), self::BODY), true);

        $this->assertStringNotContainsString('custom_id', $printed);
    }

    public function testRegisteredWebhooks(): void
    {
        $this->pushFixture('webhooks');

        $webhooks = $this->paypal()->webhooks()->list();

        $this->assertSame('/v1/notifications/webhooks', $this->sentPath());
        $this->assertSame(self::WEBHOOK_ID, $webhooks[0]->id);
        $this->assertSame(['PAYMENT.CAPTURE.COMPLETED', 'BILLING.SUBSCRIPTION.CANCELLED'], $webhooks[0]->eventTypes);
    }

    public function testAnEventIsReadAndItsResourceMapsAsTheEntityItIs(): void
    {
        $this->pushFixture('webhook-event');

        $event = $this->paypal()->webhooks()->event('WH-2WR32451HC0233532-67976317FL4543714');

        $this->assertSame('/v1/notifications/webhooks-events/WH-2WR32451HC0233532-67976317FL4543714', $this->sentPath());
        $this->assertSame('2.0', $event->resourceVersion);
        $this->assertSame('105.38', Capture::fromArray($event->resource)->net()?->value);
    }

    public function testEachEventFollowsNextLinks(): void
    {
        $this->client->pushJson(200, [
            'events' => [self::fixture('webhook-event')],
            'count' => 1,
            'links' => [['href' => 'https://api-m.sandbox.paypal.com/v1/notifications/webhooks-events?page_size=1&start_id=WH-2', 'rel' => 'next', 'method' => 'GET']],
        ]);
        $this->client->pushJson(200, ['events' => [['id' => 'WH-2']], 'count' => 1, 'links' => []]);

        $events = iterator_to_array($this->paypal()->webhooks()->eachEvent(eventType: 'PAYMENT.CAPTURE.COMPLETED', pageSize: 1), false);

        $this->assertSame(['WH-2WR32451HC0233532-67976317FL4543714', 'WH-2'], array_map(static fn ($event) => $event->id, $events));

        parse_str($this->client->requests[0]->getUri()->getQuery(), $first);

        $this->assertSame(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'page_size' => '1'], $first);
        $this->assertSame('start_id=WH-2', explode('&', $this->client->requests[1]->getUri()->getQuery())[1]);
    }

    public function testANextLinkToAnotherHostIsNotFollowed(): void
    {
        $this->client->pushJson(200, [
            'events' => [['id' => 'WH-1']],
            'links' => [['href' => 'https://attacker.example/steal', 'rel' => 'next']],
        ]);

        $this->expectException(\Hampel\PayPal\Api\Exception\RuntimeException::class);

        iterator_to_array($this->paypal()->webhooks()->eachEvent(), false);
    }
}
