<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Support;

use Hampel\PayPal\Api\Entity\Money;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;

/**
 * Reading a value out of an API result without trusting it.
 *
 * Every one of these returns null - or an empty collection - rather than throwing when the value
 * is absent or the wrong shape. PayPal's specifications mark almost nothing in a response as
 * required, and a transaction search row in particular carries whichever of its forty fields
 * applied to that transaction and omits the rest. A client that treated an absent field as an
 * error would break on the ordinary case.
 */
final class Cast
{
    public static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A string that means something, with the empty string read as absent.
     */
    public static function text(mixed $value): ?string
    {
        $string = self::string($value);

        return $string === null || trim($string) === '' ? null : $string;
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-')) ? (int) $value : null;
    }

    /**
     * A boolean, including the `Y` and `N` that transaction search writes its flags as.
     */
    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (is_string($value) ? strtolower($value) : $value) {
            1, '1', 'true', 'y', 'yes' => true,
            0, '0', 'false', 'n', 'no' => false,
            default => null,
        };
    }

    /**
     * The string-keyed part of a value that should have been an object.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /**
     * A list of strings, with anything that is not one dropped.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * A list of nested objects mapped into entities. Anything that is not an object is skipped
     * rather than mapped, so one malformed element does not take the others with it.
     *
     * @template T
     * @param  callable(array<string, mixed>): T  $map
     * @return list<T>
     */
    public static function objects(mixed $value, callable $map): array
    {
        if (!is_array($value)) {
            return [];
        }

        $mapped = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $mapped[] = $map(self::object($item));
            }
        }

        return $mapped;
    }

    /**
     * A nested object mapped into an entity, or null when the field was null, absent or empty.
     *
     * @template T
     * @param  callable(array<string, mixed>): T  $map
     * @return T|null
     */
    public static function nested(mixed $value, callable $map): mixed
    {
        return is_array($value) && $value !== [] ? $map(self::object($value)) : null;
    }

    /**
     * A `{currency_code, value}` object, or null.
     */
    public static function money(mixed $value): ?Money
    {
        return Money::fromArray($value);
    }

    /**
     * An instant, WITH THE OFFSET IT WAS WRITTEN IN.
     *
     * NOT CONVERTED TO UTC, deliberately. Transaction search documents its dates as "expressed in
     * the time zone of the account", so `2026-09-01T23:30:00-0700` is the 1st in the account's own
     * books and the 2nd in UTC - and which of those a reconciliation means is the caller's
     * decision, which a conversion here would have made for them. Call setTimezone() to convert;
     * `format('Y-m-d')` on the value as returned is the date as PayPal wrote it.
     *
     * Both offset forms parse: RFC 3339's `+10:00`, which the specifications describe, and the
     * colon-less `+0000` of PayPal's older transaction search samples. Which one the API sends
     * today is unmeasured - `vendor/bin/rig transactions` prints it raw.
     */
    public static function datetime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * An instant on its way OUT, as RFC 3339 in UTC with whole seconds - `2026-09-01T00:00:00Z`.
     *
     * Every PayPal date parameter demands seconds and an explicit offset. UTC with `Z` is the
     * form least likely to be misread: a `+` in an offset is a space once a careless layer
     * form-decodes the query string.
     */
    public static function instantString(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A backed enum case matched case-insensitively, or null.
     *
     * @template T of \BackedEnum
     * @param  class-string<T>  $enum
     * @return T|null
     */
    public static function enum(string $enum, mixed $value): ?\BackedEnum
    {
        if (!is_scalar($value)) {
            return null;
        }

        $exact = $enum::tryFrom(is_int($value) ? $value : (string) $value);

        if ($exact !== null) {
            return $exact;
        }

        foreach ($enum::cases() as $case) {
            if (strcasecmp((string) $case->value, (string) $value) === 0) {
                return $case;
            }
        }

        return null;
    }

    /**
     * A PayPal id on its way into a path - refused when empty, and percent-encoded otherwise, so
     * an id carrying a `/` or a `?` cannot move the request to another path.
     */
    public static function pathSegment(string $id, string $what): string
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(sprintf('A PayPal %s id is required.', $what));
        }

        return rawurlencode($id);
    }
}
