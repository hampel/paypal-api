<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\PayPal\Api\Authentication\AccessToken;
use Hampel\PayPal\Api\Authentication\Authentication;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Environment;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function paypal(
        ?Config $config = null,
        ?Authentication $authentication = null,
        ?LoggerInterface $logger = null,
    ): Client {
        $factory = new HttpFactory();

        return new Client(
            $config ?? new Config(Environment::Sandbox),
            $authentication ?? new AccessToken('test-access-token'),
            $this->client,
            $factory,
            $factory,
            $logger ?? new NullLogger(),
        );
    }

    /**
     * A response body from tests/fixtures - a redacted sandbox capture, PayPal's own sample, or
     * built from the schema. CLAUDE.md's Testing section says which is which.
     *
     * @return array<string, mixed>
     */
    protected static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \LogicException(sprintf('Fixture %s is not an object.', $name));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    protected function pushFixture(string $name, int $status = 200): void
    {
        $this->client->pushJson($status, self::fixture($name));
    }

    /**
     * @return array<mixed>
     */
    protected function sentBody(): array
    {
        $decoded = json_decode((string) $this->client->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function sentPath(): string
    {
        return $this->client->lastRequest()->getUri()->getPath();
    }

    /**
     * The query string of the last request, decoded.
     *
     * @return array<string, string>
     */
    protected function sentQuery(): array
    {
        parse_str($this->client->lastRequest()->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
