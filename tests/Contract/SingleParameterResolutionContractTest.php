<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SingleParameterResolution;

use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use ReflectionFunction;
use ReflectionParameter;

test('single native parameter resolution returns the declared default while batch resolution preserves omission', function (): void {
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $resolver = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $length = $resolver->target(new ReflectionParameter('array_slice', 'length'));
    $values = ['first', 'second'];

    expect($resolver->resolveParameter($length, new ParameterResolutionContext()))->toBe([2, null])
        ->and($resolver->resolveParameter($length, new ParameterResolutionContext(['length' => 1])))->toBe([2, 1])
        ->and($resolver->resolve(new ReflectionFunction('array_slice')->getParameters(), ['array' => $values, 'offset' => 1]))
        ->toBe([$values, 1])
        ->and($container->call('array_slice', ['array' => $values, 'offset' => 1]))->toBe(['second']);
});

test('single native parameter resolution reports unavailable defaults and accepts an explicit value afterwards', function (): void {
    $resolver = (new ContainerBuilder())->assemble()[1]->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $minimum = $resolver->target(new ReflectionParameter('rand', 'min'));

    expect(fn() => $resolver->resolveParameter($minimum, new ParameterResolutionContext()))
        ->toThrow(ResolutionException::class)
        ->and($resolver->resolveParameter($minimum, new ParameterResolutionContext(['min' => 3])))->toBe([0, 3]);
});

final class ObservingCaster implements CasterInterface, CasterProviderInterface
{
    public int $calls = 0;
    public string $name { get => 'observe'; }

    public function provide(string $name): ?CasterInterface
    {
        return $name === $this->name ? $this : null;
    }

    public function cast(mixed $value): mixed
    {
        ++$this->calls;
        return $value;
    }
}

final class NativeOwner
{
    public static function value(): string
    {
        return 'native value';
    }
}

test('single parameter resolution rejects attributes discovered during callable validation and recovers without replay', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = __NAMESPACE__ . '\\LateCast_' . $suffix;
    $owner = __NAMESPACE__ . '\\LateOwner_' . $suffix;
    $callback = eval(sprintf('return static fn(#[\\%s("observe")] callable $value): callable => $value;', $attribute));
    if (!$callback instanceof Closure) {
        throw new \LogicException('Expected the attributed callback fixture.');
    }
    $caster = new ObservingCaster();
    $resolver = (new ContainerBuilder())->addService(CasterProviderInterface::class, $caster)->assemble()[1]->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $target = $resolver->target(new ReflectionFunction($callback)->getParameters()[0]);
    $native = [$owner, 'value'];
    $context = new ParameterResolutionContext(['value' => $native]);
    $loads = 0;
    $loader = static function (string $class) use ($owner, $attribute, &$loads): void {
        if ($class === $owner) {
            ++$loads;
            class_alias(Cast::class, $attribute);
            class_alias(NativeOwner::class, $owner);
        }
    };
    spl_autoload_register($loader);

    try {
        expect(fn() => $resolver->resolveParameter($target, $context))->toThrow(AttributeCompositionException::class)
            ->and($loads)->toBe(1)
            ->and($caster->calls)->toBe(0);

        expect($resolver->resolveParameter($target, $context))->toBe([0, $native])
            ->and($caster->calls)->toBe(1)
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
});
