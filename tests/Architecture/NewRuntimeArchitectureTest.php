<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Architecture;

use Componenta\DI\Attribute\SetUp;
use Componenta\Config\Config;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerator;
use Componenta\DI\Compile\Attribute\GeneratedAttributeCode;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverFingerprint;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverGenerator;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverLoader;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverWriter;
use Componenta\DI\Compile\Factory\FactoryCode;
use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
use Componenta\DI\Compile\Parameter\DefaultParameterResolverCodeGenerators;
use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProtectedServiceIds;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Entry\SetUpRunner;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SkipConstructorForTest {}

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class LazyForTest {}

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ProxyForTest {}

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class ValueForTest
{
    public function __construct(public int $value) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
class OrderedBaseAttributeForTest
{
    public function __construct(public string $value) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
final class OrderedChildAttributeForTest extends OrderedBaseAttributeForTest {}

final class OrderedAttributeEntryForTest
{
    public function __construct(
        #[OrderedChildAttributeForTest('child')]
        #[OrderedBaseAttributeForTest('base')]
        public string $value = '',
    ) {}
}

#[\Attribute(\Attribute::TARGET_ALL)]
final readonly class UnsupportedMetadataForTest {}

#[UnsupportedMetadataForTest]
final class UnsupportedMetadataEntryForTest
{
    #[UnsupportedMetadataForTest]
    public string $value = '';

    #[UnsupportedMetadataForTest]
    public function configure(#[UnsupportedMetadataForTest] string $name = ''): void {}
}

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class SideEffectForTest
{
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }
}

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class RetryValueForTest
{
    public function __construct(public int $value) {}
}

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class MutableValueForTest
{
    public function __construct(public int $value) {}
}

interface LeftForTest {}
interface RightForTest {}

final class BothForTest implements LeftForTest, RightForTest {}
final class LeftOnlyForTest implements LeftForTest {}

final class DnfEntryForTest
{
    public function __construct(public (LeftForTest&RightForTest)|\stdClass $value) {}
}

final class FixedResolverForTest implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, 7];
    }
}


final class NullResolverForTest implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

final class CountingResolverForTest implements ParameterResolverInterface
{
    public int $supportsCalls = 0;

    public function supports(ParameterTarget $target): bool
    {
        ++$this->supportsCalls;
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, 7];
    }
}

final class MutatingResolverForTest implements ParameterResolverInterface
{
    public ?ParametersResolver $chain = null;
    private bool $mutated = false;

    public function supports(ParameterTarget $target): bool
    {
        if (!$this->mutated && $this->chain !== null) {
            $this->mutated = true;
            $this->chain->add(new FixedResolverForTest());
        }

        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, 7];
    }
}

final class WrongPositionResolverForTest implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position + 1, 17];
    }
}

final class WrongValueResolverForTest implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, 'not-an-int'];
    }
}

final class MalformedResolverForTest implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [0 => $target->position, 2 => 17];
    }
}

final class FixedGeneratorForTest implements ParameterResolverCodeGeneratorInterface
{
    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        return GeneratedResolverCode::terminal(sprintf(
            '%s = 7; goto %s;',
            $context->argumentVariable,
            $context->resolvedLabel,
        ));
    }
}

final class SkipConstructorHandlerForTest implements CompilableAttributeHandlerInterface
{
    public AttributePhase $phase { get => AttributePhase::BeforeInstantiation; }
    public int $priority { get => 300; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionClass
            && $attributeClass === SkipConstructorForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $context->disableConstructor();
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        return new GeneratedAttributeCode(
            $context->creationExpression . '->disableConstructor();',
            disablesConstructor: true,
        );
    }
}

final class LazyHandlerForTest implements CompilableAttributeHandlerInterface
{
    public AttributePhase $phase { get => AttributePhase::BeforeInstantiation; }
    public int $priority { get => 200; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionClass && $attributeClass === LazyForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $context->selectStrategy(CreationStrategy::Lazy);
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        return new GeneratedAttributeCode(sprintf(
            '%s->selectStrategy(\\%s::Lazy);',
            $context->creationExpression,
            CreationStrategy::class,
        ));
    }
}

final class ProxyHandlerForTest implements CompilableAttributeHandlerInterface
{
    public AttributePhase $phase { get => AttributePhase::BeforeInstantiation; }
    public int $priority { get => 250; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionClass && $attributeClass === ProxyForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $context->selectStrategy(CreationStrategy::Proxy);
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        return new GeneratedAttributeCode(sprintf(
            '%s->selectStrategy(\%s::Proxy);',
            $context->creationExpression,
            CreationStrategy::class,
        ));
    }
}

final class ValueHandlerForTest implements AttributeHandlerInterface
{
    public int $calls = 0;
    public AttributePhase $phase { get => AttributePhase::AfterInstantiation; }
    public int $priority { get => 100; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty && $attributeClass === ValueForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$context->claimProperty($target)) {
            return;
        }

        ++$this->calls;
        $context->writeProperty($target, $attribute->value);
    }
}

final class SideEffectHandlerForTest implements AttributeHandlerInterface
{
    public int $calls = 0;
    public AttributePhase $phase { get => AttributePhase::AfterInstantiation; }
    public int $priority { get => 1000; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty && $attributeClass === SideEffectForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$context->claimProperty($target)) {
            return;
        }

        ++$this->calls;
        $context->writeProperty($target, 99);
    }
}

final class RetryValueHandlerForTest implements AttributeHandlerInterface
{
    /** @var array<string, int> */
    private array $attempts = [];

    public AttributePhase $phase { get => AttributePhase::AfterInstantiation; }
    public int $priority { get => 900; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && $attributeClass === RetryValueForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$context->claimProperty($target)) {
            return;
        }

        $key = $target->getDeclaringClass()->getName() . '::$' . $target->getName();
        $attempt = $this->attempts[$key] = ($this->attempts[$key] ?? 0) + 1;

        if ($attempt === 1) {
            throw new \RuntimeException('first realization attempt fails');
        }

