<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\ErrorDetail;
use Hampel\PayPal\Api\Exception\ApiException;
use Hampel\PayPal\Api\Exception\ClientException;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\NotFoundException;
use Hampel\PayPal\Api\Exception\NotPermittedException;
use Hampel\PayPal\Api\Exception\RequestException;
use Hampel\PayPal\Api\Exception\RuntimeException;
use Hampel\PayPal\Api\Exception\ServerException;
use Hampel\PayPal\Api\Exception\TooManyRequestsException;
use Hampel\PayPal\Api\Exception\UnprocessableException;
use Hampel\PayPal\Api\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConnectionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '403' => [403, NotPermittedException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ClientException::class];
        yield '422' => [422, UnprocessableException::class];
        yield '429' => [429, TooManyRequestsException::class];
        yield '500' => [500, ServerException::class];
        yield '503' => [503, ServerException::class];
    }

    /**
     * @param  class-string<ApiException>  $expected
     */
    #[DataProvider('statuses')]
    public function testEachStatusRaisesItsOwnType(int $status, string $expected): void
    {
        $this->client->pushJson($status, ['name' => 'SOMETHING', 'message' => 'No.', 'debug_id' => 'abc123']);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected ' . $expected);
        } catch (ApiException $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertSame($status, $e->statusCode);
        }
    }

    public function testAValidationFailureCarriesItsIssuesAndDebugId(): void
    {
        $this->client->pushJson(400, [
            'name' => 'INVALID_REQUEST',
            'message' => 'Request is not well-formed, syntactically incorrect, or violates schema.',
            'debug_id' => 'body-debug-id',
            'details' => [
                ['field' => 'start_date', 'location' => 'query', 'issue' => 'INVALID_PARAMETER_SYNTAX', 'description' => 'Bad date.'],
                ['field' => 'end_date', 'issue' => 'MISSING_REQUIRED_PARAMETER'],
            ],
        ], ['PayPal-Debug-Id' => 'header-debug-id']);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('INVALID_REQUEST', $e->errorName());
            $this->assertSame(['INVALID_PARAMETER_SYNTAX', 'MISSING_REQUIRED_PARAMETER'], $e->issues());
            $this->assertTrue($e->hasIssue('invalid_parameter_syntax'));
            $this->assertSame('header-debug-id', $e->debugId, 'the header is what PayPal support asks for');
            $this->assertStringContainsString('start_date: INVALID_PARAMETER_SYNTAX - Bad date.', $e->getMessage());
            $this->assertStringContainsString('[debug id header-debug-id]', $e->getMessage());
        }
    }

    public function testTheBodyDebugIdIsUsedWhenNoHeaderCame(): void
    {
        $this->pushFixture('not-found', 404);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('b1d1f06c7246c', $e->debugId);
            $this->assertTrue($e->isMissingResource());
        }
    }

    public function testA404WithoutAnErrorBodyIsNotAMissingResource(): void
    {
        $this->client->pushRaw(404, '<!DOCTYPE html><html><body>Not Found</body></html>', ['Content-Type' => 'text/html']);

        try {
            $this->paypal()->captures()->find('A');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertFalse($e->isMissingResource());
            $this->assertStringContainsString('unrouted path', $e->getMessage());
        }
    }

    public function testARetryAfterHeaderIsRead(): void
    {
        $this->client->pushJson(429, ['name' => 'RATE_LIMIT_REACHED'], ['Retry-After' => '30']);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function testATransportFailureIsARequestExceptionWithTheCauseChained(): void
    {
        $failure = new TransportFailure($this->paypal()->connection()->request('GET', 'v2/payments/captures/A'));
        $this->client->pushThrowable($failure);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame($failure, $e->getPrevious());
        }
    }

    /**
     * Laravel's StrayRequestException is a plain RuntimeException, and it names the URL only if it
     * reaches the test untouched.
     */
    public function testSomethingThatIsNotAPsr18ExceptionPassesThroughUntouched(): void
    {
        $stray = new \RuntimeException('Attempted request to [https://api-m.sandbox.paypal.com] without a matching fake.');
        $this->client->pushThrowable($stray);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected the stray exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($stray, $e);
        }
    }

    public function testASuccessThatIsNotJsonIsRaised(): void
    {
        $this->client->pushRaw(200, '<html>Service Unavailable</html>', ['Content-Type' => 'text/html', 'PayPal-Debug-Id' => 'f00']);

        try {
            $this->paypal()->captures()->get('A');
            $this->fail('expected MalformedResponseException');
        } catch (MalformedResponseException $e) {
            $this->assertSame('f00', $e->debugId);
        }
    }

    public function testAnEmptyGetIsMalformedRatherThanEmpty(): void
    {
        $this->client->pushRaw(200, '');

        $this->expectException(MalformedResponseException::class);

        $this->paypal()->transactions()->balances();
    }

    public function testAnotherHostIsRefusedBeforeTheTokenIsSent(): void
    {
        try {
            $this->paypal()->connection()->follow('https://attacker.example/v1/reporting/transactions');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('attacker.example', $e->getMessage());
        }

        $this->assertSame([], $this->client->requests);
    }

    public function testTheOtherEnvironmentIsAnotherHost(): void
    {
        $this->expectException(RuntimeException::class);

        $this->paypal()->connection()->get('https://api-m.paypal.com/v1/reporting/balances');
    }

    public function testAPlainHttpLinkToTheSameHostIsRefused(): void
    {
        $this->assertFalse((new Config(Environment::Live))->ownsUri('http://api-m.paypal.com/v1/reporting/balances'));
        $this->assertFalse((new Config(Environment::Live))->ownsUri('https://api-m.paypal.com:8443/v1/reporting/balances'));
        $this->assertTrue((new Config(Environment::Live))->ownsUri('https://API-M.paypal.com/v1/reporting/balances'));
    }

    public function testPostEncodedSendsTheBodyByteForByte(): void
    {
        $this->client->pushJson(200, ['ok' => true]);
        $json = '{"a":"x\/y","b":1.10}';

        $this->paypal()->connection()->postEncoded('v1/anything', $json);

        $this->assertSame($json, (string) $this->client->lastRequest()->getBody());
    }

    public function testPostEncodedRefusesSomethingThatIsNotJson(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paypal()->connection()->postEncoded('v1/anything', '{not json');
    }

    public function testAnOAuthErrorBodyParses(): void
    {
        $error = ErrorDetail::fromBody('{"error":"invalid_client","error_description":"Client Authentication failed"}');

        $this->assertNotNull($error);
        $this->assertSame('invalid_client', $error->oauthError);
        $this->assertSame('invalid_client Client Authentication failed', $error->describe());
    }

    public function testABodyThatNamesNothingIsNotAnErrorDetail(): void
    {
        $this->assertNull(ErrorDetail::fromBody('[]'));
        $this->assertNull(ErrorDetail::fromBody('{"message": "hello"}'));
        $this->assertNull(ErrorDetail::fromBody('not json'));
    }

    public function testConfigRefusesABadPageSizeAndARelativeBaseUri(): void
    {
        foreach ([fn () => new Config(Environment::Sandbox, 501), fn () => new Config(Environment::Sandbox, baseUri: 'api-m.paypal.com')] as $build) {
            try {
                $build();
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEnvironmentFromName(): void
    {
        $this->assertSame(Environment::Sandbox, Environment::fromName(' SANDBOX '));

        $this->expectException(InvalidArgumentException::class);

        Environment::fromName('production');
    }
}
