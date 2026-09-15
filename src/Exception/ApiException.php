<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

use Hampel\PayPal\Api\ErrorDetail;
use Psr\Http\Message\ResponseInterface;

/**
 * PayPal answered, and the answer was not a success.
 *
 * READ THE TYPE, THEN THE ISSUES. PayPal's error body names a class of failure - `INVALID_REQUEST`,
 * `NOT_AUTHORIZED` - with a message that is the same sentence for every error of that class, and
 * puts what actually went wrong in `details`: a field and an issue code per problem. The
 * subclasses are the statuses a caller can act on; issues() is the detail.
 *
 * `debugId` is PayPal's correlation id for the request, from the `PayPal-Debug-Id` header or the
 * body. Quote it to PayPal; nothing else identifies the request to them.
 */
abstract class ApiException extends PayPalException
{
    /**
     * @param  string  $body  the raw response body, for when it was not JSON at all
     */
    final public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?ErrorDetail $error = null,
        public readonly string $body = '',
        public readonly ?int $retryAfter = null,
        public readonly ?string $debugId = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(string $method, string $uri, ResponseInterface $response, string $body): self
    {
        $status = $response->getStatusCode();
        $error = ErrorDetail::fromBody($body);
        $debugId = self::debugIdOf($response) ?? $error?->debugId;
        $detail = $error?->describe() ?? '';

        if ($detail === '' && trim($body) !== '' && !ErrorDetail::looksLikeHtml($body)) {
            $detail = trim((string) preg_replace('/\s+/', ' ', substr($body, 0, 500)));
        }

        if ($detail === '' && $status === 404) {
            $detail = 'no error body, which is what an unrouted path looks like rather than a missing resource';
        }

        $message = sprintf(
            'PayPal rejected %s %s (HTTP %d)%s%s',
            $method,
            $uri,
            $status,
            $detail === '' ? '' : ': ' . $detail,
            $debugId === null ? '' : sprintf(' [debug id %s]', $debugId)
        );

        $retryAfter = self::retryAfter($response);

        return match (true) {
            $status === 400 => new ValidationException($message, $status, $error, $body, $retryAfter, $debugId),
            $status === 401 => new NotAuthenticatedException($message, $status, $error, $body, $retryAfter, $debugId),
            $status === 403 => new NotPermittedException($message, $status, $error, $body, $retryAfter, $debugId),
            $status === 404 => new NotFoundException($message, $status, $error, $body, $retryAfter, $debugId),
            $status === 422 => new UnprocessableException($message, $status, $error, $body, $retryAfter, $debugId),
            $status === 429 => new TooManyRequestsException($message, $status, $error, $body, $retryAfter, $debugId),
            $status >= 500 => new ServerException($message, $status, $error, $body, $retryAfter, $debugId),
            default => new ClientException($message, $status, $error, $body, $retryAfter, $debugId),
        };
    }

    /**
     * PayPal's name for the failure - `INVALID_REQUEST`, `RESOURCE_NOT_FOUND` - or null when the
     * body carried none.
     */
    public function errorName(): ?string
    {
        return $this->error?->name;
    }

    /**
     * Every fine-grained issue code the body gave, in order - `INVALID_PARAMETER_SYNTAX`.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        return $this->error?->issues() ?? [];
    }

    public function hasIssue(string $issue): bool
    {
        return $this->error?->hasIssue($issue) ?? false;
    }

    protected static function debugIdOf(ResponseInterface $response): ?string
    {
        $header = trim($response->getHeaderLine('PayPal-Debug-Id'));

        return $header === '' ? null : $header;
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }
}
