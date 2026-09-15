<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Endpoint;

use Hampel\PayPal\Api\Connection;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\NotFoundException;
use Hampel\PayPal\Api\Result\ApiResponse;

/**
 * The base class for everything that groups a set of PayPal operations - this package's own, and
 * anybody else's.
 *
 *     final class Disputes extends Endpoint
 *     {
 *         public function get(string $id): array
 *         {
 *             return $this->apiGet('v1/customer/disputes/' . rawurlencode($id))->object();
 *         }
 *     }
 *
 *     $paypal->endpoint(Disputes::class)->get('PP-D-27803');
 *
 * There is nothing to register and no container. The class IS the registration, so a third-party
 * package ships one, a consumer type-hints it, and static analysis follows the return type through.
 *
 * Every helper carries an `api` prefix, which looks redundant and is not: an endpoint wants to call
 * its own methods get() and list(), and PHP will not let a subclass redeclare an inherited method
 * with a different signature.
 */
abstract class Endpoint
{
    public function __construct(
        protected readonly Connection $connection,
    ) {
    }

    /**
     * Refuse a class name that cannot be constructed as an endpoint - for a consumer reading class
     * names out of configuration, where static analysis cannot help.
     */
    public static function assertConstructible(string $class): void
    {
        if (!is_subclass_of($class, self::class) || !(new \ReflectionClass($class))->isInstantiable()) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot be constructed as a PayPal endpoint: it must be a concrete subclass of %s.',
                $class,
                self::class
            ));
        }
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     */
    protected function apiGet(string $path, array $query = [], array $headers = []): ApiResponse
    {
        return $this->connection->get($path, $query, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function apiPost(string $path, array $payload = []): ApiResponse
    {
        return $this->connection->post($path, $payload);
    }

    /**
     * A read whose answer is one resource, at the top level of the body.
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return TItem
     */
    protected function apiObject(string $path, callable $map, array $query = []): mixed
    {
        return $map($this->apiGet($path, $query)->object());
    }

    /**
     * A lookup where "no such resource" is an ordinary answer.
     *
     * ONLY A 404 NAMING A MISSING RESOURCE BECOMES NULL - see
     * NotFoundException::isMissingResource(). A 404 that does not say so is raised: a mistyped
     * path is not "that capture does not exist".
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return TItem|null
     */
    protected function apiFind(string $path, callable $map, array $query = []): mixed
    {
        try {
            return $this->apiObject($path, $map, $query);
        } catch (NotFoundException $e) {
            if (!$e->isMissingResource()) {
                throw $e;
            }

            return null;
        }
    }
}
