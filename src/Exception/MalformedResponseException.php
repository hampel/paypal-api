<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * A success whose body is not JSON.
 *
 * The failure worth being loud about in a client whose answers are mostly lists: a maintenance
 * page, a proxy's error document or a truncated response is a 200 with something other than JSON
 * in it, and decoded permissively it becomes an empty search that reaches a reconciliation as
 * "no payments this month".
 *
 * Extends ApiException so an existing `catch (ApiException)` sees it, even though nothing was
 * rejected.
 */
final class MalformedResponseException extends ApiException
{
    public static function forResponse(string $method, string $uri, ResponseInterface $response, string $body): self
    {
        $excerpt = trim(substr($body, 0, 200));

        return new self(
            sprintf(
                'PayPal answered %s %s with HTTP %d but the body is not JSON (Content-Type: %s)%s',
                $method,
                $uri,
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type') ?: 'none',
                $excerpt === '' ? '; the body was empty' : ': ' . $excerpt
            ),
            $response->getStatusCode(),
            null,
            $body,
            null,
            self::debugIdOf($response),
        );
    }
}
