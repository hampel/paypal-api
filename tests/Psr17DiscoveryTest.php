<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\PayPal\Api\Authentication\AccessToken;
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Exception\RuntimeException;
use Hampel\PayPal\Api\Support\Psr17Discovery;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ported from xenforo-api, where the class was written.
 */
final class Psr17DiscoveryTest extends TestCase
{
    public function testAClientCanBeBuiltWithoutNamingAFactory(): void
    {
        $paypal = new Client(new Config(Environment::Sandbox), new AccessToken('token'), $this->client);

        $this->assertInstanceOf(RequestFactoryInterface::class, $paypal->connection()->requestFactory());
        $this->assertInstanceOf(StreamFactoryInterface::class, $paypal->connection()->streamFactory());

        // and the found factory actually works end to end
        $this->pushFixture('capture');
        $this->assertSame('74L756601X447022Y', $paypal->captures()->get('74L756601X447022Y')->id);
    }

    public function testOnThisMachineItFindsGuzzle(): void
    {
        [$request, $stream] = Psr17Discovery::find();

        $this->assertInstanceOf(HttpFactory::class, $request);
        $this->assertSame($request, $stream, 'Guzzle ships one class for both roles; it should be built once.');
    }

    public function testAFactoryThatIsGivenIsUsedRatherThanDiscovered(): void
    {
        $given = new HttpFactory();

        $paypal = new Client(new Config(Environment::Sandbox), new AccessToken('token'), $this->client, $given, $given);

        $this->assertSame($given, $paypal->connection()->requestFactory());
        $this->assertSame($given, $paypal->connection()->streamFactory());
    }

    /**
     * The short form names only the credential, the environment and the transport - so it is the
     * constructor that depends on discovery most.
     */
    public function testTheShortFormDiscoversItsFactoriesAndCarriesItsCredential(): void
    {
        $paypal = Client::withClientCredentials('client-id', 'secret', Environment::Sandbox, $this->client);

        $this->assertInstanceOf(RequestFactoryInterface::class, $paypal->connection()->requestFactory());
        $this->assertInstanceOf(ClientCredentials::class, $paypal->authentication());
        $this->assertTrue($paypal->config()->isSandbox());
    }

    /**
     * The not-found path cannot be reached with Guzzle installed, so it is reached through the list
     * instead.
     */
    public function testNothingFoundSaysWhatToPassAndWhatToInstall(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RequestFactoryInterface::class);
        $this->expectExceptionMessage('guzzlehttp/psr7');

        Psr17Discovery::from([['No\Such\Factory', 'No\Such\Factory']]);
    }

    /**
     * A class that exists but is not a factory is skipped, not returned - the instanceof is what
     * makes discovery by name safe.
     */
    public function testAClassThatExistsButIsNotAFactoryIsSkipped(): void
    {
        $this->expectException(RuntimeException::class);

        Psr17Discovery::from([[\stdClass::class, \stdClass::class]]);
    }

    public function testLaterCandidatesAreTriedWhenEarlierOnesAreAbsent(): void
    {
        [$request] = Psr17Discovery::from([
            ['No\Such\Factory', 'No\Such\Factory'],
            [\stdClass::class, \stdClass::class],
            [HttpFactory::class, HttpFactory::class],
        ]);

        $this->assertInstanceOf(HttpFactory::class, $request);
    }
}
