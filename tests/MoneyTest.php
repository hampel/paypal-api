<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Entity\Money;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class MoneyTest extends BaseTestCase
{
    public function testTheValueIsKeptExactlyAsWritten(): void
    {
        $money = Money::fromArray(['currency_code' => 'usd', 'value' => '10.10']);

        $this->assertNotNull($money);
        $this->assertSame('10.10', $money->value);
        $this->assertSame('USD', $money->currency);
        $this->assertSame(['currency_code' => 'USD', 'value' => '10.10'], $money->jsonSerialize());
    }

    public function testAnythingButTheDocumentedShapeIsNull(): void
    {
        $this->assertNull(Money::fromArray(null));
        $this->assertNull(Money::fromArray([]));
        $this->assertNull(Money::fromArray(['currency_code' => 'USD']));
        $this->assertNull(Money::fromArray(['currency_code' => 'USD', 'value' => 'ten']));
        $this->assertNull(Money::fromArray(['currency_code' => '', 'value' => '1.00']));
    }

    public function testMinorUnitsAreExact(): void
    {
        $this->assertSame(1099, Money::of('10.99', 'USD')->minorUnits());
        $this->assertSame(-462, Money::of('-4.62', 'USD')->minorUnits());
        $this->assertSame(1000, Money::of('10', 'USD')->minorUnits());
        $this->assertSame(1050, Money::of('10.500', 'USD')->minorUnits(), 'a trailing zero is not a third place');
        $this->assertSame(1500, Money::of('1500', 'JPY')->minorUnits(0));
        $this->assertSame(1234, Money::of('1.234', 'KWD')->minorUnits(3));
    }

    public function testAnAmountThatIsNotAWholeNumberOfMinorUnitsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('10.999', 'USD')->minorUnits();
    }

    public function testAGrossAndANegativeFeeMakeANetExactly(): void
    {
        $net = Money::of('110.00', 'USD')->plus(Money::of('-4.62', 'USD'));

        $this->assertSame('105.38', $net->value);
        $this->assertSame('10.69', Money::of('10.99', 'USD')->plus(Money::of('-0.3', 'USD'))->value);
        $this->assertSame('-0.01', Money::of('0.10', 'USD')->minus(Money::of('0.11', 'USD'))->value);
        $this->assertSame('3000', Money::of('1000', 'JPY')->plus(Money::of('2000', 'JPY'))->value);
    }

    public function testDifferentCurrenciesDoNotAdd(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('1.00', 'USD')->plus(Money::of('1.00', 'AUD'));
    }

    public function testEquality(): void
    {
        $this->assertTrue(Money::of('10.5', 'USD')->equals(Money::of('10.50', 'USD')));
        $this->assertFalse(Money::of('10.50', 'USD')->equals(Money::of('10.50', 'AUD')));
        $this->assertFalse(Money::of('10.50', 'USD')->equals(Money::of('10.51', 'USD')));
    }

    public function testSigns(): void
    {
        $fee = Money::of('-3.30', 'AUD');

        $this->assertTrue($fee->isNegative());
        $this->assertSame('3.30', $fee->absolute()->value);
        $this->assertSame('3.30', $fee->negate()->value);
        $this->assertSame('-3.30', $fee->absolute()->negate()->value);
        $this->assertFalse(Money::of('-0.00', 'AUD')->isNegative());
        $this->assertTrue(Money::of('-0.00', 'AUD')->isZero());
        $this->assertSame('3.3', (string) Money::of('3.3', 'AUD')->toFloat());
    }

    public function testAWrittenAmountIsValidated(): void
    {
        foreach ([['1,000.00', 'USD'], ['10.00', 'US'], ['1e3', 'USD']] as [$value, $currency]) {
            try {
                Money::of($value, $currency);
                $this->fail(sprintf('expected %s %s to be refused', $value, $currency));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
