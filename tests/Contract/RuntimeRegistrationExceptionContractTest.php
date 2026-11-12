<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RuntimeRegistrationException;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;

final readonly class RegistrationProduct
{
    public function __construct(public string $value = 'replacement') {}

    public static function decorate(self $entry): self
    {
        return new self('decorated:' . $entry->value);
    }
}

final class RegistrationLoadState
{
    public bool $fail = true;
}

test('runtime registration wraps foreign autoload failures and permits a successful retry', function (bool $delegator): void {
    $container = (new ContainerBuilder())
        ->addFactory('product', static fn(): RegistrationProduct => new RegistrationProduct('original'))
        ->build();
    $original = $container->get('product');
    /** @var class-string<RegistrationProduct> $class */
    $class = __NAMESPACE__ . '\\DeferredRegistration_' . bin2hex(random_bytes(5));
    $failure = new \RuntimeException('The registration class could not be loaded.');
    $state = new RegistrationLoadState();
    $loader = static function (string $requested) use ($class, $failure, $state): void {
        if ($requested !== $class) {
            return;
        }
        if ($state->fail) {
            throw $failure;
        }
        class_alias(RegistrationProduct::class, $class);
    };
    $register = static function () use ($container, $class, $delegator): void {
        if ($delegator) {
            $container->delegator('product', $class . '::decorate');
            return;
        }
        $container->set('product', ClassDefinition::create($class));
    };
    spl_autoload_register($loader);

    try {
        try {
            $register();
            Assert::fail('The failed autoloader must abort runtime registration.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getPrevious())->toBe($failure);
        }

        expect($container->get('product'))->toBe($original)
            ->and($container->make('product'))->toEqual(new RegistrationProduct('original'));

        $state->fail = false;
        $register();

        expect($container->get('product'))->toEqual(new RegistrationProduct(
            $delegator ? 'decorated:original' : 'replacement',
        ));
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['definition' => false, 'delegator' => true]);

test('runtime registration preserves Componenta autoload exceptions unchanged', function (bool $delegator): void {
    $container = (new ContainerBuilder())->addService('product', 'original')->build();
    /** @var class-string<RegistrationProduct> $class */
    $class = __NAMESPACE__ . '\\OwnedFailureRegistration_' . bin2hex(random_bytes(5));
    $failure = new ResolutionException('A dependency could not be loaded.');
    $loader = static function (string $requested) use ($class, $failure): void {
        if ($requested === $class) {
            throw $failure;
        }
    };
    spl_autoload_register($loader);

    try {
        try {
            if ($delegator) {
                $container->delegator('product', $class . '::decorate');
            } else {
                $container->set('product', ClassDefinition::create($class));
            }
            Assert::fail('The failed autoloader must abort runtime registration.');
        } catch (ExceptionInterface $exception) {
            expect($exception)->toBe($failure);
        }

        expect($container->get('product'))->toBe('original');
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['definition' => false, 'delegator' => true]);
