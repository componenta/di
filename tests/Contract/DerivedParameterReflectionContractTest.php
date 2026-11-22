<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DerivedParameterReflection;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use ReflectionAttribute;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use WeakReference;

function declared(#[EntryId('trusted.value')] string $value = 'fallback'): string
{
    return $value;
}

function declaredPair(
    #[EntryId('trusted.value')] string $value = 'fallback',
    #[EntryId('next.value')] string $next = 'fallback',
): string {
    return $value . ':' . $next;
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class BootstrapMarker {}

final class Subject
{
    public static function declared(#[EntryId('trusted.value')] string $value = 'fallback'): string
    {
        return $value;
    }
}

final class DerivedFunction extends ReflectionFunction
{
    public function __construct(string $function, public readonly object $payload)
    {
        parent::__construct($function);
    }
}

final class DerivedMethod extends ReflectionMethod
{
    public function __construct(string $class, string $method, public readonly object $payload)
    {
        parent::__construct($class, $method);
    }
}

final class DerivedParameter extends ReflectionParameter
{
    private readonly ReflectionFunctionAbstract $declaring;
    public bool $hideAttributes = true;

    /** @param string|array{class-string,string} $function */
    public function __construct(string|array $function, object $payload)
    {
        parent::__construct($function, 0);
        $declaring = parent::getDeclaringFunction();
        $this->declaring = $declaring instanceof ReflectionMethod
            ? new DerivedMethod($declaring->getDeclaringClass()->getName(), $declaring->getName(), $payload)
            : new DerivedFunction($declaring->getName(), $payload);
    }

    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return $this->hideAttributes ? [] : parent::getAttributes($name, $flags);
    }

    public function getDeclaringFunction(): ReflectionFunctionAbstract
    {
        return $this->declaring;
    }
}

/** @return string|array{class-string,string} */
function declaration(bool $method): string|array
{
    return $method ? [Subject::class, 'declared'] : __NAMESPACE__ . '\\declared';
}

test('parameter targets keep native and derived declarations independent', function (bool $method, bool $nativeFirst): void {
    $factory = new ParameterTargetFactory();
    $native = new ReflectionParameter(declaration($method), 0);
    $derived = new DerivedParameter(declaration($method), new \stdClass());

    foreach ($nativeFirst ? [$native, $derived, $native] : [$derived, $native, $derived] as $reflection) {
        $target = $factory->create($reflection);

        expect($target->hasAttribute(EntryId::class))->toBe($reflection === $native)
            ->and($target->firstAttribute(EntryId::class)?->value)->toBe($reflection === $native ? 'trusted.value' : null);
    }
})->with(['function' => false, 'method' => true])
    ->with(['derived first' => false, 'native first' => true]);

test('parameter resolution uses each native or derived declaration after warming the other', function (bool $method, bool $nativeFirst): void {
    [$container, $runtime] = (new ContainerBuilder())->addService('trusted.value', 'source')->assemble();
    $resolver = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $native = new ReflectionParameter(declaration($method), 0);
    $derived = new DerivedParameter(declaration($method), new \stdClass());

    foreach ($nativeFirst ? [$native, $derived, $native] : [$derived, $native, $derived] as $reflection) {
        expect($resolver->resolve([$reflection]))->toBe([$reflection === $native ? 'source' : 'fallback']);
    }
})->with(['function' => false, 'method' => true])
    ->with(['derived first' => false, 'native first' => true]);

test('a retained target with a derived reflector keeps using its current attribute plan', function (bool $method): void {
    [$container, $runtime] = (new ContainerBuilder())->addService('trusted.value', 'source')->assemble();
    $resolver = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $reflection = new DerivedParameter(declaration($method), new \stdClass());
    $target = $resolver->target($reflection);

    expect($resolver->resolveTargets([$target]))->toBe(['fallback']);
    $reflection->hideAttributes = false;
    expect($resolver->resolveTargets([$target]))->toBe(['source']);
    $reflection->hideAttributes = true;
    expect($resolver->resolveTargets([$target]))->toBe(['fallback']);
})->with(['function' => false, 'method' => true]);

test('completed parameter validation retains a consumed attribute hidden by its reflector', function (bool $hide): void {
    $function = __NAMESPACE__ . '\\declaredPair';
    $reflection = new DerivedParameter($function, new \stdClass());
    $reflection->hideAttributes = false;
    $targets = [$reflection, new ReflectionParameter($function, 1)];
    /** @var class-string $alias */
    $alias = __NAMESPACE__ . '\\RevisionMarker_' . bin2hex(random_bytes(6));
    [$container, $runtime] = (new ContainerBuilder())
        ->addFactory('trusted.value', static function () use ($reflection, $hide): string {
            $reflection->hideAttributes = $hide;
            return 'source';
        })
        ->addFactory('next.value', static function () use ($alias): string {
            class_alias(EntryId::class, $alias, autoload: false);
            return 'next';
        })
        ->assemble();
    $registry = $runtime->attributes;
    Assert::assertInstanceOf(AttributeDefinitionRegistry::class, $registry);
    $registry->definition($alias);
    $resolver = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);

    if ($hide) {
        expect(fn() => $resolver->resolve($targets))->toThrow(
            AttributeCompositionException::class,
            'changed after parameter resolution completed',
        );
        expect($resolver->resolve($targets))->toBe(['fallback', 'next']);
    } else {
        expect($resolver->resolve($targets))->toBe(['source', 'next']);
    }
})->with(['stable metadata control' => false, 'consumed declaration disappears' => true]);

test('bootstrap validates a used parameter declaration that its reflector later hides', function (bool $hide): void {
    $reflection = new DerivedParameter(__NAMESPACE__ . '\\declared', new \stdClass());
    $reflection->hideAttributes = false;
    $selected = null;
    $runtime = new ResolutionRuntime();
    $builder = (new ContainerBuilder())
        ->addFactory('trusted.value', static function () use ($reflection, $hide): string {
            $reflection->hideAttributes = $hide;
            return 'source';
        })
        ->addAttributeDefinition(static function () use ($reflection, $runtime, &$selected): AttributeDefinition {
            $selected = $runtime->parameters->resolve([$reflection]);
            return new AttributeDefinition(BootstrapMarker::class);
        });

    if ($hide) {
        expect(fn() => $builder->assemble($runtime))->toThrow(
            InvalidConfigurationException::class,
            'before its required attribute extensions were registered',
        );
    } else {
        expect($builder->assemble($runtime)[0]->get('trusted.value'))->toBe('source');
    }
    expect($selected)->toBe(['source']);
})->with(['stable metadata control' => false, 'used declaration disappears' => true]);

test('parameter metadata releases derived reflectors and their declaring function state', function (bool $method, bool $resolve): void {
    [$container, $runtime] = (new ContainerBuilder())->addService('trusted.value', 'source')->assemble();
    $resolver = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $factory = new ParameterTargetFactory();
    $payload = new \stdClass();
    $derived = new DerivedParameter(declaration($method), $payload);
    $declaring = $derived->getDeclaringFunction();
    $references = array_map(WeakReference::create(...), [$derived, $declaring, $payload]);

    if ($resolve) {
        expect($resolver->resolveTargets([new ParameterTarget($derived)]))->toBe(['fallback']);
    } else {
        expect($factory->create($derived)->attributeClasses)->toBe([]);
    }
    unset($derived, $declaring, $payload);
    gc_collect_cycles();

    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }
    $native = new ReflectionParameter(declaration($method), 0);
    expect($factory->create($native)->hasAttribute(EntryId::class))->toBeTrue()
        ->and($resolver->resolve([$native]))->toBe(['source']);
})->with(['function' => false, 'method' => true])
    ->with(['target factory' => false, 'prepared resolver' => true]);

test('parameter resolution does not extend the lifetime of an explicitly supplied target', function (bool $derived): void {
    [$container, $runtime] = (new ContainerBuilder())->addService('trusted.value', 'source')->assemble();
    $resolver = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $resolver);
    $reflection = $derived
        ? new DerivedParameter(declaration(true), new \stdClass())
        : new ReflectionParameter(declaration(true), 0);
    $target = new ParameterTarget($reflection);
    $reference = WeakReference::create($target);

    expect($resolver->resolveTargets([$target]))->toBe([$derived ? 'fallback' : 'source']);
    unset($target, $reflection);

    expect($reference->get())->toBeNull();
})->with(['native reflector' => false, 'derived reflector' => true]);
