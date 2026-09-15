<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\NotFoundException;
use Hampel\PayPal\Api\Exception\RequestException;
use Hampel\PayPal\Api\Exception\ServerException;

/**
 * The client does not log a failure it raises. Requests are logged at `debug`, and that is all.
 *
 * Whether an exception is a failure is decided by whoever catches it, and the client catches some
 * of its own: find() turns a 404 into null. Logged at `error` before the throw, that reports an
 * ordinary answer as a fault - and every failure a caller does log arrives twice.
 *
 * Each case here asserts nothing reached a level above `debug`, which is what would reach an
 * alerting channel.
 */
final class LoggingTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertRaisedWithoutLogging(string $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('expected ' . $expected);
        } catch (ExceptionInterface $e) {
            $this->assertInstanceOf($expected, $e);
        }

        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function testARejectedRequestIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(500, ['name' => 'INTERNAL_SERVER_ERROR']);

        $this->assertRaisedWithoutLogging(ServerException::class, fn () => $this->paypal(logger: $this->logger)->captures()->get('A'));
    }

    public function testATransportFailureIsRaisedAndNotLogged(): void
    {
        $this->client->pushThrowable(new TransportFailure($this->paypal()->connection()->request('GET', 'v2/payments/captures/A')));

        $this->assertRaisedWithoutLogging(RequestException::class, fn () => $this->paypal(logger: $this->logger)->captures()->get('A'));
    }

    public function testABodyThatIsNotJsonIsRaisedAndNotLogged(): void
    {
        $this->client->pushRaw(200, '<html>Service Unavailable</html>', ['Content-Type' => 'text/html']);

        $this->assertRaisedWithoutLogging(MalformedResponseException::class, fn () => $this->paypal(logger: $this->logger)->captures()->get('A'));
    }

    public function testAnUnroutedPathIsRaisedAndNotLogged(): void
    {
        $this->client->pushRaw(404, '');

        $this->assertRaisedWithoutLogging(NotFoundException::class, fn () => $this->paypal(logger: $this->logger)->captures()->find('A'));
    }

    public function testAMissingResourceAnsweredAsNullLogsNothingAboveDebug(): void
    {
        $this->pushFixture('not-found', 404);

        $this->assertNull($this->paypal(logger: $this->logger)->captures()->find('A'));
        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function testEachRequestIsLoggedAtDebugWithoutItsCredential(): void
    {
        $this->pushFixture('capture');

        $this->paypal(logger: $this->logger)->captures()->get('A');

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('debug', $this->logger->records[0]['level']);
        $this->assertStringNotContainsString('test-access-token', (string) json_encode($this->logger->records));
    }
}
