<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DeferredFactoryRecovery;

use Componenta\Config\ContainerValue;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class IncompatibleFactory
{
    public int $calls = 0;

    /** @param array<array-key,mixed> $container */
    public function __invoke(array $container): object
    {
        ++$this->calls;
        return new \stdClass();
    }
}

final readonly class Product
{
    /** @param array<string|int,mixed> $parameters */
    public function __construct(public ContainerValue $container, public array $parameters) {}
}

final class CompatibleFactory
{
    public int $calls = 0;

    /** @param array<string|int,mixed> $parameters */
    public function __invoke(ContainerValue $container, array $parameters): Product
    {
        ++$this->calls;
        return new Product($container, $parameters);
    }
}

test('deferred factories reject invalid owners and recover after replacement', function (bool $method, bool $incompatible): void {
    $invalid = $incompatible ? new IncompatibleFactory() : null;
    $container = (new ContainerBuilder())
        ->addService('factory.owner', $invalid)
        ->addFactory('product', $method ? ['factory.owner', '__invoke'] : 'factory.owner')
        ->build();
    $message = $incompatible ? 'parameter #1' : 'Factory service for "product" resolved to unsupported';

    expect(fn() => $container->get('product'))->toThrow(InvalidConfigurationException::class, $message);
    expect(fn() => $container->make('product'))->toThrow(InvalidConfigurationException::class, $message);
    if ($invalid instanceof IncompatibleFactory) {
        expect($invalid->calls)->toBe(0);
    }

    $factory = new CompatibleFactory();
    $container->set('factory.owner', $factory);
    $shared = $container->get('product');
    $fresh = $container->make('product', ['value' => 'fresh', 7 => 'positional']);
    expect($shared)->toBeInstanceOf(Product::class)
        ->and($fresh)->toBeInstanceOf(Product::class);
    if (!$shared instanceof Product || !$fresh instanceof Product) {
        throw new \LogicException('Expected products from the replacement factory.');
    }
    expect($shared->container->container)->toBe($container)
        ->and($shared->parameters)->toBe([])
        ->and($fresh->container->container)->toBe($container)
        ->and($fresh->parameters)->toBe(['value' => 'fresh', 7 => 'positional'])
        ->and($fresh)->not->toBe($shared)
        ->and($container->get('product'))->toBe($shared)
        ->and($factory->calls)->toBe(2);
})->with([
    'noncallable service' => [false, false],
    'noncallable service method' => [true, false],
    'incompatible service signature' => [false, true],
    'incompatible service method signature' => [true, true],
]);
