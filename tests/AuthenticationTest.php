<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Authentication\AccessToken;
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Authentication\InMemoryTokenStore;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\NotAuthenticatedException;
use Hampel\PayPal\Api\Exception\RuntimeException;

final class AuthenticationTest extends TestCase
{
    private const CLIENT_ID = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    private const SECRET = 'EFGH-secret-value-0123456789';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new \DateTimeImmutable('2026-09-15 10:00:00', new \DateTimeZone('UTC'));
    }

    private function credentials(?InMemoryTokenStore $store = null): ClientCredentials
    {
        return new ClientCredentials(self::CLIENT_ID, self::SECRET, $store, fn (): \DateTimeImmutable => $this->now);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenBody(string $token = 'token-1', int $expiresIn = 32400): array
    {
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'scope' => 'openid', 'app_id' => 'APP-1'];
    }

    public function testTheTokenRequestIsBasicAuthenticatedFormEncodedAndCarriesNoBearer(): void
    {
        $this->client->pushJson(200, $this->tokenBody());
        $this->pushFixture('capture');

        $this->paypal(authentication: $this->credentials())->captures()->get('74L756601X447022Y');

        $token = $this->client->requests[0];

        $this->assertSame('POST', $token->getMethod());
        $this->assertSame('https://api-m.sandbox.paypal.com/v1/oauth2/token', (string) $token->getUri());
        $this->assertSame('Basic ' . base64_encode(self::CLIENT_ID . ':' . self::SECRET), $token->getHeaderLine('Authorization'));
        $this->assertSame('application/x-www-form-urlencoded', $token->getHeaderLine('Content-Type'));
        $this->assertSame('grant_type=client_credentials', (string) $token->getBody());

        $this->assertSame('Bearer token-1', $this->client->requests[1]->getHeaderLine('Authorization'));
    }

    public function testATokenIsObtainedOnceAndReused(): void
    {
        $this->client->pushJson(200, $this->tokenBody());
        $this->pushFixture('capture');
        $this->pushFixture('capture');

        $paypal = $this->paypal(authentication: $this->credentials());
        $paypal->captures()->get('A');
        $paypal->captures()->get('B');

        $this->assertCount(3, $this->client->requests);
        $this->assertSame('Bearer token-1', $this->client->requests[2]->getHeaderLine('Authorization'));
    }

    public function testAStoredGrantIsUsedWithoutAskingForAnother(): void
    {
        $store = new InMemoryTokenStore();
        $store->put(
            ClientCredentials::keyFor(self::CLIENT_ID, 'api-m.sandbox.paypal.com'),
            AccessGrant::fromResponse($this->tokenBody('cached'), $this->now)
        );
        $this->pushFixture('capture');

        $this->paypal(authentication: $this->credentials($store))->captures()->get('A');

        $this->assertCount(1, $this->client->requests);
        $this->assertSame('Bearer cached', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * The same app id on both hosts would be a coincidence, but one store serving both must never
     * hand a sandbox token to the live API.
     */
    public function testTheStoreKeySeparatesSandboxFromLive(): void
    {
        $this->assertNotSame(
            ClientCredentials::keyFor(self::CLIENT_ID, 'api-m.sandbox.paypal.com'),
            ClientCredentials::keyFor(self::CLIENT_ID, 'api-m.paypal.com')
        );
        $this->assertStringNotContainsString(self::CLIENT_ID, ClientCredentials::keyFor(self::CLIENT_ID, 'api-m.paypal.com'));
    }

    public function testAnExpiredGrantIsReplaced(): void
    {
        $store = new InMemoryTokenStore();
        $store->put(
            ClientCredentials::keyFor(self::CLIENT_ID, 'api-m.sandbox.paypal.com'),
            AccessGrant::fromResponse($this->tokenBody('old', 3600), $this->now->modify('-2 hours'))
        );
        $this->client->pushJson(200, $this->tokenBody('new'));
        $this->pushFixture('capture');

        $this->paypal(authentication: $this->credentials($store))->captures()->get('A');

        $this->assertSame('/v1/oauth2/token', $this->client->requests[0]->getUri()->getPath());
        $this->assertSame('Bearer new', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testAGrantAboutToExpireIsTreatedAsExpired(): void
    {
        $grant = AccessGrant::fromResponse($this->tokenBody('t', 3600), $this->now);

        $this->assertFalse($grant->isExpired($this->now->modify('+3500 seconds')));
        $this->assertTrue($grant->isExpired($this->now->modify('+3541 seconds')));
    }

    /**
     * A token can be revoked before its recorded expiry. One 401 costs a new token and one retry.
     */
    public function testARefusedTokenIsReplacedAndTheRequestRetriedOnce(): void
    {
        $this->client->pushJson(200, $this->tokenBody('revoked'));
        $this->client->pushJson(401, ['name' => 'AUTHENTICATION_FAILURE', 'message' => 'Authentication failed']);
        $this->client->pushJson(200, $this->tokenBody('fresh'));
        $this->pushFixture('capture');

        $capture = $this->paypal(authentication: $this->credentials())->captures()->get('74L756601X447022Y');

        $this->assertSame('74L756601X447022Y', $capture->id);
        $this->assertCount(4, $this->client->requests);
        $this->assertSame('Bearer fresh', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testASecondRefusalIsRaisedRatherThanRetriedAgain(): void
    {
        $this->client->pushJson(200, $this->tokenBody('one'));
        $this->client->pushJson(401, ['name' => 'AUTHENTICATION_FAILURE']);
        $this->client->pushJson(200, $this->tokenBody('two'));
        $this->client->pushJson(401, ['name' => 'AUTHENTICATION_FAILURE']);

        try {
            $this->paypal(authentication: $this->credentials())->captures()->get('A');
            $this->fail('expected NotAuthenticatedException');
        } catch (NotAuthenticatedException $e) {
            $this->assertSame('AUTHENTICATION_FAILURE', $e->errorName());
        }

        $this->assertCount(4, $this->client->requests);
    }

    public function testARefusedClientSecretIsRaisedWithTheOAuthErrorAndNotRetried(): void
    {
        $this->client->pushJson(401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']);

        try {
            $this->paypal(authentication: $this->credentials())->captures()->get('A');
            $this->fail('expected NotAuthenticatedException');
        } catch (NotAuthenticatedException $e) {
            $this->assertSame('invalid_client', $e->oauthError());
            $this->assertStringContainsString('Client Authentication failed', $e->getMessage());
        }

        $this->assertCount(1, $this->client->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tokenlessAnswers(): iterable
    {
        yield 'an empty body' => [''];
        yield 'an empty object' => ['{}'];
        yield 'another service' => ['{"status": "ok"}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenlessAnswers')]
    public function testASuccessWithNoTokenIsAMalformedResponseNotAnInvalidArgument(string $body): void
    {
        $this->client->pushRaw(200, $body, ['Content-Type' => 'application/json', 'PayPal-Debug-Id' => 'dbg-1']);

        try {
            $this->paypal(authentication: $this->credentials())->captures()->get('A');
            $this->fail('expected MalformedResponseException');
        } catch (MalformedResponseException $e) {
            $this->assertStringContainsString('without an access_token', $e->getMessage());
            $this->assertSame(200, $e->statusCode);
            $this->assertSame('dbg-1', $e->debugId);
        }

        $this->assertCount(1, $this->client->requests);
    }

    public function testAStaticTokenIsNotRetried(): void
    {
        $this->client->pushJson(401, ['name' => 'AUTHENTICATION_FAILURE']);

        $this->expectException(NotAuthenticatedException::class);

        try {
            $this->paypal(authentication: new AccessToken('expired'))->captures()->get('A');
        } finally {
            $this->assertCount(1, $this->client->requests);
        }
    }

    public function testCredentialsDoNotPrint(): void
    {
        $credentials = $this->credentials();
        $token = new AccessToken('A21AAFEpH4PsADK7qSS7pSRsgzfENtu');
        $grant = AccessGrant::fromResponse($this->tokenBody('A21AAFEpH4PsADK7qSS7pSRsgzfENtu'), $this->now);

        foreach ([$credentials, $token, $grant] as $credential) {
            $printed = print_r($credential, true) . json_encode($credential) . ($credential instanceof \Stringable ? (string) $credential : '');

            $this->assertStringNotContainsString(self::SECRET, $printed);
            $this->assertStringNotContainsString('A21AAFEpH4Ps', $printed);
            $this->assertStringNotContainsString(self::CLIENT_ID, $printed);
        }

        $this->assertSame('PayPal client credentials for app AbCd...6789', $credentials->describe());
    }

    public function testTheGrantRoundTripsThroughItsPortableForm(): void
    {
        $grant = AccessGrant::fromResponse(self::fixture('token'), $this->now);
        $copy = AccessGrant::fromArray($grant->toArray());

        $this->assertSame($grant->accessToken, $copy->accessToken);
        $this->assertSame($grant->scope, $copy->scope);
        $this->assertSame('APP-80W284485P519543T', $copy->appId);
        $this->assertEquals($this->now->modify('+31668 seconds'), $copy->expiresAt);
    }

    public function testTheScopeSaysWhetherTransactionSearchIsEnabled(): void
    {
        $grant = AccessGrant::fromResponse(self::fixture('token'), $this->now);

        $this->assertContains('openid', $grant->scopes());
        $this->assertTrue($grant->canSearchTransactions());
        $this->assertFalse(AccessGrant::fromResponse($this->tokenBody(), $this->now)->canSearchTransactions());
    }

    public function testTheScopeSaysWhetherSubscriptionsAreEnabled(): void
    {
        $this->assertTrue(AccessGrant::fromResponse(self::fixture('token'), $this->now)->canReadSubscriptions());
        $this->assertFalse(AccessGrant::fromResponse($this->tokenBody(), $this->now)->canReadSubscriptions());
    }

    public function testVerifyAnswersTheGrant(): void
    {
        $this->pushFixture('token');

        $grant = $this->paypal(authentication: $this->credentials())->verify();

        $this->assertTrue($grant->canSearchTransactions());
        $this->assertCount(1, $this->client->requests);
    }

    public function testVerifyNeedsCredentialsThatObtainAGrant(): void
    {
        $this->expectException(RuntimeException::class);

        $this->paypal()->verify();
    }

    public function testTheShortFormTakesTheEnvironmentByName(): void
    {
        $live = Client::withClientCredentials(self::CLIENT_ID, self::SECRET, Environment::Live, $this->client);

        $this->assertSame('https://api-m.paypal.com', $live->config()->baseUri);
        $this->assertInstanceOf(ClientCredentials::class, $live->authentication());
    }

    public function testEmptyCredentialsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientCredentials('client', ' ');
    }

    public function testADerivedClientCarriesTheNewCredential(): void
    {
        $this->pushFixture('capture');

        $this->paypal()->withCredential(new AccessToken('other'))->withConfig(new Config(Environment::Sandbox))->captures()->get('A');

        $this->assertSame('Bearer other', $this->client->lastRequest()->getHeaderLine('Authorization'));
    }
}
