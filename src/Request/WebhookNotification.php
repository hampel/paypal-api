<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Request;

use Hampel\PayPal\Api\Entity\WebhookEvent;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Support\Cast;
use Hampel\PayPal\Api\Support\Json;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A webhook notification as it arrived: five PayPal headers and the body, byte for byte.
 *
 *     $notification = WebhookNotification::fromServerRequest($request);
 *
 *     if ($paypal->webhooks()->verifySignature($notification, $webhookId)) {
 *         $event = $notification->event();
 *     }
 *
 * THE BODY IS KEPT AS A STRING, AND THAT IS THE POINT OF THE CLASS. PayPal signs the bytes it sent,
 * and documents that "parsing to an array/object followed by re-serializing back to a JSON string
 * can fail verification". A body decoded and encoded again differs in ways nobody can see - slashes
 * gain or lose their escaping, `1.10` becomes `1.1`, whitespace goes - so the verification request
 * splices the original bytes in as `webhook_event` rather than encoding a decoded event. See
 * verificationPayload().
 *
 * NOTHING HERE IS TRUSTED UNTIL VERIFIED. event() decodes whatever was posted; anybody can post to a
 * webhook URL.
 */
final class WebhookNotification
{
    /**
     * The request fields, and the headers PayPal sends each in.
     */
    public const HEADERS = [
        'auth_algo' => 'PAYPAL-AUTH-ALGO',
        'cert_url' => 'PAYPAL-CERT-URL',
        'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
        'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG',
        'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
    ];

    private function __construct(
        public readonly string $authAlgo,
        public readonly string $certUrl,
        public readonly string $transmissionId,
        public readonly string $transmissionSig,
        public readonly string $transmissionTime,
        public readonly string $body,
    ) {
    }

    /**
     * From a PSR-7 request, as a framework hands it to a controller.
     */
    public static function fromServerRequest(ServerRequestInterface $request): self
    {
        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[(string) $name] = $request->getHeaderLine((string) $name);
        }

        return self::fromHeaders($headers, (string) $stream);
    }

    /**
     * From headers and the raw body - `file_get_contents('php://input')`, never a re-encoded array.
     *
     * Header names match in any case, and `$_SERVER`'s `HTTP_PAYPAL_TRANSMISSION_ID` form is
     * accepted too, so `fromHeaders($_SERVER, file_get_contents('php://input'))` works as it stands.
     *
     * @param  array<array-key, mixed>  $headers  a value may be a string or a list of strings
     */
    public static function fromHeaders(array $headers, string $body): self
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('_', '-', (string) $name));
            $key = str_starts_with($key, 'HTTP-') ? substr($key, 5) : $key;
            $text = is_array($value) ? Cast::text(reset($value)) : Cast::text($value);

            if ($text !== null) {
                $normalised[$key] = trim($text);
            }
        }

        $values = [];

        foreach (self::HEADERS as $field => $header) {
            if (!isset($normalised[$header])) {
                throw new InvalidArgumentException(sprintf(
                    'This is not a PayPal webhook notification: the %s header is missing.',
                    $header
                ));
            }

            $values[$field] = $normalised[$header];
        }

        [$decoded, $event] = Json::decode($body);

        if (!$decoded || !is_array($event) || array_is_list($event)) {
            throw new InvalidArgumentException('This is not a PayPal webhook notification: the body is not a JSON object.');
        }

        return new self(
            $values['auth_algo'],
            $values['cert_url'],
            $values['transmission_id'],
            $values['transmission_sig'],
            $values['transmission_time'],
            $body,
        );
    }

    /**
     * The event, decoded - UNVERIFIED.
     */
    public function event(): WebhookEvent
    {
        [, $event] = Json::decode($this->body);

        return WebhookEvent::fromArray(Cast::object($event));
    }

    /**
     * When PayPal says it sent the notification. A handler that refuses stale notifications - a
     * replayed one carries a valid signature - compares this with the clock; PayPal documents no
     * tolerance, so the window is the caller's.
     */
    public function transmittedAt(): ?\DateTimeImmutable
    {
        return Cast::datetime($this->transmissionTime);
    }

    /**
     * The body of `POST /v1/notifications/verify-webhook-signature`, with the notification's body
     * spliced in verbatim as `webhook_event`.
     *
     * @param  string  $webhookId  the id of the webhook the notification was sent to
     */
    public function verificationPayload(string $webhookId): string
    {
        $webhookId = trim($webhookId);

        if (preg_match('/^[A-Za-z0-9]+$/', $webhookId) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A PayPal webhook id is letters and digits, such as "8PT597110X687430LKGECATA"; "%s" is not.',
                $webhookId
            ));
        }

        $fields = Json::encode([
            'auth_algo' => $this->authAlgo,
            'cert_url' => $this->certUrl,
            'transmission_id' => $this->transmissionId,
            'transmission_sig' => $this->transmissionSig,
            'transmission_time' => $this->transmissionTime,
            'webhook_id' => $webhookId,
        ]);

        return substr($fields, 0, -1) . ',"webhook_event":' . trim($this->body) . '}';
    }

    /**
     * Without the body, which can carry a payer's name and email.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'authAlgo' => $this->authAlgo,
            'certUrl' => $this->certUrl,
            'transmissionId' => $this->transmissionId,
            'transmissionTime' => $this->transmissionTime,
            'body' => sprintf('(%d bytes)', strlen($this->body)),
        ];
    }
}
