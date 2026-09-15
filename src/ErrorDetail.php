<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api;

use Hampel\PayPal\Api\Support\Cast;
use Hampel\PayPal\Api\Support\Json;

/**
 * The body of a failed request, parsed from whichever of PayPal's two shapes it arrived in.
 *
 *  - PAYPAL'S OWN, from every data endpoint - one schema across all four specifications:
 *    `{"name": "INVALID_REQUEST", "message": "Request is not well-formed, syntactically incorrect,
 *    or violates schema.", "debug_id": "b1d1f06c7246c", "details": [{"field": "start_date",
 *    "location": "query", "issue": "INVALID_PARAMETER_SYNTAX", "description": "..."}]}`
 *  - AN OAUTH ERROR, from the token endpoint: `{"error": "invalid_client", "error_description":
 *    "Client Authentication failed"}`.
 *
 * THE MESSAGE IS THE LEAST USEFUL PART. It is fixed per `name` - every 400 says the request "is
 * not well-formed" - and PayPal says a detail's description "can change over the lifetime of an
 * API, so clients must not depend on this value". Branch on `name` and issues().
 *
 * Defensive at every level, because this runs on the failure path: an exception raised while
 * building an exception loses the original failure.
 */
final class ErrorDetail implements \JsonSerializable
{
    /**
     * @param  list<array{field: string|null, value: string|null, location: string|null, issue: string, description: string|null}>  $details
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $message = null,
        public readonly ?string $debugId = null,
        public readonly array $details = [],
        public readonly ?string $oauthError = null,
        public readonly ?string $informationLink = null,
        public readonly mixed $raw = null,
    ) {
    }

    public static function fromBody(string $body): ?self
    {
        [$decoded, $value] = Json::decode($body);

        return $decoded ? self::from($value) : null;
    }

    public static function from(mixed $value): ?self
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        $details = [];

        foreach (Cast::objects($value['details'] ?? null, static fn (array $row): array => $row) as $row) {
            $issue = Cast::text($row['issue'] ?? null);

            if ($issue !== null) {
                $details[] = [
                    'field' => Cast::text($row['field'] ?? null),
                    'value' => Cast::string($row['value'] ?? null),
                    'location' => Cast::text($row['location'] ?? null),
                    'issue' => $issue,
                    'description' => Cast::text($row['description'] ?? null),
                ];
            }
        }

        $name = Cast::text($value['name'] ?? null);
        $oauthError = Cast::text($value['error'] ?? null);

        if ($name === null && $oauthError === null && $details === []) {
            return null;
        }

        return new self(
            $name,
            Cast::text($value['message'] ?? null) ?? Cast::text($value['error_description'] ?? null),
            Cast::text($value['debug_id'] ?? null),
            $details,
            $oauthError,
            Cast::text($value['information_link'] ?? null),
            $value,
        );
    }

    /**
     * Whether a body that failed to decode is an HTML page rather than anything the API wrote.
     */
    public static function looksLikeHtml(string $body): bool
    {
        $start = strtolower(ltrim(substr($body, 0, 200)));

        return str_starts_with($start, '<!doctype html') || str_starts_with($start, '<html');
    }

    /**
     * @return list<string>
     */
    public function issues(): array
    {
        return array_map(static fn (array $detail): string => $detail['issue'], $this->details);
    }

    public function hasIssue(string $issue): bool
    {
        foreach ($this->details as $detail) {
            if (strcasecmp($detail['issue'], $issue) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line, for an exception message: the name, the message, then each issue with its field.
     */
    public function describe(): string
    {
        $parts = [];
        $head = trim(implode(' ', array_filter([$this->name ?? $this->oauthError, $this->message], static fn (?string $part): bool => $part !== null)));

        if ($head !== '') {
            $parts[] = $head;
        }

        foreach ($this->details as $detail) {
            $parts[] = trim(sprintf(
                '%s%s%s',
                $detail['field'] === null ? '' : $detail['field'] . ': ',
                $detail['issue'],
                $detail['description'] === null ? '' : ' - ' . $detail['description']
            ));
        }

        return trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', implode('; ', $parts)));
    }

    public function jsonSerialize(): mixed
    {
        return $this->raw;
    }
}
