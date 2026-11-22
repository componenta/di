<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;

final class CurrentRuleLimit
{
    public static int $current = 1;
    public readonly int $value;
    public function __construct()
    {
        $this->value = self::$current;
    }
}

#[Attribute(Attribute::TARGET_ALL)]
final class PositiveLimit
{
    public function __construct(public CurrentRuleLimit $limit) {}
}

final class PositiveLimitRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        $limit = $attribute->arguments[0] ?? null;
        if (!$limit instanceof CurrentRuleLimit || $limit->value < 1) {
            throw new AttributeCompositionException('Attribute limit must be positive.');
        }
    }
}

function withCurrentLimit(#[PositiveLimit(new CurrentRuleLimit())] string $value = 'ok'): string
{
    return $value;
}

function withoutRule(string $unrelated = 'ok'): string
{
    return $unrelated;
}

final class EarlierArgument {}

function withLaterLimit(
    EarlierArgument $dependency,
    #[PositiveLimit(new CurrentRuleLimit())] string $value = 'ok',
): string {
    return $value;
}

test('a composition rule leaves an unrelated callable classification reusable', function (): void {
    $observer = new class () implements ParameterResolverInterface {
        public int $checks = 0;

        public function supports(ParameterTarget $target): bool
        {
            if ($target->name === 'unrelated') {
                ++$this->checks;
            }
            return false;
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
        {
            return null;
        }
    };
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(PositiveLimit::class, rules: [new PositiveLimitRule()]))
        ->addParameterResolver($observer, 1000)
        ->build();

    expect($container->call(__NAMESPACE__ . '\\withoutRule'))->toBe('ok');
    $checks = $observer->checks;

    expect($container->call(__NAMESPACE__ . '\\withoutRule'))->toBe('ok')
        ->and($observer->checks)->toBe($checks);
});

test('repeated calls validate current composition rule arguments', function (bool $named): void {
    $container = (new ContainerBuilder())->addAttributeDefinition(
        new AttributeDefinition(PositiveLimit::class, rules: [new PositiveLimitRule()]),
    )->build();
    $executor = $container->get(CallableExecutorInterface::class);
    Assert::assertInstanceOf(CallableExecutorInterface::class, $executor);
    $callable = $named ? __NAMESPACE__ . '\\withCurrentLimit'
        : static fn(#[PositiveLimit(new CurrentRuleLimit())] string $value = 'ok'): string => $value;

    try {
        CurrentRuleLimit::$current = 1;
        expect($executor->call($callable))->toBe('ok');
        CurrentRuleLimit::$current = -1;
        expect(fn() => $executor->call($callable))->toThrow(AttributeCompositionException::class, 'Attribute limit must be positive.');
        CurrentRuleLimit::$current = 2;
        expect($executor->call($callable))->toBe('ok');
    } finally {
        CurrentRuleLimit::$current = 1;
    }
})->with(['named function' => true, 'closure' => false]);

test('a warmed callable validates a changed rule before resolving earlier arguments', function (): void {
    $container = (new ContainerBuilder())->addAttributeDefinition(
        new AttributeDefinition(PositiveLimit::class, rules: [new PositiveLimitRule()]),
    )->build();
    $calls = 0;
    $factory = Definition::factory(static function () use (&$calls): EarlierArgument {
        ++$calls;
        return new EarlierArgument();
    });
    $name = __NAMESPACE__ . '\\withLaterLimit';
    $container->set(EarlierArgument::class, $factory);

    try {
        CurrentRuleLimit::$current = 1;
        expect($container->call($name))->toBe('ok')->and($calls)->toBe(1);
        $container->set(EarlierArgument::class, $factory);
        CurrentRuleLimit::$current = -1;

        expect(fn() => $container->call($name))->toThrow(AttributeCompositionException::class)
            ->and($calls)->toBe(1);
    } finally {
        CurrentRuleLimit::$current = 1;
    }
});

#[PositiveLimit(new CurrentRuleLimit())]
final class ClassWithLimit {}
final class PropertyWithLimit
{
    #[PositiveLimit(new CurrentRuleLimit())]
    public string $value = 'ok';
}
final class MethodWithLimit
{
    #[PositiveLimit(new CurrentRuleLimit())]
    public function value(): string
    {
        return 'ok';
    }
}
final class ConstructorWithLimit
{
    public function __construct(#[PositiveLimit(new CurrentRuleLimit())] public string $value = 'ok') {}
}

test('fresh objects validate composition rules after earlier resolutions', function (string $class): void {
    if (!class_exists($class)) {
        throw new \LogicException('Unknown composition rule fixture: ' . $class);
    }
    $container = (new ContainerBuilder())->addAttributeDefinition(
        new AttributeDefinition(PositiveLimit::class, rules: [new PositiveLimitRule()]),
    )->build();

    try {
        CurrentRuleLimit::$current = 1;
        expect($container->make($class))->toBeInstanceOf($class);
        CurrentRuleLimit::$current = -1;
        expect(fn() => $container->make($class))->toThrow(AttributeCompositionException::class, 'Attribute limit must be positive.');
        CurrentRuleLimit::$current = 2;
        expect($container->make($class))->toBeInstanceOf($class);
    } finally {
        CurrentRuleLimit::$current = 1;
    }
})->with([ClassWithLimit::class, PropertyWithLimit::class, MethodWithLimit::class, ConstructorWithLimit::class]);

test('an earlier availability check does not change optional dependency resolution', function (bool $inspectFirst): void {
    $container = (new ContainerBuilder())->addAttributeDefinition(
        new AttributeDefinition(PositiveLimit::class, rules: [new PositiveLimitRule()]),
    )->build();
    $executor = $container->get(CallableExecutorInterface::class);
    Assert::assertInstanceOf(CallableExecutorInterface::class, $executor);
    $callable = static fn(?ClassWithLimit $service = null): ?ClassWithLimit => $service;

    try {
        CurrentRuleLimit::$current = 1;
        if ($inspectFirst) {
            expect($container->has(ClassWithLimit::class))->toBeTrue();
        }

        CurrentRuleLimit::$current = -1;
        expect($executor->call($callable))->toBeNull()
            ->and($container->has(ClassWithLimit::class))->toBeFalse();

        CurrentRuleLimit::$current = 2;
        expect($executor->call($callable))->toBeInstanceOf(ClassWithLimit::class);
    } finally {
        CurrentRuleLimit::$current = 1;
    }
})->with(['without prior inspection' => false, 'after has()' => true]);