        $context->writeProperty($target, $attribute->value);
    }

    public function attemptsFor(string $class): int
    {
        return $this->attempts[$class . '::$property'] ?? 0;
    }
}

final class MutatingAttributeHandlerForTest implements CompilableAttributeHandlerInterface
{
    public AttributePhase $phase { get => AttributePhase::AfterInstantiation; }
    public int $priority { get => 500; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && $attributeClass === MutableValueForTest::class;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof MutableValueForTest || !$target instanceof ReflectionProperty) {
            throw new \LogicException('Unexpected mutable attribute invocation.');
        }

        if ($context->claimProperty($target)) {
            $context->writeProperty($target, $attribute->value);
        }

        ++$attribute->value;
    }

    public function generateAttributeCode(
        object $attribute,
        Reflector $target,
        AttributeCodeGenerationContext $context,
    ): GeneratedAttributeCode {
        if (!$attribute instanceof MutableValueForTest || !$target instanceof ReflectionProperty) {
            throw new \LogicException('Unexpected mutable attribute compilation.');
        }

        $value = $attribute->value++;

        return new GeneratedAttributeCode(
            sprintf(
                'if (%1$s->claimProperty(%2$s)) { %1$s->writeProperty(%2$s, %3$d); }',
                $context->creationExpression,
                $context->targetExpression,
                $value,
            ),
            usesTarget: true,
        );
    }
}

final class MutatingHandlerRegistryForTest implements AttributeHandlerInterface
{
    public ?AttributeHandlerRegistry $registry = null;
    private bool $mutated = false;

    public AttributePhase $phase { get => AttributePhase::AfterInstantiation; }
    public int $priority { get => 1000; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        if (!$this->mutated && $this->registry !== null) {
            $this->mutated = true;
            $this->registry->add(new ValueHandlerForTest());
        }

        return false;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void {}
}

final class MutatingGeneratorRegistryForTest implements ParameterResolverCodeGeneratorInterface
{
    public ?ParameterResolverCodeGeneratorRegistry $registry = null;
    private bool $mutated = false;

    public function generate(
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterCodeGenerationContext $context,
    ): GeneratedResolverCode {
        if (!$this->mutated && $this->registry !== null) {
            $this->mutated = true;
            $this->registry->register(FixedResolverForTest::class, $this);
        }

        return GeneratedResolverCode::terminal(
            sprintf('%s = 7; goto %s;', $context->argumentVariable, $context->resolvedLabel),
        );
    }
}

final class MutableMetadataHandlerForTest implements AttributeHandlerInterface
{
    public AttributePhase $phase = AttributePhase::AfterInstantiation;
    public int $priority = 10;

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return false;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void {}
}

final class DynamicAttributeHandlerForTest implements AttributeHandlerInterface
{
    public function __construct(private string $attributeClass) {}

    public AttributePhase $phase { get => AttributePhase::AfterInstantiation; }
    public int $priority { get => 100; }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && $attributeClass === $this->attributeClass;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($context->claimProperty($target)) {
            $context->writeProperty($target, $attribute->value);
        }
    }
}

final class EagerEntryForTest
{
    public int $writes = 0;

    #[ValueForTest(42)]
    public int $property = 0 {
        set {
            ++$this->writes;
            $this->property = $value;
        }
    }

    public function __construct(public int $number) {}
}

#[SkipConstructorForTest]
final class NoConstructorEntryForTest
{
    #[ValueForTest(42)]
    public int $property;

    public function __construct()
    {
        throw new \LogicException('Constructor must be skipped.');
    }
}

#[SkipConstructorForTest]
final class PrivateNoConstructorEntryForTest
{
    public int $value = 82;

    private function __construct()
    {
        throw new \LogicException('Private constructor must be skipped.');
    }
}

final class PrivateConstructorEntryForTest
{
    private function __construct() {}
}

#[LazyForTest]
final class LazyEntryForTest
{
    #[ValueForTest(9)]
    public int $property;

    public function __construct(public int $number) {}
}

#[LazyForTest]
final class RetryLazyEntryForTest
{
    #[RetryValueForTest(77)]
    public int $property;

    public function __construct(public int $number = 14) {}
}

#[ProxyForTest]
final class RetryProxyEntryForTest
{
    #[RetryValueForTest(78)]
    public int $property;

    public function __construct(public int $number = 15) {}
}

final class RequiredNumberEntryForTest
{
    public function __construct(public int $number) {}
}

final class UnwritableEntryForTest
{
    #[SideEffectForTest]
    public static int $staticValue = 1;

    #[SideEffectForTest]
    public readonly int $readonlyValue;

    public function __construct()
    {
        $this->readonlyValue = 2;
    }
}

#[SetUp('boot')]
final class SetUpEntryForTest
{
    public int $setupValue = 0;

    public function __construct(public int $number) {}

    public function boot(int $number): void
    {
        $this->setupValue = $number;
    }
}

final class CountedDefaultForTest
{
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }
}

final class ObjectDefaultEntryForTest
{
    public function __construct(
        public CountedDefaultForTest $value = new CountedDefaultForTest(),
    ) {}
}

final class WritableSideEffectEntryForTest
{
    #[SideEffectForTest]
    public int $value;
}

class ParentPrivateAttributeEntryForTest
{
    #[ValueForTest(66)]
    private int $value = 0;

    public function value(): int
    {
        return $this->value;
    }
}

final class ChildPrivateAttributeEntryForTest extends ParentPrivateAttributeEntryForTest {}

final class MutableAttributeEntryForTest
{
    #[MutableValueForTest(31)]
    public int $value = 0;
}

final class ThrowingHookEntryForTest
{
    #[ValueForTest(1)]
    public int $value {
        set {
            throw new \DomainException('hook failure');
        }
    }
}

final class VariadicEntryForTest
{
    /** @var list<string> */
    public array $values;

    public function __construct(string ...$values)
    {
        $this->values = $values;
    }
}

#[SkipConstructorForTest]
final class NoConstructorVariadicEntryForTest
{
    public int $value = 73;

    public function __construct(string ...$values)
    {
        throw new \LogicException('constructor must not execute');
    }
}

