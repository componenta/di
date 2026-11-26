<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\ParameterResolutionServiceContract;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
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


test('the public service refuses resolution during assembly and uses the completed chain afterwards', function (): void {
    $parameters = new ReflectionFunction(static fn(string $value = 'default'): string => $value)->getParameters();
    $duringAssembly = null;
    $container = (new ContainerBuilder())
        ->addParameterResolver(
            static function (ContainerInterface $container) use ($parameters, &$duringAssembly): ParameterResolverInterface {
                $duringAssembly = $container->get(ParametersResolverInterface::class);
                Assert::assertInstanceOf(ParametersResolverInterface::class, $duringAssembly);

                expect(fn() => $duringAssembly->resolve($parameters))
                    ->toThrow(InvalidConfigurationException::class, 'sealed resolver pipeline');

                return new LabelResolver();
            },
            2000,
        )
        ->build();
    $resolver = $container->get(ParametersResolverInterface::class);
    Assert::assertInstanceOf(ParametersResolverInterface::class, $resolver);

    expect($resolver)->toBe($duringAssembly)
        ->and($resolver->resolve($parameters))->toBe(['custom']);
});

test('the public service remains unavailable after container finalization fails', function (): void {
    $target = static fn(string $value = 'default'): string => $value;
    $captured = null;
    $builder = (new ContainerBuilder())->addParameterResolver(
        static function (ContainerInterface $container) use ($target, &$captured): ParameterResolverInterface {
            $captured = $container->get(ParametersResolverInterface::class);
            $executor = $container->get(CallableExecutorInterface::class);
            Assert::assertInstanceOf(CallableExecutorInterface::class, $executor);
            Assert::assertSame('default', $executor->call($target));

            return new LabelResolver();
        },
        2000,
    );

    expect(fn() => $builder->build())->toThrow(InvalidConfigurationException::class, 'before applicable parameter resolver');
    Assert::assertInstanceOf(ParametersResolverInterface::class, $captured);

    $parameters = new ReflectionFunction($target)->getParameters();
    expect(fn() => $captured->resolve($parameters))
        ->toThrow(InvalidConfigurationException::class, 'sealed resolver pipeline');
});
