<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\VirtualProxyFactoryInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BootstrapServicesMarker {}

interface MissingOptionalBootstrapService {}

#[SetUp('configure')]
final class BootstrapConfiguredObject
{
    public string $label;

    public function configure(string $value): void
    {
        $this->label = $value;
    }
}

final readonly class BootstrapReplacementResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, 'custom'];
    }
}

final readonly class BootstrapFactoryProduct
{
    /** @param array<string|int,mixed> $arguments */
    public function __construct(public ContainerValue $facade, public array $arguments) {}
}

test('container execution and factory interfaces all resolve to the same container instance', function (string $id): void {
    $container = (new ContainerBuilder())->build();

    expect($container->has($id))->toBeTrue()
        ->and($container->get($id))->toBe($container);
})->with([
    Container::class,
    ContainerInterface::class,
    FactoryInterface::class,
    CallableExecutorInterface::class,
    CallableInvokerInterface::class,
    CallableResolverInterface::class,
    ProxyFactoryInterface::class,
    LazyObjectFactoryInterface::class,
    VirtualProxyFactoryInterface::class,
]);

test('bootstrap exposes the original config environment and container facade together', function (): void {
    $environment = new Environment(['APP_MODE' => 'test']);
    $config = new Config(['app' => ['name' => 'audit']], $environment);
    $container = (new ContainerBuilder($config))->build();
    $facade = $container->get(ContainerValue::class);
    Assert::assertInstanceOf(ContainerValue::class, $facade);

    expect($container->get(Config::class))->toBe($config)
        ->and($container->get(Environment::class))->toBe($environment)
        ->and($facade->container)->toBe($container)
        ->and($facade->config)->toBe($config);
});

test('assembly seals its attribute registry after publishing the container', function (): void {
    [, $runtime] = (new ContainerBuilder())->assemble();
    $registry = $runtime->attributes;

    expect(fn() => $registry->register(new AttributeDefinition(BootstrapServicesMarker::class)))
        ->toThrow(InvalidConfigurationException::class, 'Attribute definition registry is sealed.');
});

test('explicit resolver replacement removes defaults including explicit argument resolution', function (): void {
    $container = (new ContainerBuilder())
        ->replaceParameterResolvers()
        ->addParameterResolver(new BootstrapReplacementResolver(), -10)
        ->build();

    expect($container->call(static fn(string $value): string => $value, ['value' => 'provided']))
        ->toBe('custom');
});

test('explicit factories retain their native arguments when the parameter resolver pipeline is replaced', function (): void {
    $config = new Config([], new Environment([]));
    $container = (new ContainerBuilder($config))
        ->replaceParameterResolvers()
        ->addParameterResolver(new BootstrapReplacementResolver(), -10)
        ->addFactory(BootstrapFactoryProduct::class, static fn(ContainerValue $facade, array $arguments): BootstrapFactoryProduct => new BootstrapFactoryProduct($facade, $arguments))
        ->build();
    $product = $container->make(BootstrapFactoryProduct::class, ['value' => 'provided']);
    Assert::assertInstanceOf(BootstrapFactoryProduct::class, $product);

    expect($product->facade)->toBe($container->get(ContainerValue::class))
        ->and($product->facade->container)->toBe($container)
        ->and($product->facade->config)->toBe($config)
        ->and($product->arguments)->toBe(['value' => 'provided'])
        ->and($container->call(static fn(string $value): string => $value, ['value' => 'provided']))
        ->toBe('custom');
});

test('replacing parameter resolvers keeps object attribute processing available', function (): void {
    $container = (new ContainerBuilder())
        ->replaceParameterResolvers()
        ->addParameterResolver(new BootstrapReplacementResolver(), -10)
        ->build();

    $instance = $container->make(BootstrapConfiguredObject::class);
    Assert::assertInstanceOf(BootstrapConfiguredObject::class, $instance);
    expect($instance->label)->toBe('custom');
});

test('nullable fallback resolves an unavailable interface without a declared default', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->call(static fn(?MissingOptionalBootstrapService $dependency): mixed => $dependency))
        ->toBeNull();
});
