<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Tests;

use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The README is documentation for people who cannot read the source, which makes a wrong example
 * worse than a missing one: it is copied, it fails, and the reader concludes the package is broken.
 * Renaming a method is exactly the change that passes every other test here - the suite calls the
 * new name and only the README still calls the old one.
 *
 * So this checks that everything the README names exists. It cannot check the examples are correct;
 * it can check they are not stale, which is the failure that actually happens.
 */
final class ReadmeTest extends BaseTestCase
{
    private function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/README.md');
    }

    public function testEveryDocumentedEndpointCallExists(): void
    {
        preg_match_all('/\$paypal->(\w+)\(\)->(\w+)\(/', $this->readme(), $matches, PREG_SET_ORDER);

        $this->assertGreaterThan(10, count($matches), 'the README should show rather more endpoint calls than this');

        foreach ($matches as [, $accessor, $method]) {
            $this->assertTrue(method_exists(Client::class, $accessor), sprintf('the README calls $paypal->%s(), which Client does not have', $accessor));

            $returns = (string) (new \ReflectionMethod(Client::class, $accessor))->getReturnType();

            $this->assertTrue(
                method_exists($returns, $method),
                sprintf('the README calls $paypal->%s()->%s(), which %s does not have', $accessor, $method, $returns)
            );
        }
    }

    public function testEveryDocumentedClientMethodExists(): void
    {
        preg_match_all('/(?:\$paypal->|Client::)(\w+)\(/', $this->readme(), $matches);

        foreach (array_unique($matches[1]) as $method) {
            $this->assertTrue(method_exists(Client::class, $method), sprintf('the README calls %s() on Client, which does not exist', $method));
        }
    }

    public function testEveryImportedClassAndStaticCallExists(): void
    {
        $readme = $this->readme();

        preg_match_all('/^use (Hampel\\\\PayPal\\\\Api\\\\[\w\\\\]+);$/m', $readme, $uses);

        $this->assertNotEmpty($uses[1]);

        $imported = [];

        foreach ($uses[1] as $class) {
            $this->assertTrue(class_exists($class) || enum_exists($class), sprintf('the README imports %s, which does not exist', $class));
            $imported[substr((string) strrchr($class, '\\'), 1)] = $class;
        }

        preg_match_all('/\b([A-Z]\w+)::(\w+)\b/', $readme, $statics, PREG_SET_ORDER);

        foreach ($statics as [, $short, $member]) {
            if (!isset($imported[$short])) {
                continue;
            }

            $class = $imported[$short];

            $this->assertTrue(
                method_exists($class, $member) || defined($class . '::' . $member),
                sprintf('the README names %s::%s, which does not exist', $short, $member)
            );
        }
    }

    public function testEveryDocumentedExceptionExistsAndIsOurs(): void
    {
        preg_match_all('/`(\w+Exception)`/', $this->readme(), $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $name) {
            $class = 'Hampel\\PayPal\\Api\\Exception\\' . $name;

            $this->assertTrue(class_exists($class), sprintf('the README names %s, which does not exist', $name));
            $this->assertTrue(is_subclass_of($class, ExceptionInterface::class));
        }
    }

    /**
     * `Class::method()` written in prose rather than code - `TransactionFilter::amountBetween()` -
     * for every class the README mentions that way, wherever in the package it lives.
     */
    public function testEveryMethodNamedInProseExists(): void
    {
        preg_match_all('/`(\w+)::(\w+)\(\)`/', $this->readme(), $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);

        foreach ($matches as [, $short, $method]) {
            $class = self::find($short);

            $this->assertNotNull($class, sprintf('the README names %s, which is no class in src/', $short));
            $this->assertTrue(method_exists($class, $method), sprintf('the README names %s::%s(), which does not exist', $short, $method));
        }
    }

    /**
     * `Class::$property` and the entity methods the README's tables name.
     */
    public function testEveryPropertyAndRowMethodNamedInProseExists(): void
    {
        $readme = $this->readme();

        preg_match_all('/`(\w+)::\$(\w+)`/', $readme, $properties, PREG_SET_ORDER);

        foreach ($properties as [, $short, $property]) {
            $class = self::find($short);

            $this->assertNotNull($class, sprintf('the README names %s, which is no class in src/', $short));
            $this->assertTrue(property_exists($class, $property), sprintf('the README names %s::$%s, which does not exist', $short, $property));
        }

        preg_match_all('/^\| `(\w+)\(\)` \|/m', $readme, $rowMethods);

        $this->assertNotEmpty($rowMethods[1]);

        foreach ($rowMethods[1] as $method) {
            $this->assertTrue(method_exists('Hampel\\PayPal\\Api\\Entity\\Transaction', $method), sprintf('the README names Transaction::%s(), which does not exist', $method));
        }
    }

    /**
     * @return class-string|null
     */
    private static function find(string $short): ?string
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getFilename() === $short . '.php') {
                $relative = substr($file->getPathname(), strlen(dirname(__DIR__) . '/src/'), -4);
                $class = 'Hampel\\PayPal\\Api\\' . str_replace('/', '\\', $relative);

                if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
                    return $class;
                }
            }
        }

        return null;
    }
}