final class ByReferenceEntryForTest
{
    public int $value;

    public function __construct(int &$value)
    {
        $this->value = ++$value;
    }
}

#[SkipConstructorForTest]
final class NoConstructorByReferenceEntryForTest
{
    public int $value = 74;

    public function __construct(int &$value)
    {
        throw new \LogicException('constructor must not execute');
    }
}

final class ThrowingConstructorEntryForTest
{
    public function __construct()
    {
        throw new \TypeError('constructor failure');
    }
}

#[LazyForTest]
final class ThrowingLazyConstructorEntryForTest
{
    public int $touch = 1;

    public function __construct()
    {
        throw new \TypeError('lazy constructor failure');
    }
}

#[ProxyForTest]
final class ThrowingProxyConstructorEntryForTest
{
    public int $touch = 1;

    public function __construct()
    {
        throw new \TypeError('proxy constructor failure');
    }
}

final class CallableInvokerForTest implements CallableInvokerInterface
{
    public function __construct(
        private readonly ParametersResolver $parameters,
    ) {}

    public function call(mixed $callable, array $params = []): mixed
    {
        if (is_array($callable) && count($callable) === 2 && is_object($callable[0])) {
            $method = new ReflectionMethod($callable[0], (string) $callable[1]);
            $arguments = $this->parameters->resolve($method->getParameters(), $params);

            return $method->invokeArgs($callable[0], $arguments);
        }

        return $callable(...array_values($params));
    }
}

final class ProxyFactoryForTest implements ProxyFactoryInterface
{
    public int $lazyCalls = 0;

    public function makeLazy(string $class, callable $initializer): object
    {
        ++$this->lazyCalls;
        $entry = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $initializer($entry);

        return $entry;
    }

    public function makeProxy(string $class, callable $initializer): object
    {
        return $initializer((new ReflectionClass($class))->newInstanceWithoutConstructor());
    }
}

final class NativeProxyFactoryForTest implements ProxyFactoryInterface
{
    public function makeLazy(string $class, callable $initializer): object
    {
        return (new ReflectionClass($class))->newLazyGhost($initializer);
    }

    public function makeProxy(string $class, callable $initializer): object
    {
        return (new ReflectionClass($class))->newLazyProxy($initializer);
    }
}

class ParentFingerprintStateForTest
{
    public function __construct(private int $secret) {}
}

final class ChildFingerprintStateForTest extends ParentFingerprintStateForTest {}

final class OptionalDefaultsEntryForTest
{
    public function __construct(
        public int $number = 13,
        public string $name = 'default',
    ) {}
}

final class NoArgumentsEntryForTest
{
    public function __construct() {}
}

final class HookedFingerprintForTest
{
    public int $reads = 0;

    public int $backed = 10 {
        get {
            ++$this->reads;
            return $this->backed;
        }
    }

    public int $virtual {
        get {
            ++$this->reads;
            return 20;
        }
    }
}

it('preserves native declaration order for inherited parameter attributes', function () {
    $parameter = (new ReflectionMethod(
        OrderedAttributeEntryForTest::class,
        '__construct',
    ))->getParameters()[0];
    $attribute = (new ParameterTarget($parameter))->firstAttribute(
        OrderedBaseAttributeForTest::class,
    );

    expect($attribute)->toBeInstanceOf(OrderedChildAttributeForTest::class)
        ->and($attribute->value)->toBe('child');
});

it('fingerprints unsupported attributes and rejects source-less user classes', function () {
    $processor = new AttributeProcessor(new AttributeHandlerRegistry());

    expect($processor->sourceAttributeClasses(
        new ReflectionClass(UnsupportedMetadataEntryForTest::class),
    ))->toContain(UnsupportedMetadataForTest::class);

    $short = 'EvalOnlyFingerprintForTest_' . bin2hex(random_bytes(4));
    $class = __NAMESPACE__ . '\\' . $short;
    eval(sprintf('namespace %s; final class %s {}', __NAMESPACE__, $short));

    expect(fn() => GeneratedEntryResolverFingerprint::sources([$class]))
        ->toThrow(\LogicException::class);
});

it('matches complete DNF object types in the runtime resolver', function () {
    $parameter = (new ReflectionMethod(DnfEntryForTest::class, '__construct'))->getParameters()[0];
    $target = new ParameterTarget($parameter);
    $resolver = new ArrayTypedResolver();
    $both = new BothForTest();

    expect($target->typeNames)->toBe([
        LeftForTest::class,
        RightForTest::class,
        \stdClass::class,
    ])
        ->and($resolver->resolveParameter(
            $target,
            new ParameterResolutionContext(['candidate' => new LeftOnlyForTest()]),
        ))->toBeNull()
        ->and($resolver->resolveParameter(
            $target,
            new ParameterResolutionContext(['candidate' => $both]),
        ))->toBe([0, $both]);
});

it('caches supports slots for the immutable parameter target', function () {
    $resolver = new CountingResolverForTest();
    $parameters = new ParametersResolver($resolver);
    $parameter = (new ReflectionMethod(EagerEntryForTest::class, '__construct'))->getParameters()[0];
    $target = $parameters->target($parameter);

    expect($parameters->resolverSlotsFor($target))->toBe([0])
        ->and($parameters->resolverSlotsFor($target))->toBe([0])
        ->and($resolver->supportsCalls)->toBe(1);
});

it('invalidates supported resolver slots when the open pipeline changes', function () {
    $first = new CountingResolverForTest();
    $second = new CountingResolverForTest();
    $parameters = new ParametersResolver($first);
    $parameter = (new ReflectionMethod(EagerEntryForTest::class, '__construct'))
        ->getParameters()[0];
    $target = $parameters->target($parameter);

    expect($parameters->resolverSlotsFor($target))->toBe([0])
        ->and($first->supportsCalls)->toBe(1);

    $parameters->add($second, priority: 10);

    expect($parameters->resolverSlotsFor($target))->toBe([0, 1])
        ->and($first->supportsCalls)->toBe(2)
        ->and($second->supportsCalls)->toBe(1);
});

