<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Entity;

use Hampel\PayPal\Api\Exception\InvalidArgumentException;

/**
 * An amount in a currency, exactly as PayPal wrote it: `{"currency_code": "USD", "value": "10.99"}`.
 *
 * THE VALUE STAYS A STRING. PayPal sends amounts as decimal strings, so unlike a client whose API
 * sends JSON numbers there is exactness here to lose, and a float would lose it. The arithmetic
 * a reconciliation needs - a gross plus a fee, two amounts compared - is done on the digits, in
 * minorUnits() and plus(), and never through a float. toFloat() exists for handing an amount to
 * something that only takes one, and says so.
 *
 * The number of decimal places is the currency's: two for USD and AUD, none for JPY, three for a
 * few. Nothing here assumes two.
 */
final class Money implements \JsonSerializable, \Stringable
{
    private const PATTERN = '/^-?(\d+)(?:\.(\d+))?$/';

    private function __construct(
        public readonly string $currency,
        public readonly string $value,
    ) {
    }

    /**
     * An amount a caller wrote - refused unless the value is a plain decimal and the currency three
     * letters.
     */
    public static function of(string $value, string $currency): self
    {
        $value = trim($value);
        $currency = strtoupper(trim($currency));

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal amount PayPal can read, such as "10.99".', $value));
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a three-letter ISO 4217 currency code.', $currency));
        }

        return new self($currency, $value);
    }

    /**
     * An amount PayPal sent, or null when the field is absent or not the documented shape.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $currency = $value['currency_code'] ?? null;
        $amount = $value['value'] ?? null;

        if (!is_string($currency) || (!is_string($amount) && !is_int($amount) && !is_float($amount))) {
            return null;
        }

        $amount = trim((string) $amount);

        return preg_match(self::PATTERN, $amount) === 1 && $currency !== '' ? new self(strtoupper($currency), $amount) : null;
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-') && !$this->isZero();
    }

    public function isZero(): bool
    {
        return trim(str_replace(['-', '.'], '', $this->value), '0') === '';
    }

    /**
     * The same amount with its sign dropped - a fee PayPal wrote as `-3.30` read as `3.30`.
     */
    public function absolute(): self
    {
        return new self($this->currency, ltrim($this->value, '-'));
    }

    public function negate(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return new self($this->currency, $this->isNegative() ? ltrim($this->value, '-') : '-' . $this->value);
    }

    /**
     * The amount as an integer count of the smallest unit - cents, for a currency of two places.
     *
     * EXACT, OR REFUSED. `10.99` at two places is 1099; `10.999` at two places is not a number of
     * cents, and rounding it here would move money nobody decided to move, so it raises.
     */
    public function minorUnits(int $decimals = 2): int
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('A currency cannot have a negative number of decimal places.');
        }

        preg_match(self::PATTERN, $this->value, $match);

        $whole = $match[1] ?? '0';
        $fraction = rtrim($match[2] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException(sprintf(
                '%s %s has more than %d decimal places, so it is not a whole number of minor units.',
                $this->value,
                $this->currency,
                $decimals
            ));
        }

        $digits = ltrim($whole . str_pad($fraction, $decimals, '0'), '0');

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException(sprintf('%s %s is too large to count in minor units.', $this->value, $this->currency));
        }

        $units = (int) ($digits === '' ? '0' : $digits);

        return $this->isNegative() ? -$units : $units;
    }

    /**
     * The sum of two amounts in the same currency, exactly - a gross and a negative fee make a net.
     *
     * Written to as many decimal places as the more precise of the two, so `10.99` plus `-0.3` is
     * `10.69`, not `10.690000000000001`.
     */
    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot add %s to %s: the currencies differ, and converting between them is a rate '
                    . 'PayPal decides, not arithmetic.',
                $other,
                $this
            ));
        }

        $decimals = max($this->decimals(), $other->decimals());
        $units = $this->minorUnits($decimals) + $other->minorUnits($decimals);

        return new self($this->currency, self::format($units, $decimals));
    }

    public function minus(self $other): self
    {
        return $this->plus($other->negate());
    }

    /**
     * Whether two amounts are the same money - `10.5` equals `10.50`, and USD never equals AUD.
     */
    public function equals(self $other): bool
    {
        if ($other->currency !== $this->currency) {
            return false;
        }

        $decimals = max($this->decimals(), $other->decimals());

        return $this->minorUnits($decimals) === $other->minorUnits($decimals);
    }

    /**
     * THROUGH A FLOAT, SO NOT EXACT. For something that takes nothing else - a client whose own API
     * sends and accepts numbers. Compare and add with minorUnits(), plus() and equals().
     */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    public function __toString(): string
    {
        return $this->value . ' ' . $this->currency;
    }

    /**
     * @return array{currency_code: string, value: string}
     */
    public function jsonSerialize(): array
    {
        return ['currency_code' => $this->currency, 'value' => $this->value];
    }

    private function decimals(): int
    {
        $dot = strpos($this->value, '.');

        return $dot === false ? 0 : strlen($this->value) - $dot - 1;
    }

    private static function format(int $units, int $decimals): string
    {
        $digits = str_pad((string) abs($units), $decimals + 1, '0', STR_PAD_LEFT);
        $value = $decimals === 0 ? $digits : substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);

        return $units < 0 ? '-' . $value : $value;
    }
}
