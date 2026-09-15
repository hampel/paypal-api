<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Entity\Capture;
use Hampel\PayPal\Api\Entity\WebhookEvent;
use Hampel\PayPal\Api\Enum\CaptureStatus;
use Hampel\PayPal\Api\Enum\RefundStatus;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Exception\NotFoundException;

/**
 * Captures and refunds, against the samples in PayPal's own payments specification.
 */
final class PaymentsTest extends TestCase
{
    public function testACaptureIsReadWithItsBreakdown(): void
    {
        $this->pushFixture('capture');

        $capture = $this->paypal()->captures()->get('74L756601X447022Y');

        $this->assertSame('/v2/payments/captures/74L756601X447022Y', $this->sentPath());
        $this->assertSame(CaptureStatus::Completed, $capture->captureStatus());
        $this->assertSame('100.00 USD', (string) $capture->amount);
        $this->assertSame('3.98', $capture->fee()?->value);
        $this->assertSame('96.02', $capture->net()?->value);
        $this->assertFalse($capture->breakdown?->isConverted());
        $this->assertSame('25M43554V9523650M', $capture->orderId);
        $this->assertSame('0T620041CK889853A', $capture->authorizationId);
        $this->assertSame('YXZY75W2GKDQE', $capture->payeeMerchantId);
        $this->assertSame('ELIGIBLE', $capture->sellerProtection);
        $this->assertTrue($capture->finalCapture);
        $this->assertSame('2024-10-23T20:55:19+00:00', $capture->createdAt?->format(DATE_ATOM));

        $this->assertTrue($capture->breakdown->gross?->equals($capture->net()->plus($capture->fee())));
    }

    public function testAConvertedCaptureNamesTheRateAndTheReceivableAmount(): void
    {
        $capture = Capture::fromArray(WebhookEvent::fromArray(self::fixture('webhook-event'))->resource);

        $this->assertTrue($capture->breakdown?->isConverted());
        $this->assertSame('158.07 AUD', (string) $capture->breakdown->receivable);
        $this->assertSame('USD', $capture->breakdown->exchangeRate?->sourceCurrency);
        $this->assertSame('1.50000000', $capture->breakdown->exchangeRate->value);
        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $capture->customId);
    }

    public function testARefundNamesTheCaptureItRefundedThroughItsLink(): void
    {
        $this->pushFixture('refund');

        $refund = $this->paypal()->refunds()->get('1JU08902781691411');

        $this->assertSame('/v2/payments/refunds/1JU08902781691411', $this->sentPath());
        $this->assertSame(RefundStatus::Completed, $refund->refundStatus());
        $this->assertSame('2GG279541U471931P', $refund->captureId());
        $this->assertSame('0.33', $refund->breakdown?->paypalFee?->value);
        $this->assertSame('9.66', $refund->breakdown->net?->value);
        $this->assertSame('10.99', $refund->breakdown->totalRefunded?->value);
        $this->assertSame(['1.00 USD'], array_map('strval', $refund->breakdown->platformFees));
    }

    public function testFindAnswersNullForAMissingCaptureAndRefund(): void
    {
        $this->pushFixture('not-found', 404);
        $this->pushFixture('not-found', 404);

        $this->assertNull($this->paypal()->captures()->find('NOPE'));
        $this->assertNull($this->paypal()->refunds()->find('NOPE'));
    }

    public function testGetRaisesForAMissingCapture(): void
    {
        $this->pushFixture('not-found', 404);

        $this->expectException(NotFoundException::class);

        $this->paypal()->captures()->get('NOPE');
    }

    public function testAnIdCannotMoveTheRequestToAnotherPath(): void
    {
        $this->pushFixture('not-found', 404);

        $this->paypal()->captures()->find('../refunds/X?y');

        $this->assertSame('/v2/payments/captures/..%2Frefunds%2FX%3Fy', $this->sentPath());
        $this->assertSame('', $this->client->lastRequest()->getUri()->getQuery());
    }

    public function testAnEmptyIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paypal()->captures()->get(' ');
    }
}