it('rejects unwritable properties before handlers perform side effects', function () {
    $handler = new SideEffectHandlerForTest();
    $registry = new AttributeHandlerRegistry();
    $registry->add($handler);
    $processor = new AttributeProcessor($registry);
    $entry = new UnwritableEntryForTest();
    $class = new ReflectionClass($entry);
    $context = new ObjectCreationContext($class);
    $context->initialize($entry);

    $processor->process($class, AttributePhase::AfterInstantiation, $context);

    expect($handler->calls)->toBe(0)
        ->and($entry->readonlyValue)->toBe(2);
});

it('fingerprints raw state without invoking property hooks', function () {
    $entry = new HookedFingerprintForTest();

    GeneratedEntryResolverFingerprint::objects([$entry]);

    expect($entry->reads)->toBe(0);
});

it('generates one resolver with constructor, property, lazy, no-constructor and setup parity', function () {
    $parameterResolver = new FixedResolverForTest();
    $parameters = new ParametersResolver($parameterResolver);
    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $parameterGenerators->register(FixedResolverForTest::class, new FixedGeneratorForTest());

    $registry = new AttributeHandlerRegistry();
    $registry->add(new SkipConstructorHandlerForTest());
    $registry->add(new LazyHandlerForTest());
    $registry->add(new ValueHandlerForTest());
    $registry->add(new SetUpRunner(new CallableInvokerForTest($parameters)));
    $attributes = new AttributeProcessor($registry);

    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $writer = new GeneratedEntryResolverWriter();
    $loader = new GeneratedEntryResolverLoader();
    $proxy = new ProxyFactoryForTest();
    $file = sys_get_temp_dir() . '/componenta-di-test-' . bin2hex(random_bytes(6)) . '.php';

    try {
        $writer->write($file, $generator->generate([
            EagerEntryForTest::class,
            NoConstructorEntryForTest::class,
            PrivateNoConstructorEntryForTest::class,
            PrivateConstructorEntryForTest::class,
            LazyEntryForTest::class,
            SetUpEntryForTest::class,
        ], 'Componenta\\DI\\Tests\\Generated'));

        $resolver = $loader->load(
            $file,
            $parameters->resolverList,
            $attributes->registry->handlers,
            $proxy,
        );

        expect($resolver)->toBeInstanceOf(EntryResolverInterface::class);

        $eager = $resolver->resolve(EagerEntryForTest::class);
        $noConstructor = $resolver->resolve(NoConstructorEntryForTest::class);
        $privateNoConstructor = $resolver->resolve(PrivateNoConstructorEntryForTest::class);
        expect(fn() => $resolver->resolve(PrivateConstructorEntryForTest::class))
            ->toThrow(ResolutionException::class);
        $lazy = $resolver->resolve(LazyEntryForTest::class);
        $setUp = $resolver->resolve(SetUpEntryForTest::class);

        expect($eager->number)->toBe(7)
            ->and($eager->property)->toBe(42)
            ->and($eager->writes)->toBe(1)
            ->and($noConstructor->property)->toBe(42)
            ->and($privateNoConstructor->value)->toBe(82)
            ->and($lazy->number)->toBe(7)
            ->and($lazy->property)->toBe(9)
            ->and($proxy->lazyCalls)->toBe(1)
            ->and($setUp->setupValue)->toBe(7)
            ->and($loader->load($file, [], $attributes->registry->handlers, $proxy))->toBeNull();

        $reflectionProxy = new ProxyFactoryForTest();
        $reflection = new ReflectionResolver(
            new InstanceCreator($parameters),
            $attributes,
            $reflectionProxy,
        );

        $anonymousEntry = new class () {};

        expect($reflection->can(\Closure::class))->toBeFalse()
            ->and($reflection->can($anonymousEntry::class))->toBeFalse()
            ->and(fn() => $generator->generate(
                [$anonymousEntry::class],
                'Componenta\\DI\\Tests\\AnonymousGenerated',
            ))->toThrow(\InvalidArgumentException::class);

        expect($reflection->resolve(EagerEntryForTest::class)->property)->toBe(42)
            ->and($reflection->resolve(NoConstructorEntryForTest::class)->property)->toBe(42)
            ->and($reflection->can(PrivateNoConstructorEntryForTest::class))->toBeTrue()
            ->and($reflection->resolve(PrivateNoConstructorEntryForTest::class)->value)->toBe(82)
            ->and(fn() => $reflection->resolve(PrivateConstructorEntryForTest::class))->toThrow(ResolutionException::class)
            ->and($reflection->resolve(LazyEntryForTest::class)->property)->toBe(9)
            ->and($reflectionProxy->lazyCalls)->toBe(1)
            ->and($reflection->resolve(SetUpEntryForTest::class)->setupValue)->toBe(7);
    } finally {
        @unlink($file);
    }
});

it('uses native constructor defaults for the empty-context generated fast path', function () {
    $parameters = new ParametersResolver(
        new ArrayResolver(),
        new DefaultValueResolver(),
    );
    $handlers = new AttributeHandlerRegistry();
    $attributes = new AttributeProcessor($handlers);
    $parameterGenerators = DefaultParameterResolverCodeGenerators::create();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $parameters,
            $parameterGenerators,
        ),
        $attributes,
        new AttributeCodeGenerator(),
    );

    $optionalFactory = $factory->generate(
        OptionalDefaultsEntryForTest::class,
        'createOptionalDefaults',
    );
    $noArgumentsFactory = $factory->generate(
        NoArgumentsEntryForTest::class,
        'createNoArguments',
    );

    expect($optionalFactory->code)->toContain('if ($parameters === [])')
        ->and($noArgumentsFactory->code)->not->toContain(ObjectCreationContext::class);

    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $writer = new GeneratedEntryResolverWriter();
    $loader = new GeneratedEntryResolverLoader();
    $proxy = new ProxyFactoryForTest();
    $file = sys_get_temp_dir() . '/componenta-di-fast-' . bin2hex(random_bytes(6)) . '.php';

    try {
        $writer->write($file, $generator->generate([
            OptionalDefaultsEntryForTest::class,
            NoArgumentsEntryForTest::class,
        ], 'Componenta\DI\Tests\GeneratedFast'));

        $resolver = $loader->load(
            $file,
            $parameters->resolverList,
            [],
            $proxy,
        );

        expect($resolver)->toBeInstanceOf(EntryResolverInterface::class);

        $defaults = $resolver->resolve(OptionalDefaultsEntryForTest::class);
        $override = $resolver->resolve(
            OptionalDefaultsEntryForTest::class,
            ['number' => 21],
        );

        expect($defaults->number)->toBe(13)
            ->and($defaults->name)->toBe('default')
            ->and($override->number)->toBe(21)
            ->and($resolver->resolve(NoArgumentsEntryForTest::class))
                ->toBeInstanceOf(NoArgumentsEntryForTest::class);
    } finally {
        @unlink($file);
    }
});

