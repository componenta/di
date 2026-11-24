<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Extension\ExtensionAndCore;

use Componenta\Config\ContainerValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;

use function Componenta\DI\Tests\Support\fixtureInstance;

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

    $shared = $container->get(CoreService::class);
    $first = $container->make(CoreService::class);
    $second = $container->make(CoreService::class);

    expect($shared)->toBeInstanceOf(CoreService::class)
        ->and($first)->toBeInstanceOf(CoreService::class)
        ->and($second)->toBeInstanceOf(CoreService::class)
        ->and($first)->not->toBe($shared)
        ->and($second)->not->toBe($shared)
        ->and($second)->not->toBe($first)
        ->and($container->get(CoreService::class))->toBe($shared);
});

test('aliases preserve normal container resolution', function (): void {
    $container = (new ContainerBuilder())
        ->addAlias('core.service', CoreService::class)
        ->build();

    $canonical = $container->get(CoreService::class);
    expect($canonical)->toBeInstanceOf(CoreService::class)
        ->and($container->get('core.service'))->toBe($canonical);

    $replacement = new CoreService();
    $container->set(CoreService::class, $replacement);
    expect($container->get('core.service'))->toBe($replacement);
});

test('user factories receive the restored array parameter ABI', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'factory.product',
            static fn(ContainerValue $_container, array $params): FactoryProduct => new FactoryProduct($params),
        )
        ->build();

    $product = $container->make('factory.product', ['explicit' => 2]);
    if (!$product instanceof FactoryProduct) {
        throw new \LogicException('The configured factory returned an unexpected type.');
    }

    expect($product->params)->toBe(['explicit' => 2]);
});

test('custom parameter resolvers participate in the same ordered pipeline', function (): void {
    $container = (new ContainerBuilder())
        ->addParameterResolver(new MessageParameterResolver(), 350)
        ->build();

    expect(fixtureInstance($container->make(CustomResolverDto::class), CustomResolverDto::class)->message)->toBe('from-resolver');
});

test('explicit caller parameters take precedence over lower-priority custom resolvers', function (): void {
    $container = (new ContainerBuilder())
        ->addParameterResolver(new MessageParameterResolver(), 350)
        ->build();

    expect(fixtureInstance($container->make(CustomResolverDto::class, ['message' => 'explicit']), CustomResolverDto::class)->message)
        ->toBe('explicit');
});
