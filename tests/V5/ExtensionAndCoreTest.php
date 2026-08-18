<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackDefinition;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;

final class CoreService {}

final readonly class FactoryProduct
{
    public function __construct(public ResolutionContext $context) {}
}

final class CustomFallbackDto
{
    public function __construct(public string $message) {}
}

final readonly class MessageFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return $target->name === 'message';
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        return new ValueResult('from-fallback');
    }
}

final readonly class NullFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool { return false; }
    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult { return null; }
}

test('get remains shared while make remains fresh', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->get(CoreService::class))->toBe($container->get(CoreService::class))
        ->and($container->make(CoreService::class))->not->toBe($container->make(CoreService::class));
});

test('aliases preserve normal container resolution', function (): void {
    $container = (new ContainerBuilder())
        ->addAlias('core.service', CoreService::class)
        ->build();

    expect($container->get('core.service'))->toBeInstanceOf(CoreService::class);
});

test('user factories receive the provenance-aware ResolutionContext ABI', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'factory.product',
            static fn(ContainerValue $_container, ResolutionContext $context): FactoryProduct => new FactoryProduct($context),
        )
        ->build();
    $context = ResolutionContext::mapped(['mapped' => 1])->withExplicit(['explicit' => 2]);

    $product = $container->make('factory.product', $context);

    expect($product->context)->toBe($context);
});

test('custom fallbacks participate in declarative ordering', function (): void {
    $container = (new ContainerBuilder())
        ->addValueFallback(new ValueFallbackDefinition(
            'message',
            new MessageFallback(),
            before: ['property_initial'],
            after: ['trusted'],
        ))
        ->build();

    expect($container->make(CustomFallbackDto::class)->message)->toBe('from-fallback');
});

test('fallback ordering cycles fail while composing the container', function (): void {
    $builder = (new ContainerBuilder())
        ->addValueFallback(new ValueFallbackDefinition('cycle.a', new NullFallback(), after: ['cycle.b']))
        ->addValueFallback(new ValueFallbackDefinition('cycle.b', new NullFallback(), after: ['cycle.a']));

    expect(fn() => $builder->build())->toThrow(InvalidConfigurationException::class);
});