it('validates resolver result tuples in runtime and generated fallback paths', function () {
    $parameter = (new ReflectionMethod(RequiredNumberEntryForTest::class, '__construct'))->getParameters()[0];

    foreach ([new WrongPositionResolverForTest(), new WrongValueResolverForTest(), new MalformedResolverForTest()] as $resolver) {
        $parameters = new ParametersResolver($resolver);

        expect(fn() => $parameters->resolveParameter(
            $parameters->target($parameter),
            new ParameterResolutionContext(),
        ))->toThrow(ResolutionException::class);
    }

    $parameters = new ParametersResolver(new WrongPositionResolverForTest());
    $attributes = new AttributeProcessor(new AttributeHandlerRegistry());
    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $file = sys_get_temp_dir() . '/componenta-di-result-' . bin2hex(random_bytes(6)) . '.php';

    try {
        (new GeneratedEntryResolverWriter())->write(
            $file,
            $generator->generate(
                [RequiredNumberEntryForTest::class],
                'Componenta\DI\Tests\GeneratedResultValidation',
            ),
        );
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            $parameters->resolverList,
            [],
            new ProxyFactoryForTest(),
        );

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class)
            ->and(fn() => $generated->resolve(RequiredNumberEntryForTest::class))
            ->toThrow(ResolutionException::class);
    } finally {
        @unlink($file);
    }
});

it('fingerprints inherited private state and portable anonymous implementations', function () {
    expect(GeneratedEntryResolverFingerprint::objects([new ChildFingerprintStateForTest(1)]))
        ->not->toBe(GeneratedEntryResolverFingerprint::objects([new ChildFingerprintStateForTest(2)]));

    $fileA = sys_get_temp_dir() . '/componenta-di-anonymous-a-' . bin2hex(random_bytes(4)) . '.php';
    $fileB = sys_get_temp_dir() . '/componenta-di-anonymous-b-' . bin2hex(random_bytes(4)) . '.php';
    $source = <<<'PHP'
<?php
return new class(5) {
    public function __construct(private int $value) {}
};
PHP;

    try {
        file_put_contents($fileA, $source);
        file_put_contents($fileB, $source);
        $a = require $fileA;
        $b = require $fileB;

        expect(GeneratedEntryResolverFingerprint::objectTypes([$a]))
            ->toBe(GeneratedEntryResolverFingerprint::objectTypes([$b]))
            ->and(GeneratedEntryResolverFingerprint::objects([$a]))
            ->toBe(GeneratedEntryResolverFingerprint::objects([$b]));
    } finally {
        @unlink($fileA);
        @unlink($fileB);
    }
});

it('retries failed lazy and proxy lifecycle attempts with fresh mutable context', function () {
    $parameters = new ParametersResolver(new FixedResolverForTest());
    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $parameterGenerators->register(FixedResolverForTest::class, new FixedGeneratorForTest());
    $nativeProxy = new NativeProxyFactoryForTest();

    $makeProcessor = static function (): array {
        $retry = new RetryValueHandlerForTest();
        $registry = new AttributeHandlerRegistry();
        $registry->add(new ProxyHandlerForTest());
        $registry->add(new LazyHandlerForTest());
        $registry->add($retry);

        return [new AttributeProcessor($registry), $retry];
    };

    [$reflectionAttributes, $reflectionRetry] = $makeProcessor();
    $reflection = new ReflectionResolver(
        new InstanceCreator($parameters),
        $reflectionAttributes,
        $nativeProxy,
    );

    $lazy = $reflection->resolve(RetryLazyEntryForTest::class);
    expect(fn() => $lazy->property)->toThrow(\RuntimeException::class)
        ->and($lazy->property)->toBe(77)
        ->and($reflectionRetry->attemptsFor(RetryLazyEntryForTest::class))->toBe(2);

    $proxy = $reflection->resolve(RetryProxyEntryForTest::class);
    expect(fn() => $proxy->property)->toThrow(\RuntimeException::class)
        ->and($proxy->property)->toBe(78)
        ->and($reflectionRetry->attemptsFor(RetryProxyEntryForTest::class))->toBe(2);

    [$generatedAttributes, $generatedRetry] = $makeProcessor();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $generatedAttributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $generatedAttributes, $parameterGenerators);
    $file = sys_get_temp_dir() . '/componenta-di-retry-' . bin2hex(random_bytes(6)) . '.php';

    try {
        (new GeneratedEntryResolverWriter())->write($file, $generator->generate([
            RetryLazyEntryForTest::class,
            RetryProxyEntryForTest::class,
        ], 'Componenta\DI\Tests\GeneratedRetry'));
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            $parameters->resolverList,
            $generatedAttributes->registry->handlers,
            $nativeProxy,
        );

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class);
        $generatedLazy = $generated->resolve(RetryLazyEntryForTest::class);
        expect(fn() => $generatedLazy->property)->toThrow(\RuntimeException::class)
            ->and($generatedLazy->property)->toBe(77)
            ->and($generatedRetry->attemptsFor(RetryLazyEntryForTest::class))->toBe(2);
        $generatedProxy = $generated->resolve(RetryProxyEntryForTest::class);
        expect(fn() => $generatedProxy->property)->toThrow(\RuntimeException::class)
            ->and($generatedProxy->property)->toBe(78)
            ->and($generatedRetry->attemptsFor(RetryProxyEntryForTest::class))->toBe(2);
    } finally {
        @unlink($file);
    }
});

