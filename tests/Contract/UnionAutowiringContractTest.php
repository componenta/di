<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\UnionAutowiring;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;

interface Left {}
interface Right {}
interface Other {}

final class LeftValue implements Left {}
final class RightValue implements Right {}
final class BothValue implements Left, Right {}
final class OtherValue implements Other {}

enum Choice
{
    case Selected;
}

abstract class AbstractValue {}
final class ConcreteValue extends AbstractValue {}

final class ConstructorTarget
{
    public function __construct(public Left|Right $value) {}
}

final class NullableTarget
{
    public function __construct(public Left|Right|null $value) {}
}

final class OptionalTarget
{
    public function __construct(public Left|Right|false|null $value = false) {}
}

final class MethodTarget
{
    public Left|Right $value;

    public function configure(Left|Right $value): void
    {
        $this->value = $value;
    }
}

class ContextParent {}

class ContextTarget extends ContextParent
{
    public function same(self|Right $value): self|Right
    {
        return $value;
    }

    public function parentValue(parent|Right $value): parent|Right
    {
        return $value;
    }
}

final class InheritedContext extends ContextTarget {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class NoCacheMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function builder(bool $uncached = false): ContainerBuilder
{
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(NoCacheMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

function select(Left|Right $value): Left|Right
{
    return $value;
}

function reverse(Right|Left $value): Left|Right
{
    return $value;
}

function optional(Left|Right|false|null $value = false): Left|Right|false|null
{
    return $value;
}

/** @return callable():mixed */
function operation(\Componenta\DI\Container $container, string $kind): callable
{
    return match ($kind) {
        'call' => static fn(): mixed => $container->call(__NAMESPACE__ . '\\select'),
        'reflection' => static fn(): mixed => $container->make(ConstructorTarget::class),
        'definition constructor', 'definition method' => static fn(): mixed => $container->make('target'),
        default => throw new LogicException('Unknown union entry path.'),
    };
}

function targetDefinition(ContainerBuilder $builder, string $kind): ContainerBuilder
{
    return match ($kind) {
        'definition constructor' => $builder->addDefinition('target', ClassDefinition::create(ConstructorTarget::class)->autowire()),
        'definition method' => $builder->addDefinition('target', ClassDefinition::create(MethodTarget::class)->autowire()->call('configure')),
        default => $builder,
    };
}

test('one available union candidate is shared across reflection calls and explicit definition entry paths', function (string $kind): void {
    $expected = new LeftValue();
    $calls = 0;
    $container = targetDefinition(builder()->addFactory(Left::class, static function () use ($expected, &$calls): object {
        ++$calls;
        return $expected;
    }), $kind)->build();
    $resolve = operation($container, $kind);

    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $result = $resolve();
        if ($kind !== 'call') {
            if (!$result instanceof ConstructorTarget && !$result instanceof MethodTarget) {
                throw new LogicException('Expected a union target.');
            }
            $result = $result->value;
        }
        expect($result)->toBe($expected);
    }
    expect($calls)->toBe(1);
})->with(['call', 'reflection', 'definition constructor', 'definition method']);

test('ambiguous union candidates fail before any factory executes', function (string $kind): void {
    $calls = [];
    $container = targetDefinition(builder()
        ->addFactory(Left::class, static function () use (&$calls): Left {
            $calls[] = 'left';
            return new LeftValue();
        })
        ->addFactory(Right::class, static function () use (&$calls): Right {
            $calls[] = 'right';
            return new RightValue();
        }), $kind)
        ->build();

    expect(operation($container, $kind))->toThrow(
        ResolutionException::class,
        'union autowiring is ambiguous; available services: ' . Left::class . ', ' . Right::class,
    );
    expect($calls)->toBe([]);
})->with(['call', 'reflection', 'definition constructor', 'definition method']);

test('union ambiguity is independent of declaration order and converging aliases', function (bool $reverse): void {
    $value = new BothValue();
    $container = builder()->addService('shared', $value)
        ->addAlias(Left::class, 'shared')->addAlias(Right::class, 'shared')->build();

    expect(fn() => $container->call(__NAMESPACE__ . ($reverse ? '\\reverse' : '\\select')))
        ->toThrow(ResolutionException::class, 'available services: ' . Left::class . ', ' . Right::class);
})->with([false, true]);

test('supplied union values and provider attributes resolve before ambiguous autowiring', function (string|int $key): void {
    $expected = new RightValue();
    $container = builder()->addService(Left::class, new LeftValue())
        ->addService(Right::class, $expected)->addService('selected', $expected)->build();

    expect($container->call(__NAMESPACE__ . '\\select', [$key => $expected]))->toBe($expected)
        ->and($container->call(static fn(#[EntryId('selected')] Left|Right $value): Left|Right => $value))->toBe($expected);
})->with(['name' => 'value', 'position' => 0, 'type key' => Right::class]);

test('an earlier custom resolver retains priority over union candidates', function (): void {
    $expected = new RightValue();
    $resolver = new class ($expected) implements ParameterResolverInterface {
        public function __construct(private readonly Right $value) {}
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'value';
        }
        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [$target->position, $this->value];
        }
    };
    $container = builder()->addService(Left::class, new LeftValue())->addService(Right::class, $expected)
        ->addParameterResolver($resolver, 1000)->build();

    expect($container->call(__NAMESPACE__ . '\\select'))->toBe($expected);
});

test('unavailable unions preserve reflection defaults and nullable fallback', function (): void {
    $container = builder()->build();

    expect($container->call(__NAMESPACE__ . '\\optional'))->toBeFalse()
        ->and($container->make(OptionalTarget::class)->value)->toBeFalse()
        ->and($container->make(NullableTarget::class)->value)->toBeNull()
        ->and(fn() => $container->call(__NAMESPACE__ . '\\select'))->toThrow(ResolutionException::class);
});

test('explicit definitions preserve omission and require the autowire opt-in', function (): void {
    $value = new LeftValue();
    $container = builder()->addService(Left::class, $value)
        ->addDefinition('disabled', new ClassDefinition(OptionalTarget::class))
        ->addDefinition('enabled', ClassDefinition::create(OptionalTarget::class)->autowire())
        ->build();
    $disabled = $container->make('disabled');
    $enabled = $container->make('enabled');
    if (!$disabled instanceof OptionalTarget || !$enabled instanceof OptionalTarget) {
        throw new LogicException('Expected explicit optional-union targets.');
    }
    expect($disabled->value)->toBeFalse()
        ->and($enabled->value)->toBe($value);

    $unbound = builder()
        ->addDefinition('optional', ClassDefinition::create(OptionalTarget::class)->autowire())
        ->addDefinition('required', ClassDefinition::create(NullableTarget::class)->autowire())->build();
    $optional = $unbound->make('optional');
    if (!$optional instanceof OptionalTarget) {
        throw new LogicException('Expected the defaulted union target.');
    }
    expect($optional->value)->toBeFalse()
        ->and(fn() => $unbound->make('required'))->toThrow(ResolutionException::class);
});

test('a selected binding must satisfy its selected union arm even when another arm accepts its value', function (string $kind): void {
    $calls = 0;
    $container = targetDefinition(builder()->addFactory(Left::class, static function () use (&$calls): Right {
        ++$calls;
        return new RightValue();
    }), $kind)->build();

    expect(operation($container, $kind))->toThrow(ResolutionException::class, 'selected union service "' . Left::class . '"');
    expect($calls)->toBe(1);
})->with(['call', 'reflection', 'definition constructor', 'definition method']);

test('a selected union factory failure does not fall back to a default or null', function (): void {
    $cause = new LogicException('selected factory failed');
    $calls = 0;
    $container = builder()->addFactory(Left::class, static function () use ($cause, &$calls): never {
        ++$calls;
        throw $cause;
    })->build();

    try {
        $container->call(__NAMESPACE__ . '\\optional');
        \PHPUnit\Framework\Assert::fail('The selected factory must fail the resolution.');
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBe($cause)
            ->and($calls)->toBe(1);
    }
});

test('Closure participates in union selection under the normal availability rule', function (): void {
    $callback = static fn(): string => 'value';
    $target = static fn(Closure|Left|false $value = false): Closure|Left|false => $value;
    $container = builder()->build();
    expect($container->call($target))->toBeFalse();
    $container->set(Closure::class, $callback);
    expect($container->call($target))->toBe($callback);
    $container->set(Left::class, new LeftValue());
    expect(fn() => $container->call($target))->toThrow(ResolutionException::class, 'union autowiring is ambiguous');

    $invalid = builder()->addFactory(Left::class, static fn(): Closure => $callback)->build();
    expect(fn() => $invalid->call($target))->toThrow(ResolutionException::class, 'selected union service "' . Left::class . '"');
});

test('warmed union plans observe replacement alias changes and newly available candidates', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $target = __NAMESPACE__ . '\\optional';
    expect($container->call($target))->toBeFalse();
    $first = new LeftValue();
    $second = new LeftValue();
    $container->set('first', $first);
    $container->alias(Left::class, 'first');
    expect($container->call($target))->toBe($first);
    $container->set('first', $second);
    expect($container->call($target))->toBe($second);
    $container->set('second', $first);
    $container->alias(Left::class, 'second');
    expect($container->call($target))->toBe($first);
    $container->set(Right::class, new RightValue());
    expect(fn() => $container->call($target))->toThrow(ResolutionException::class, 'union autowiring is ambiguous');
})->with(['cached plans' => false, 'uncached plans' => true]);

test('warmed union plans observe newly registered external ownership', function (): void {
    $container = builder()->build();
    expect($container->call(__NAMESPACE__ . '\\optional'))->toBeFalse();
    $expected = new RightValue();
    $container->addContainer(new class ($expected) implements ContainerInterface {
        public function __construct(private readonly Right $value) {}
        public function has(string $id): bool
        {
            return $id === Right::class;
        }
        public function get(string $id): mixed
        {
            return $this->value;
        }
    });
    expect($container->call(__NAMESPACE__ . '\\optional'))->toBe($expected);
});

test('union candidate availability probes have stable order', function (): void {
    $owner = new ContextTarget();
    $external = new class ($owner) implements ContainerInterface {
        /** @var list<string> */
        public array $seen = [];
        public function __construct(private readonly ContextTarget $value) {}
        public function has(string $id): bool
        {
            $this->seen[] = $id;
            return $id === ContextTarget::class;
        }
        public function get(string $id): mixed
        {
            return $this->value;
        }
    };
    $resolver = new AutowireByTypeResolver($external);
    foreach ([__NAMESPACE__ . '\\select', __NAMESPACE__ . '\\reverse'] as $function) {
        $external->seen = [];
        $target = new ParameterTarget(new ReflectionFunction($function)->getParameters()[0]);
        expect($resolver->resolveParameter($target, new ParameterResolutionContext()))->toBeNull()
            ->and($external->seen)->toBe([Left::class, Right::class]);
    }
});

test('union contextual types retain the declaring class and parent for inherited methods', function (): void {
    $expected = new ContextTarget();
    $parent = new ContextParent();
    $container = builder()->addService(ContextTarget::class, $expected)->addService(ContextParent::class, $parent)->build();
    $target = new InheritedContext();
    expect($container->call([$target, 'same']))->toBe($expected)
        ->and($container->call([$target, 'parentValue']))->toBe($parent);
});

test('enum and abstract union arms are available only through ordinary container bindings', function (): void {
    $container = builder()->build();
    $enum = static fn(Choice|Left|null $value = null): Choice|Left|null => $value;
    $abstract = static fn(AbstractValue|Left|null $value = null): AbstractValue|Left|null => $value;
    expect($container->call($enum))->toBeNull()->and($container->call($abstract))->toBeNull();
    $concrete = new ConcreteValue();
    $container->set(Choice::class, Choice::Selected);
    $container->set(AbstractValue::class, $concrete);
    expect($container->call($enum))->toBe(Choice::Selected)
        ->and($container->call($abstract))->toBe($concrete);
});

test('variadic intersection and DNF types keep their existing explicit-value contracts', function (): void {
    $both = new BothValue();
    $other = new OtherValue();
    $container = builder()->addService(Left::class, $both)->addService(Right::class, $both)->addService(Other::class, $other)->build();
    $variadic = static fn(Left|Right ...$values): array => $values;
    $intersection = static fn(Left&Right $value): object => $value;
    $dnf = static fn((Left&Right)|Other|null $value = null): object|null => $value;
    expect($container->call($variadic))->toBe([])
        ->and($container->call($variadic, ['values' => [$both]]))->toBe([$both])
        ->and(fn() => $container->call($intersection))->toThrow(ResolutionException::class)
        ->and($container->call($intersection, [Left::class => $both]))->toBe($both)
        ->and($container->call($dnf))->toBeNull()
        ->and($container->call($dnf, [Other::class => $other]))->toBe($other);
});
