<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\ParameterResolutionServiceContract;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use ReflectionFunction;

final class Dependency {}

test('the public resolver uses the assembled chain without executing the target or exposing its registration authority', function (): void {
    $dependency = new Dependency();
    $container = (new ContainerBuilder())->addService(Dependency::class, $dependency)->build();
    $resolver = $container->get(ParametersResolverInterface::class);
    Assert::assertInstanceOf(ParametersResolverInterface::class, $resolver);

    $calls = 0;
    $target = static function (Dependency $object, string $label = 'default', string ...$rest) use (&$calls): array {
        ++$calls;
        return [$object, $label, $rest];
    };
    $parameters = (new ReflectionFunction($target))->getParameters();
    $provided = ['label' => 'example', 'rest' => ['first', 'named' => 'last']];
    $arguments = $resolver->resolve($parameters, $provided);

    expect($container->get(ParametersResolverInterface::class))->toBe($resolver)
        ->and($resolver)->not->toBeInstanceOf(ParametersResolver::class)
        ->and($container->has(ParametersResolver::class))->toBeFalse()
        ->and($arguments)->toBe([$dependency, 'example', 'first', 'named' => 'last'])
        ->and($calls)->toBe(0)
        ->and($container->call(new PreparedCallable($target), $arguments))->toBe([$dependency, 'example', ['first', 'named' => 'last']])
        ->and($calls)->toBe(1);
});

final class LabelResolver implements ParameterResolverInterface
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

test('the public service shares custom resolver configuration with callable execution', function (): void {
    $target = static fn(string $value): string => $value;
    $provided = ['value' => 'explicit'];
    $container = (new ContainerBuilder())
        ->addParameterResolver(new LabelResolver(), 2000)
        ->build();
    $resolver = $container->get(ParametersResolverInterface::class);
    Assert::assertInstanceOf(ParametersResolverInterface::class, $resolver);
    $parameters = (new ReflectionFunction($target))->getParameters();

    expect($resolver->resolve($parameters, $provided))->toBe(['custom'])
        ->and($container->call($target, $provided))->toBe('custom')
        ->and($resolver->resolve($parameters, $provided))->toBe(['custom']);
});

test('the public service reports unresolved required arguments through the DI exception contract', function (): void {
    $container = (new ContainerBuilder())->build();
    $resolver = $container->get(ParametersResolverInterface::class);
    Assert::assertInstanceOf(ParametersResolverInterface::class, $resolver);
    $target = static fn(string $value): string => $value;

    expect(fn() => $resolver->resolve((new ReflectionFunction($target))->getParameters()))
        ->toThrow(ResolutionException::class);
});