it('rejects applicability methods that mutate extension state during compilation', function () {
    $parameters = new ParametersResolver(new CountingResolverForTest());
    $attributes = new AttributeProcessor(new AttributeHandlerRegistry());
    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);

    expect(fn() => $generator->generate(
        [EagerEntryForTest::class],
        'Componenta\DI\Tests\ImpureApplicability',
    ))->toThrow(\LogicException::class);
});

it('invalidates generated resolvers when an attribute implementation source changes', function () {
    $suffix = bin2hex(random_bytes(6));
    $releaseFingerprint = 'release-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Dynamic' . $suffix;
    $attributeClass = $namespace . '\\DynamicValue';
    $entryClass = $namespace . '\\DynamicEntry';
    $attributeFile = sys_get_temp_dir() . '/componenta-di-attribute-' . $suffix . '.php';
    $entryFile = sys_get_temp_dir() . '/componenta-di-entry-' . $suffix . '.php';
    $generatedFile = sys_get_temp_dir() . '/componenta-di-generated-' . $suffix . '.php';

    try {
        file_put_contents($attributeFile, sprintf(<<<'PHP'
<?php
namespace %s;
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class DynamicValue
{
    public function __construct(public int $value) {}
}
PHP, $namespace));
        file_put_contents($entryFile, sprintf(<<<'PHP'
<?php
namespace %s;
final class DynamicEntry
{
    #[DynamicValue(123)]
    public int $value;
}
PHP, $namespace));
        require $attributeFile;
        require $entryFile;

        $registry = new AttributeHandlerRegistry();
        $registry->add(new DynamicAttributeHandlerForTest($attributeClass));
        $attributes = new AttributeProcessor($registry);
        $parameters = new ParametersResolver();
        $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
        $factory = new FactoryCodeGenerator(
            new ParameterCodeGenerator($parameters, $parameterGenerators),
            $attributes,
            new AttributeCodeGenerator(),
        );
        $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
        $writer = new GeneratedEntryResolverWriter();
        $loader = new GeneratedEntryResolverLoader();
        $writer->write($generatedFile, $generator->generate(
            [$entryClass],
            'Componenta\DI\Tests\GeneratedDynamic' . $suffix,
            $releaseFingerprint,
        ));

        $resolver = $loader->load(
            $generatedFile,
            [],
            $attributes->registry->handlers,
            new ProxyFactoryForTest(),
        );
        expect($resolver)->toBeInstanceOf(EntryResolverInterface::class)
            ->and($resolver->resolve($entryClass)->value)->toBe(123);

        file_put_contents($attributeFile, "\n// source changed\n", FILE_APPEND);
        expect($loader->load(
            $generatedFile,
            [],
            $attributes->registry->handlers,
            new ProxyFactoryForTest(),
            $releaseFingerprint,
        ))->toBeInstanceOf(EntryResolverInterface::class);

        expect($loader->load(
            $generatedFile,
            [],
            $attributes->registry->handlers,
            new ProxyFactoryForTest(),
            'another-release',
        ))->toBeNull();

        expect($loader->load(
            $generatedFile,
            [],
            $attributes->registry->handlers,
            new ProxyFactoryForTest(),
        ))->toBeNull();
    } finally {
        @unlink($attributeFile);
        @unlink($entryFile);
        @unlink($generatedFile);
    }
});

it('preserves the previous generated file when syntax validation fails', function () {
    $writer = new GeneratedEntryResolverWriter();
    $file = sys_get_temp_dir() . '/componenta-di-writer-' . bin2hex(random_bytes(6)) . '.php';

    try {
        $writer->write($file, "<?php\nreturn 'stable';\n");
        $previous = file_get_contents($file);

        expect(fn() => $writer->write($file, "<?php\ninvalid php\n"))
            ->toThrow(\RuntimeException::class)
            ->and(file_get_contents($file))->toBe($previous);
    } finally {
        @unlink($file);
    }
});


it('keeps object-valued defaults fresh in reflection and generated non-fast paths', function () {
    $parameters = new ParametersResolver(
        new NullResolverForTest(),
        new DefaultValueResolver(),
    );
    $attributes = new AttributeProcessor(new AttributeHandlerRegistry());
    $proxy = new ProxyFactoryForTest();
    $reflection = new ReflectionResolver(new InstanceCreator($parameters), $attributes, $proxy);

    expect($reflection->resolve(ObjectDefaultEntryForTest::class)->value)
        ->not->toBe($reflection->resolve(ObjectDefaultEntryForTest::class)->value);

    $parameterGenerators = DefaultParameterResolverCodeGenerators::create();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $file = sys_get_temp_dir() . '/componenta-di-object-default-' . bin2hex(random_bytes(6)) . '.php';

    try {
        CountedDefaultForTest::$instances = 0;
        $code = $generator->generate(
            [ObjectDefaultEntryForTest::class],
            'Componenta\\DI\\Tests\\GeneratedObjectDefault',
        );

        expect(CountedDefaultForTest::$instances)->toBe(0);
        (new GeneratedEntryResolverWriter())->write($file, $code);
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            $parameters->resolverList,
            [],
            $proxy,
        );

        $first = $generated->resolve(ObjectDefaultEntryForTest::class);
        $second = $generated->resolve(ObjectDefaultEntryForTest::class);

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class)
            ->and($first->value)->not->toBe($second->value)
            ->and(CountedDefaultForTest::$instances)->toBe(2);
    } finally {
        @unlink($file);
    }
});

