<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\TargetedAttributeDiscovery;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\ResolutionException;
use LogicException;

final class IndependentFeature {}

/** @return array{class-string, string} */
function feature(bool $privateConstructor = false): array
{
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\OptionalFeature_' . $suffix;
    eval(sprintf(
        'namespace %s; #[Pending_%s] final class OptionalFeature_%s { %s }',
        __NAMESPACE__,
        $suffix,
        $suffix,
        $privateConstructor ? 'private function __construct() {}' : '',
    ));
    if (!class_exists($class, false)) {
        throw new LogicException('Expected the optional attribute fixture.');
    }
    return [$class, __NAMESPACE__ . '\\Pending_' . $suffix];
}

function targetedContainer(): Container
{
    $container = (new ContainerFactory())->createWithTargetedAttributeDiscovery(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new LogicException('Expected the DI container.');
    }
    return $container;
}

test('the targeted mode confines an optional loader failure to the target that uses it', function (): void {
    $container = targetedContainer();
    [$class, $attribute] = feature();
    $container->make($class);
    $loads = 0;
    $loader = static function (string $name) use ($attribute, &$loads): void {
        if ($name === $attribute) {
            ++$loads;
            throw new LogicException('The optional feature is temporarily unavailable.');
        }
    };
    spl_autoload_register($loader);

    try {
        expect($container->has(IndependentFeature::class))->toBeTrue()
            ->and($container->make(IndependentFeature::class))->toBeInstanceOf(IndependentFeature::class)
            ->and($container->get(IndependentFeature::class))->toBeInstanceOf(IndependentFeature::class)
            ->and($container->call(static fn(): string => 'healthy'))->toBe('healthy')
            ->and($loads)->toBe(0)
            ->and(fn() => $container->make($class))
            ->toThrow(ResolutionException::class, 'The optional feature is temporarily unavailable.')
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($container->make($class))->toBeInstanceOf($class);
});

test('targeted discovery can retry pending declarations of the current class', function (): void {
    $container = targetedContainer();
    [$class, $attribute] = feature(privateConstructor: true);
    expect($container->has($class))->toBeFalse();
    $loads = 0;
    $loader = static function (string $name) use ($attribute, &$loads): void {
        if ($name === $attribute) {
            ++$loads;
            class_alias(NoConstructor::class, $name);
        }
    };
    spl_autoload_register($loader);
    try {
        expect($container->has($class))->toBeTrue()
            ->and($container->make($class))->toBeInstanceOf($class)
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
});
