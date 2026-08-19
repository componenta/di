<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final class CoreService {}

final readonly class FactoryProduct
{
    /** @param array<string|int,mixed> $params */
    public function __construct(public array $params) {}
}

final class CustomResolverDto
{
    public function __construct(public string $message) {}
}

final readonly class MessageParameterResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'message';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $target->name === 'message'
            ? [$target->position, 'from-resolver']
            : null;
    }
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

test('user factories receive the restored array parameter ABI', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'factory.product',
            static fn(ContainerValue $_container, array $params): FactoryProduct => new FactoryProduct($params),
        )
        ->build();

    $product = $container->make('factory.product', ['explicit' => 2]);

    expect($product->params)->toBe(['explicit' => 2]);
});

test('custom parameter resolvers participate in the same ordered pipeline', function (): void {
    $container = (new ContainerBuilder())
        ->addParameterResolver(new MessageParameterResolver(), 350)
        ->build();

    expect($container->make(CustomResolverDto::class)->message)->toBe('from-resolver');
});

test('explicit caller parameters keep their v4 precedence over lower-priority custom resolvers', function (): void {
    $container = (new ContainerBuilder())
        ->addParameterResolver(new MessageParameterResolver(), 350)
        ->build();

    expect($container->make(CustomResolverDto::class, ['message' => 'explicit'])->message)
        ->toBe('explicit');
});