it('does not instantiate runtime-only attributes while compiling generated factories', function () {
    SideEffectForTest::$instances = 0;
    $handler = new SideEffectHandlerForTest();
    $registry = new AttributeHandlerRegistry();
    $registry->add($handler);
    $attributes = new AttributeProcessor($registry);
    $parameters = new ParametersResolver();
    $generators = new ParameterResolverCodeGeneratorRegistry();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $generators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator(
        $factory,
        $parameters,
        $attributes,
        $generators,
    );
    $file = sys_get_temp_dir() . '/componenta-di-runtime-attribute-' . bin2hex(random_bytes(6)) . '.php';

    try {
        $code = $generator->generate(
            [WritableSideEffectEntryForTest::class],
            'Componenta\\DI\\Tests\\GeneratedRuntimeAttribute',
        );
        expect(SideEffectForTest::$instances)->toBe(0);

        (new GeneratedEntryResolverWriter())->write($file, $code);
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            [],
            $attributes->registry->handlers,
            new ProxyFactoryForTest(),
        );
        $entry = $generated?->resolve(WritableSideEffectEntryForTest::class);

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class)
            ->and($entry?->value)->toBe(99)
            ->and(SideEffectForTest::$instances)->toBe(1);
    } finally {
        @unlink($file);
    }
});

it('keeps compile metadata minimal and uses one runtime attribute fallback', function () {
    expect(trait_exists('Componenta\\DI\\Compile\\Attribute\\RuntimeAttributeHandlerCode'))
        ->toBeFalse()
        ->and(is_subclass_of(
            \Componenta\DI\Resolver\Attribute\Handler\InitHandler::class,
            CompilableAttributeHandlerInterface::class,
        ))->toBeFalse();

    $constructorNames = static fn(string $class): array => array_map(
        static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod($class, '__construct'))->getParameters(),
    );

    expect($constructorNames(GeneratedAttributeCode::class))->not->toContain('usesHandler')
        ->and($constructorNames(\Componenta\DI\Compile\Parameter\GeneratedParameterCode::class))
        ->not->toContain('terminal')
        ->and($constructorNames(\Componenta\DI\Resolver\Attribute\AttributeInvocation::class))
        ->not->toContain('phase')
        ->and($constructorNames(ParameterCodeGenerationContext::class))
        ->not->toContain('containerExpression');
});

it('processes inherited private attributed properties in reflection and generated paths', function () {
    $parameters = new ParametersResolver();
    $registry = new AttributeHandlerRegistry();
    $registry->add(new ValueHandlerForTest());
    $attributes = new AttributeProcessor($registry);
    $proxy = new ProxyFactoryForTest();
    $reflection = new ReflectionResolver(new InstanceCreator($parameters), $attributes, $proxy);

    expect($reflection->resolve(ChildPrivateAttributeEntryForTest::class)->value())->toBe(66);

    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $file = sys_get_temp_dir() . '/componenta-di-parent-private-' . bin2hex(random_bytes(6)) . '.php';

    try {
        (new GeneratedEntryResolverWriter())->write(
            $file,
            $generator->generate(
                [ChildPrivateAttributeEntryForTest::class],
                'Componenta\\DI\\Tests\\GeneratedParentPrivate',
            ),
        );
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            [],
            $attributes->registry->handlers,
            $proxy,
        );

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class)
            ->and($generated->resolve(ChildPrivateAttributeEntryForTest::class)->value())->toBe(66);
    } finally {
        @unlink($file);
    }
});

it('does not share mutable attribute objects between executions or compilations', function () {
    $parameters = new ParametersResolver();
    $registry = new AttributeHandlerRegistry();
    $registry->add(new MutatingAttributeHandlerForTest());
    $attributes = new AttributeProcessor($registry);
    $reflection = new ReflectionResolver(
        new InstanceCreator($parameters),
        $attributes,
        new ProxyFactoryForTest(),
    );

    expect($reflection->resolve(MutableAttributeEntryForTest::class)->value)->toBe(31)
        ->and($reflection->resolve(MutableAttributeEntryForTest::class)->value)->toBe(31);

    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, new ParameterResolverCodeGeneratorRegistry()),
        $attributes,
        new AttributeCodeGenerator(),
    );

    expect($factory->generate(MutableAttributeEntryForTest::class, 'createMutableA')->code)
        ->toContain(', 31);')
        ->and($factory->generate(MutableAttributeEntryForTest::class, 'createMutableB')->code)
        ->toContain(', 31);');
});

it('wraps property-hook and constructor engine errors consistently', function () {
    $parameters = new ParametersResolver();
    $registry = new AttributeHandlerRegistry();
    $registry->add(new ProxyHandlerForTest());
    $registry->add(new LazyHandlerForTest());
    $registry->add(new ValueHandlerForTest());
    $attributes = new AttributeProcessor($registry);
    $nativeProxy = new NativeProxyFactoryForTest();
    $reflection = new ReflectionResolver(new InstanceCreator($parameters), $attributes, $nativeProxy);

    expect(fn() => $reflection->resolve(ThrowingHookEntryForTest::class))
        ->toThrow(ResolutionException::class)
        ->and(fn() => $reflection->resolve(ThrowingConstructorEntryForTest::class))
        ->toThrow(ResolutionException::class);

    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $file = sys_get_temp_dir() . '/componenta-di-errors-' . bin2hex(random_bytes(6)) . '.php';

    try {
        (new GeneratedEntryResolverWriter())->write($file, $generator->generate([
            ThrowingHookEntryForTest::class,
            ThrowingConstructorEntryForTest::class,
            ThrowingLazyConstructorEntryForTest::class,
            ThrowingProxyConstructorEntryForTest::class,
        ], 'Componenta\\DI\\Tests\\GeneratedErrors'));
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            [],
            $attributes->registry->handlers,
            $nativeProxy,
        );

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class)
            ->and(fn() => $generated->resolve(ThrowingHookEntryForTest::class))
            ->toThrow(ResolutionException::class)
            ->and(fn() => $generated->resolve(ThrowingConstructorEntryForTest::class))
            ->toThrow(ResolutionException::class);

        $lazy = $generated->resolve(ThrowingLazyConstructorEntryForTest::class);
        $proxy = $generated->resolve(ThrowingProxyConstructorEntryForTest::class);

        expect(fn() => $lazy->touch)->toThrow(ResolutionException::class)
            ->and(fn() => $proxy->touch)->toThrow(ResolutionException::class);
    } finally {
        @unlink($file);
    }
});

