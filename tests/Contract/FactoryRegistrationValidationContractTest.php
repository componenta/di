<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryRegistrationValidation;

use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class FactoryCalls
{
    public int $count = 0;
}

test('invalid wrapped factory registration preserves the previous shared value and factory', function (\Closure $factory, string $message): void {
    $calls = new FactoryCalls();
    $original = static function () use ($calls): object {
        ++$calls->count;
        return new \stdClass();
    };
    $container = (new ContainerBuilder())->addFactory('product', $original)->build();
    $shared = $container->get('product');

    expect(fn() => $container->set('product', new FactoryDefinition($factory)))
        ->toThrow(InvalidConfigurationException::class, $message);

    expect($container->get('product'))->toBe($shared)
        ->and($calls->count)->toBe(1)
        ->and($container->make('product'))->not->toBe($shared)
        ->and($calls->count)->toBe(2);
})->with([
    'wrong container type' => [static fn(array $container): array => $container, 'parameter #1'],
    'wrong context type' => [static fn(ContainerValue $container, string $context): string => $context, 'parameter #2'],
    'too many required arguments' => [static fn($container, $context, $third): mixed => $third, 'requires 3 arguments'],
    'variadic must accept both runtime arguments' => [static fn(ContainerValue ...$arguments): array => $arguments, 'parameter #2'],
]);
