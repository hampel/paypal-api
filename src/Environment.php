<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api;

use Hampel\PayPal\Api\Exception\InvalidArgumentException;

/**
 * Which PayPal a client talks to.
 *
 * TWO HOSTS WITH SEPARATE EVERYTHING. A sandbox app has its own client id and secret, its own
 * accounts and its own transactions, and a sandbox credential is refused by the live host rather
 * than quietly reading nothing - so pointing a sandbox credential at live fails loudly, and the
 * mistake that does not is a live credential in a test.
 *
 * There is no default, on purpose: every constructor that needs one asks for it by name.
 */
enum Environment: string
{
    case Live = 'live';
    case Sandbox = 'sandbox';

    public function baseUri(): string
    {
        return match ($this) {
            self::Live => 'https://api-m.paypal.com',
            self::Sandbox => 'https://api-m.sandbox.paypal.com',
        };
    }

    /**
     * From a configuration value - `live` or `sandbox`, in any case.
     */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, trim($name)) === 0) {
                return $case;
            }
        }

        throw new InvalidArgumentException(sprintf('A PayPal environment is "live" or "sandbox"; "%s" is neither.', $name));
    }
}