it('defers variadic rejection until constructor execution and permits NoConstructor', function () {
    $parameters = new ParametersResolver();
    $registry = new AttributeHandlerRegistry();
    $registry->add(new SkipConstructorHandlerForTest());
    $attributes = new AttributeProcessor($registry);
    $proxy = new ProxyFactoryForTest();
    $reflectionWithoutHandler = new ReflectionResolver(
        new InstanceCreator($parameters),
        new AttributeProcessor(new AttributeHandlerRegistry()),
        $proxy,
    );

    expect(fn() => $reflectionWithoutHandler->resolve(VariadicEntryForTest::class))
        ->toThrow(ResolutionException::class);

    $parameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameters, $parameterGenerators),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $generator = new GeneratedEntryResolverGenerator($factory, $parameters, $attributes, $parameterGenerators);
    $file = sys_get_temp_dir() . '/componenta-di-variadic-' . bin2hex(random_bytes(6)) . '.php';

    try {
        (new GeneratedEntryResolverWriter())->write($file, $generator->generate([
            VariadicEntryForTest::class,
            NoConstructorVariadicEntryForTest::class,
            ByReferenceEntryForTest::class,
            NoConstructorByReferenceEntryForTest::class,
        ], 'Componenta\\DI\\Tests\\GeneratedVariadic'));
        $generated = (new GeneratedEntryResolverLoader())->load(
            $file,
            [],
            $attributes->registry->handlers,
            $proxy,
        );

        expect($generated)->toBeInstanceOf(EntryResolverInterface::class)
            ->and(fn() => $generated->resolve(VariadicEntryForTest::class))
            ->toThrow(ResolutionException::class)
            ->and($generated->resolve(NoConstructorVariadicEntryForTest::class)->value)
            ->toBe(73)
            ->and(fn() => $reflectionWithoutHandler->resolve(ByReferenceEntryForTest::class, ['value' => 1]))
            ->toThrow(ResolutionException::class)
            ->and(fn() => $generated->resolve(ByReferenceEntryForTest::class, ['value' => 1]))
            ->toThrow(ResolutionException::class)
            ->and($generated->resolve(NoConstructorByReferenceEntryForTest::class)->value)
            ->toBe(74);
    } finally {
        @unlink($file);
    }
});

it('rejects duplicate generated helper method names', function () {
    $code = new FactoryCode();
    $code->addMethod('same', 'private function same(): void {}');

    expect(fn() => $code->addMethod('same', 'private function same(): void {}'))
        ->toThrow(\LogicException::class);
});

it('keeps protected service metadata and extension registries canonical', function () {
    expect(ProtectedServiceIds::contains(Config::class))->toBeTrue()
        ->and(ProtectedServiceIds::bootstrapType(Config::class))->toBe(Config::class)
        ->and(ProtectedServiceIds::contains(ProxyFactoryInterface::class))->toBeTrue()
        ->and(ProtectedServiceIds::bootstrapType(ProxyFactoryInterface::class))->toBeNull();

    $resolver = new NullResolverForTest();
    expect(fn() => new ParametersResolver($resolver, $resolver))
        ->toThrow(\InvalidArgumentException::class);

    $handler = new ValueHandlerForTest();
    $handlers = new AttributeHandlerRegistry();
    $handlers->add($handler);
    expect(fn() => $handlers->add($handler))
        ->toThrow(\InvalidArgumentException::class);

    $mutable = new MutableMetadataHandlerForTest();
    $mutableHandlers = new AttributeHandlerRegistry();
    expect(fn() => $mutableHandlers->add($mutable))
        ->toThrow(\InvalidArgumentException::class);
});

it('rejects compile-time fatal generated PHP atomically', function () {
    $writer = new GeneratedEntryResolverWriter();
    $file = sys_get_temp_dir() . '/componenta-di-compile-fatal-' . bin2hex(random_bytes(6)) . '.php';

    try {
        $writer->write($file, "<?php\nreturn 'stable';\n");
        $before = file_get_contents($file);

        expect(fn() => $writer->write(
            $file,
            "<?php\nfinal class DuplicateGeneratedMethodForTest { public function run(): void {} public function run(): void {} }\n",
        ))->toThrow(\RuntimeException::class)
            ->and(file_get_contents($file))->toBe($before);
    } finally {
        @unlink($file);
    }
});

it('rejects structural extension-pipeline mutation during applicability and generation', function () {
    $parameter = (new ReflectionMethod(EagerEntryForTest::class, '__construct'))->getParameters()[0];
    $resolver = new MutatingResolverForTest();
    $parameters = new ParametersResolver($resolver);
    $resolver->chain = $parameters;

    expect(fn() => $parameters->resolverSlotsFor($parameters->target($parameter)))
        ->toThrow(\LogicException::class);

    $handler = new MutatingHandlerRegistryForTest();
    $handlers = new AttributeHandlerRegistry();
    $handler->registry = $handlers;
    $handlers->add($handler);
    $attributes = new AttributeProcessor($handlers);

    expect(fn() => $attributes->invocations(new ReflectionClass(EagerEntryForTest::class)))
        ->toThrow(\LogicException::class);

    $runtimeParameters = new ParametersResolver(new FixedResolverForTest());
    $emptyAttributes = new AttributeProcessor(new AttributeHandlerRegistry());
    $generators = new ParameterResolverCodeGeneratorRegistry();
    $generator = new MutatingGeneratorRegistryForTest();
    $generator->registry = $generators;
    $generators->register(FixedResolverForTest::class, $generator);
    $factory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($runtimeParameters, $generators),
        $emptyAttributes,
        new AttributeCodeGenerator(),
    );
    $entryGenerator = new GeneratedEntryResolverGenerator(
        $factory,
        $runtimeParameters,
        $emptyAttributes,
        $generators,
    );

    expect(fn() => $entryGenerator->generate(
        [EagerEntryForTest::class],
        'Componenta\DI\Tests\MutatingGeneratorRegistry',
    ))->toThrow(\LogicException::class);
});
