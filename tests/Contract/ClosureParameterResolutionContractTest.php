<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClosureParameters;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigValue;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionMethod;
use ReflectionParameter;

use function Componenta\DI\Tests\Support\container;

test('resolves config attributes independently for closure method parameters', function (bool $explicitTargets): void {
    $container = container(config: new Config(['a' => 'config:A', 'b' => 'config:B'], new Environment([])));
    $resolver = $container->get(ParametersResolver::class);
    $first = static fn(#[ConfigValue('a')] string $value): string => $value;
    $second = static fn(#[ConfigValue('b')] string $value): string => $value;
    $resolve = static function (Closure $closure) use ($resolver, $explicitTargets): array {
        $parameters = new ReflectionMethod($closure, '__invoke')->getParameters();
        return $explicitTargets
            ? $resolver->resolveTargets(array_map(static fn(ReflectionParameter $parameter): ParameterTarget => new ParameterTarget($parameter), $parameters))
            : $resolver->resolve($parameters);
    };

    expect($resolve($first))->toBe(['config:A'])
        ->and($resolve($second))->toBe(['config:B'])
        ->and($resolve($first))->toBe(['config:A']);
})->with(['native parameters' => false, 'explicit targets' => true]);

test('releases captures after resolving closure method parameters', function (bool $explicitTargets): void {
    $container = container(config: new Config(['a' => 'resolved'], new Environment([])));
    $resolver = $container->get(ParametersResolver::class);
    $capture = new \stdClass();
    $reference = \WeakReference::create($capture);
    $closure = static fn(#[ConfigValue('a')] string $value): array => [$capture, $value];
    $parameters = new ReflectionMethod($closure, '__invoke')->getParameters();

    $arguments = $explicitTargets
        ? $resolver->resolveTargets(array_map(static fn(ReflectionParameter $parameter): ParameterTarget => new ParameterTarget($parameter), $parameters))
        : $resolver->resolve($parameters);
    expect($arguments)->toBe(['resolved']);

    unset($parameters, $closure, $capture);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
})->with(['native parameters' => false, 'explicit targets' => true]);
