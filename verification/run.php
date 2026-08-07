<?php

declare(strict_types=1);

namespace Componenta\Stdlib {
    final class PriorityList implements \IteratorAggregate, \Countable
    {
        private array $items = [];
        private int $order = 0;

        public function insert(object $value, int $priority = 0): void
        {
            $this->items[] = [$value, $priority, $this->order++];
        }

        public function getIterator(): \Traversable
        {
            $items = $this->items;
            usort($items, static fn(array $a, array $b): int => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
            foreach ($items as [$value]) {
                yield $value;
            }
        }

        public function count(): int
        {
            return count($this->items);
        }

        public function __clone() {}
    }
}

namespace Componenta\Reflection {
    final class ReflectionType
    {
        public static function match(?\ReflectionType $type, mixed $value): bool
        {
            if ($type === null) {
                return true;
            }

            if ($value === null && $type->allowsNull()) {
                return true;
            }

            if ($type instanceof \ReflectionNamedType) {
                $name = $type->getName();

                if (!$type->isBuiltin()) {
                    return is_object($value) && $value instanceof $name;
                }

                return match ($name) {
                    'mixed' => true,
                    'null' => $value === null,
                    'true' => $value === true,
                    'false' => $value === false,
                    'bool' => is_bool($value),
                    'int' => is_int($value),
                    'float' => is_float($value),
                    'string' => is_string($value),
                    'array' => is_array($value),
                    'object' => is_object($value),
                    'callable' => is_callable($value),
                    'iterable' => is_iterable($value),
                    default => false,
                };
            }

            if ($type instanceof \ReflectionUnionType) {
                foreach ($type->getTypes() as $nested) {
                    if (self::match($nested, $value)) {
                        return true;
                    }
                }

                return false;
            }

            if ($type instanceof \ReflectionIntersectionType) {
                foreach ($type->getTypes() as $nested) {
                    if (!self::match($nested, $value)) {
                        return false;
                    }
                }

                return true;
            }

            return false;
        }
    }

    final class Reflection
    {
        public static function class(string $class): ?\ReflectionClass
        {
            return class_exists($class) ? new \ReflectionClass($class) : null;
        }

        public static function getFirstMetadata(\ReflectionProperty|\ReflectionParameter $target, string $attributeClass): ?object
        {
            return ($target->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
        }

        public static function getMetadata(\ReflectionProperty|\ReflectionParameter $target, ?string $attributeClass = null): ?array
        {
            $attributes = $attributeClass === null
                ? $target->getAttributes()
                : $target->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);

            return $attributes === []
                ? null
                : array_map(static fn(\ReflectionAttribute $attribute): object => $attribute->newInstance(), $attributes);
        }
    }
}

namespace Componenta\DI\Exception {
    class NotFoundException extends \RuntimeException
    {
        public static function forService(string $id): self
        {
            return new self($id);
        }
    }

    class InvalidConfigurationException extends \RuntimeException
    {
        public static function forInvalidDefinition(object $definition): self
        {
            return new self($definition::class);
        }
    }

    class ResolutionException extends \RuntimeException
    {
        public static function forService(string $id, ?\Throwable $previous = null): self
        {
            return new self('service ' . $id, previous: $previous);
        }

        public static function forMissingService(string $id): self
        {
            return new self('missing service ' . $id);
        }

        public static function forParameter(
            \ReflectionParameter $parameter,
            ?string $reason = null,
            ?\Throwable $previous = null,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): self {
            return new self($reason ?? 'unresolved ' . $parameter->getName(), previous: $previous);
        }

        public static function forProperty(
            \ReflectionProperty $property,
            ?string $reason = null,
            ?\Throwable $previous = null,
        ): self {
            return new self($reason ?? 'unresolved ' . $property->getName(), previous: $previous);
        }
    }
}

namespace Componenta\DI\Definition {
    interface DefinitionInterface {}
}

namespace Componenta\DI {
    interface ProxyFactoryInterface
    {
        public function makeLazy(string $class, callable $initializer): object;
        public function makeProxy(string $class, callable $initializer): object;
    }

    interface CallableInvokerInterface
    {
        public function call(callable|string|array $callable, array $params = []): mixed;
    }
}

namespace Componenta\DI\Resolver\Entry {
    interface EntryResolverInterface
    {
        public function can(string $id): bool;
        public function resolve(string $id, array $context = []): mixed;
    }

    interface DefinitionAwareResolverInterface extends EntryResolverInterface
    {
        public function setDefinition(string $id, \Componenta\DI\Definition\DefinitionInterface $definition): void;
        public function supportsDefinition(\Componenta\DI\Definition\DefinitionInterface $definition): bool;
    }
}

namespace Componenta\DI\Resolver\Entry\SetUp {
    interface SetUpValueUnwrapperInterface
    {
        public function supports(mixed $value): bool;
        public function unwrap(mixed $value, string $key): mixed;
    }
}

namespace Componenta\DI\Attribute {
    #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
    final readonly class SetUp
    {
        public function __construct(public string $method, public array $params = []) {}
    }
}

namespace {
    function verify(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                "%s\nExpected: %s\nActual:   %s",
                $message,
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    function throws(callable $operation, string $exception, string $message): void
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            if ($error instanceof $exception) {
                return;
            }

            throw new \RuntimeException($message . ': got ' . $error::class, previous: $error);
        }

        throw new \RuntimeException($message . ': no exception was thrown');
    }

    $root = dirname(__DIR__) . '/src';
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Componenta\\DI\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

namespace Verification {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    final readonly class SkipConstructor {}

    #[\Attribute(\Attribute::TARGET_CLASS)]
    final readonly class LazyEntry {}

    #[\Attribute(\Attribute::TARGET_CLASS)]
    final readonly class ProxyEntry {}

    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    final readonly class Value
    {
        public function __construct(public int $value) {}
    }

    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    final class SideEffect
    {
        public static int $instances = 0;

        public function __construct()
        {
            ++self::$instances;
        }
    }

    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    final readonly class RetryValue
    {
        public function __construct(public int $value) {}
    }

    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    final class MutableValue
    {
        public function __construct(public int $value) {}
    }

    #[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
    class OrderedBaseAttribute
    {
        public function __construct(public string $value) {}
    }

    #[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
    final class OrderedChildAttribute extends OrderedBaseAttribute {}

    final class OrderedAttributeEntry
    {
        public function __construct(
            #[OrderedChildAttribute('child')]
            #[OrderedBaseAttribute('base')]
            public string $value = '',
        ) {}
    }

    #[\Attribute(\Attribute::TARGET_ALL)]
    final readonly class UnsupportedMetadata {}

    #[UnsupportedMetadata]
    #[LateBoundMetadata]
    final class AttributeFingerprintEntry
    {
        #[UnsupportedMetadata]
        public string $value = '';

        #[UnsupportedMetadata]
        public function configure(#[UnsupportedMetadata] string $name = 'default'): void {}
    }

    interface Left {}
    interface Right {}

    final class Both implements Left, Right {}
    final class LeftOnly implements Left {}

    final class DnfEntry
    {
        public function __construct(public (Left&Right)|\stdClass $value) {}
    }


    class ScopedParent {}

    final class ScopedChild extends ScopedParent
    {
        public function __construct(
            public self $selfValue,
            public parent $parentValue,
        ) {}
    }

    final class FixedResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public int $resolveCalls = 0;

        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            ++$this->resolveCalls;
            return [$target->position, $target->name === 'number' ? 7 : null];
        }
    }


    final class NullResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            return null;
        }
    }

    final class CountingResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public int $supportsCalls = 0;

        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            ++$this->supportsCalls;
            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            return [$target->position, 7];
        }
    }

    final class WrongPositionResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            return [$target->position + 1, 17];
        }
    }

    final class WrongValueResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            return [$target->position, 'not-an-int'];
        }
    }

    final class MalformedResultResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            return [0 => $target->position, 2 => 17];
        }
    }

    final class MutatingResolver implements \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
    {
        public ?\Componenta\DI\Resolver\Parameter\ParametersResolver $chain = null;
        private bool $mutated = false;

        public function supports(\Componenta\DI\Resolver\Target\ParameterTarget $target): bool
        {
            if (!$this->mutated && $this->chain !== null) {
                $this->mutated = true;
                $this->chain->add(new FixedResolver());
            }

            return true;
        }

        public function resolveParameter(
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
        ): ?array {
            return [$target->position, 7];
        }
    }

    final class FixedGenerator implements \Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface
    {
        public function generate(
            \Componenta\DI\Resolver\Parameter\ParameterResolverInterface $resolver,
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext $context,
        ): \Componenta\DI\Compile\Parameter\GeneratedResolverCode {
            return \Componenta\DI\Compile\Parameter\GeneratedResolverCode::terminal(
                sprintf(
                    "%s = %s->name === 'number' ? 7 : null;\ngoto %s;",
                    $context->argumentVariable,
                    $context->targetExpression,
                    $context->resolvedLabel,
                ),
                usesTarget: true,
            );
        }
    }

    final class SkipConstructorHandler implements \Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface
    {
        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::BeforeInstantiation;
        }

        public int $priority { get => 300; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionClass && $attributeClass === SkipConstructor::class;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
            $context->disableConstructor();
        }

        public function generateAttributeCode(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext $context,
        ): \Componenta\DI\Compile\Attribute\GeneratedAttributeCode {
            return new \Componenta\DI\Compile\Attribute\GeneratedAttributeCode(
                $context->creationExpression . '->disableConstructor();',
                disablesConstructor: true,
            );
        }
    }

    final class StrategyHandler implements \Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface
    {
        public function __construct(
            private string $attribute,
            private \Componenta\DI\Resolver\Attribute\CreationStrategy $strategy,
            private int $rank,
        ) {}

        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::BeforeInstantiation;
        }

        public int $priority { get => $this->rank; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionClass && $attributeClass === $this->attribute;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
            $context->selectStrategy($this->strategy);
        }

        public function generateAttributeCode(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext $context,
        ): \Componenta\DI\Compile\Attribute\GeneratedAttributeCode {
            return new \Componenta\DI\Compile\Attribute\GeneratedAttributeCode(sprintf(
                '%s->selectStrategy(\\%s::%s);',
                $context->creationExpression,
                \Componenta\DI\Resolver\Attribute\CreationStrategy::class,
                $this->strategy->name,
            ));
        }
    }

    final class ValueHandler implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
    {
        public int $calls = 0;

        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;
        }

        public int $priority { get => 100; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionProperty && $attributeClass === Value::class;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
            if (!$context->claimProperty($target)) {
                return;
            }

            ++$this->calls;
            $context->writeProperty($target, $attribute->value);
        }
    }

    final class SideEffectHandler implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
    {
        public int $calls = 0;

        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;
        }

        public int $priority { get => 1000; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionProperty && $attributeClass === SideEffect::class;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
            if (!$context->claimProperty($target)) {
                return;
            }

            ++$this->calls;
            $context->writeProperty($target, 99);
        }
    }

    final class RetryValueHandler implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
    {
        /** @var array<string, int> */
        private array $attempts = [];

        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;
        }

        public int $priority { get => 900; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionProperty
                && $attributeClass === RetryValue::class;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
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

        public function attemptsFor(string $class, string $property = 'property'): int
        {
            return $this->attempts[$class . '::$' . $property] ?? 0;
        }
    }

    final class MutatingAttributeHandler implements \Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface
    {
        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;
        }

        public int $priority { get => 500; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionProperty
                && $attributeClass === MutableValue::class;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
            if (!$attribute instanceof MutableValue || !$target instanceof \ReflectionProperty) {
                throw new \LogicException('Unexpected mutable attribute invocation.');
            }

            if ($context->claimProperty($target)) {
                $context->writeProperty($target, $attribute->value);
            }

            ++$attribute->value;
        }

        public function generateAttributeCode(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext $context,
        ): \Componenta\DI\Compile\Attribute\GeneratedAttributeCode {
            if (!$attribute instanceof MutableValue || !$target instanceof \ReflectionProperty) {
                throw new \LogicException('Unexpected mutable attribute compilation.');
            }

            $value = $attribute->value++;

            return new \Componenta\DI\Compile\Attribute\GeneratedAttributeCode(
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

    final class MutatingHandlerRegistryHandler implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
    {
        public ?\Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry $registry = null;

        private bool $mutated = false;

        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;
        }

        public int $priority { get => 1000; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            if (!$this->mutated && $this->registry !== null) {
                $this->mutated = true;
                $this->registry->add(new ValueHandler());
            }

            return false;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {}
    }

    final class MutatingCodeGeneratorRegistryGenerator implements \Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface
    {
        public ?\Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry $registry = null;

        private bool $mutated = false;

        public function generate(
            \Componenta\DI\Resolver\Parameter\ParameterResolverInterface $resolver,
            \Componenta\DI\Resolver\Target\ParameterTarget $target,
            \Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext $context,
        ): \Componenta\DI\Compile\Parameter\GeneratedResolverCode {
            if (!$this->mutated && $this->registry !== null) {
                $this->mutated = true;
                $this->registry->register(FixedResolver::class, $this);
            }

            return \Componenta\DI\Compile\Parameter\GeneratedResolverCode::terminal(
                sprintf('%s = 7; goto %s;', $context->argumentVariable, $context->resolvedLabel),
            );
        }
    }

    final class MutableMetadataHandler implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
    {
        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase = \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;

        public int $priority = 10;

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return false;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {}
    }

    final class DynamicAttributeHandler implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
    {
        public function __construct(private string $attributeClass) {}

        public \Componenta\DI\Resolver\Attribute\AttributePhase $phase {
            get => \Componenta\DI\Resolver\Attribute\AttributePhase::AfterInstantiation;
        }

        public int $priority { get => 100; }

        public function supportsAttribute(string $attributeClass, \Reflector $target): bool
        {
            return $target instanceof \ReflectionProperty
                && $attributeClass === $this->attributeClass;
        }

        public function handle(
            object $attribute,
            \Reflector $target,
            \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
        ): void {
            if ($context->claimProperty($target)) {
                $context->writeProperty($target, $attribute->value);
            }
        }
    }

    #[SkipConstructor]
    final class NoCtorExample
    {
        #[Value(42)]
        public int $value;

        public function __construct()
        {
            throw new \LogicException('must not run');
        }
    }

    #[SkipConstructor]
    final class PrivateNoCtorExample
    {
        public int $value = 82;

        private function __construct()
        {
            throw new \LogicException('private constructor must not run');
        }
    }

    final class PrivateConstructorExample
    {
        private function __construct() {}
    }

    final class EagerExample
    {
        public int $writes = 0;

        #[Value(42)]
        public int $property = 0 {
            set {
                ++$this->writes;
                $this->property = $value;
            }
        }

        public function __construct(public int $number = 7) {}
    }

    #[LazyEntry]
    final class LazyExample
    {
        #[Value(9)]
        public int $property;

        public function __construct(public int $number = 8) {}
    }

    #[ProxyEntry]
    final class ProxyExample
    {
        #[Value(11)]
        public int $property;

        public function __construct(public int $number = 10) {}
    }

    #[LazyEntry]
    final class RetryLazyExample
    {
        #[RetryValue(77)]
        public int $property;

        public function __construct(public int $number = 14) {}
    }

    #[ProxyEntry]
    final class RetryProxyExample
    {
        #[RetryValue(78)]
        public int $property;

        public function __construct(public int $number = 15) {}
    }

    final class RequiredNumberExample
    {
        public function __construct(public int $number) {}
    }

    final class UnwritableExample
    {
        #[SideEffect]
        public static int $staticValue = 1;

        #[SideEffect]
        public readonly int $readonlyValue;

        public function __construct()
        {
            $this->readonlyValue = 2;
        }
    }

    final class CountedDefault
    {
        public static int $instances = 0;

        public function __construct()
        {
            ++self::$instances;
        }
    }

    final class ObjectDefaultExample
    {
        public function __construct(public CountedDefault $value = new CountedDefault()) {}
    }

    final class WritableSideEffectExample
    {
        #[SideEffect]
        public int $value;
    }

    class ParentPrivateAttributeExample
    {
        #[Value(66)]
        private int $inheritedPrivate = 0;

        public function inheritedPrivate(): int
        {
            return $this->inheritedPrivate;
        }
    }

    final class ChildPrivateAttributeExample extends ParentPrivateAttributeExample {}

    final class ThrowingHookExample
    {
        #[Value(1)]
        public int $property {
            set {
                throw new \DomainException('property hook failed');
            }
        }
    }

    final class VariadicExample
    {
        /** @var list<string> */
        public array $items;

        public function __construct(string ...$items)
        {
            $this->items = $items;
        }
    }

    #[SkipConstructor]
    final class NoCtorVariadicExample
    {
        public int $value = 73;

        public function __construct(string ...$items)
        {
            throw new \LogicException('variadic constructor must be skipped');
        }
    }

    final class ByReferenceExample
    {
        public int $value;

        public function __construct(int &$value)
        {
            $this->value = ++$value;
        }
    }

    #[SkipConstructor]
    final class NoCtorByReferenceExample
    {
        public int $value = 74;

        public function __construct(int &$value)
        {
            throw new \LogicException('by-reference constructor must be skipped');
        }
    }

    final class MutableAttributeExample
    {
        #[MutableValue(31)]
        public int $value = 0;
    }

    final class ThrowingConstructorExample
    {
        public function __construct()
        {
            throw new \TypeError('constructor failed');
        }
    }

    #[LazyEntry]
    final class ThrowingLazyConstructorExample
    {
        public int $touch = 1;

        public function __construct()
        {
            throw new \TypeError('lazy constructor failed');
        }
    }

    #[ProxyEntry]
    final class ThrowingProxyConstructorExample
    {
        public int $touch = 1;

        public function __construct()
        {
            throw new \TypeError('proxy constructor failed');
        }
    }

    final class FakeInvoker implements \Componenta\DI\CallableInvokerInterface
    {
        public function __construct(
            private readonly \Componenta\DI\Resolver\Parameter\ParametersResolver $parameters,
        ) {}

        public function call(callable|string|array $callable, array $params = []): mixed
        {
            if (is_array($callable) && count($callable) === 2 && is_object($callable[0])) {
                $method = new \ReflectionMethod($callable[0], (string) $callable[1]);
                $arguments = $this->parameters->resolve($method->getParameters(), $params);

                return $method->invokeArgs($callable[0], $arguments);
            }

            return $callable(...array_values($params));
        }
    }

    #[\Componenta\DI\Attribute\SetUp('boot')]
    final class WithSetup
    {
        public bool $booted = false;
        public int $setupValue = 0;

        public function __construct(public int $number = 12) {}

        public function boot(int $number): void
        {
            $this->booted = true;
            $this->setupValue = $number;
        }
    }

    final class FakeProxyFactory implements \Componenta\DI\ProxyFactoryInterface
    {
        public int $lazyCalls = 0;
        public int $proxyCalls = 0;

        public function makeLazy(string $class, callable $initializer): object
        {
            ++$this->lazyCalls;
            $entry = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $initializer($entry);
            return $entry;
        }

        public function makeProxy(string $class, callable $initializer): object
        {
            ++$this->proxyCalls;
            return $initializer((new \ReflectionClass($class))->newInstanceWithoutConstructor());
        }
    }

    final class NativeProxyFactory implements \Componenta\DI\ProxyFactoryInterface
    {
        public function makeLazy(string $class, callable $initializer): object
        {
            return (new \ReflectionClass($class))->newLazyGhost($initializer);
        }

        public function makeProxy(string $class, callable $initializer): object
        {
            return (new \ReflectionClass($class))->newLazyProxy($initializer);
        }
    }

    class ParentFingerprintState
    {
        public function __construct(private int $secret) {}
    }

    final class ChildFingerprintState extends ParentFingerprintState {}

    final class FingerprintHooks
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

    final class ClosureHolder
    {
        public function __construct(public \Closure $factory) {}
    }

    final class OwnerResolver implements \Componenta\DI\Resolver\Entry\EntryResolverInterface
    {
        public function __construct(private string $id, private mixed $value) {}
        public function can(string $id): bool { return $id === $this->id; }
        public function resolve(string $id, array $context = []): mixed { return $this->value; }
    }

    final class OptionalDefaultsExample
    {
        public function __construct(
            public int $number = 13,
            public string $name = 'default',
        ) {}
    }

    final class NoArgumentsExample
    {
        public function __construct() {}
    }
}

namespace {
    use Componenta\DI\Compile\Attribute\AttributeCodeGenerator;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverFingerprint;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverGenerator;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverLoader;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverWriter;
    use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
    use Componenta\DI\Compile\Parameter\DefaultParameterResolverCodeGenerators;
    use Componenta\DI\Compile\Parameter\Generator\ArrayTypedResolverCodeGenerator;
    use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
    use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry;
    use Componenta\DI\Exception\ResolutionException;
    use Componenta\DI\ProtectedServiceIds;
    use Componenta\DI\ProxyFactoryInterface;
    use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
    use Componenta\DI\Resolver\Attribute\AttributePhase;
    use Componenta\DI\Resolver\Attribute\AttributeProcessor;
    use Componenta\DI\Resolver\Attribute\CreationStrategy;
    use Componenta\DI\Resolver\Entry\CompositeResolver;
    use Componenta\DI\Resolver\Entry\InstanceCreator;
    use Componenta\DI\Resolver\Entry\ObjectCreationContext;
    use Componenta\DI\Resolver\Entry\ReflectionResolver;
    use Componenta\DI\Resolver\Parameter\ArrayResolver;
    use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
    use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
    use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
    use Componenta\DI\Resolver\Parameter\ParametersResolver;
    use Componenta\DI\Resolver\Target\ParameterTarget;
    use Verification\Both;
    use Verification\ClosureHolder;
    use Verification\CountingResolver;
    use Verification\DnfEntry;
    use Verification\EagerExample;
    use Verification\FakeInvoker;
    use Verification\FakeProxyFactory;
    use Verification\FingerprintHooks;
    use Verification\FixedGenerator;
    use Verification\FixedResolver;
    use Verification\LazyEntry;
    use Verification\LazyExample;
    use Verification\Left;
    use Verification\LeftOnly;
    use Verification\MutatingResolver;
    use Verification\MutatingHandlerRegistryHandler;
    use Verification\MutatingCodeGeneratorRegistryGenerator;
    use Verification\MutableMetadataHandler;
    use Verification\NativeProxyFactory;
    use Verification\NoArgumentsExample;
    use Verification\NoCtorExample;
    use Verification\OptionalDefaultsExample;
    use Verification\OwnerResolver;
    use Verification\ProxyEntry;
    use Verification\ProxyExample;
    use Verification\PrivateConstructorExample;
    use Verification\PrivateNoCtorExample;
    use Verification\RequiredNumberExample;
    use Verification\RetryLazyExample;
    use Verification\RetryProxyExample;
    use Verification\RetryValueHandler;
    use Verification\SideEffectHandler;
    use Verification\SkipConstructorHandler;
    use Verification\StrategyHandler;
    use Verification\UnwritableExample;
    use Verification\ValueHandler;
    use Verification\WithSetup;
    use Verification\WrongPositionResolver;
    use Verification\WrongValueResolver;
    use Verification\MalformedResultResolver;
    use Verification\ChildFingerprintState;
    use Verification\CountedDefault;
    use Verification\DynamicAttributeHandler;
    use Verification\ByReferenceExample;
    use Verification\ChildPrivateAttributeExample;
    use Verification\NoCtorByReferenceExample;
    use Verification\MutableAttributeExample;
    use Verification\MutatingAttributeHandler;
    use Verification\NoCtorVariadicExample;
    use Verification\NullResolver;
    use Verification\ObjectDefaultExample;
    use Verification\ThrowingConstructorExample;
    use Verification\ThrowingHookExample;
    use Verification\ThrowingLazyConstructorExample;
    use Verification\ThrowingProxyConstructorExample;
    use Verification\VariadicExample;
    use Verification\WritableSideEffectExample;

    $checks = 0;
    $pass = static function () use (&$checks): void { ++$checks; };

    // ParameterTarget and runtime resolver correctness, including DNF/intersection types.
    $dnfParameter = (new ReflectionMethod(DnfEntry::class, '__construct'))->getParameters()[0];
    $dnfTarget = new ParameterTarget($dnfParameter);
    same([Verification\Left::class, Verification\Right::class, stdClass::class], $dnfTarget->typeNames, 'DNF type names must be flattened in declaration order');
    $pass();

    $typed = new ArrayTypedResolver();
    verify($typed->supports($dnfTarget), 'ArrayTypedResolver must support DNF object types');
    $pass();
    same(null, $typed->resolveParameter($dnfTarget, new ParameterResolutionContext(['wrong' => new LeftOnly()])), 'Partial intersection match must be rejected');
    $pass();
    $both = new Both();
    same([0, $both], $typed->resolveParameter($dnfTarget, new ParameterResolutionContext(['candidate' => $both])), 'Complete intersection match must resolve');
    $pass();
    same(null, $typed->resolveParameter($dnfTarget, new ParameterResolutionContext([Left::class => new LeftOnly()])), 'Invalid type-key value must be rejected');
    $pass();

    // Applicable resolver slots are computed once and reused.
    $counting = new CountingResolver();
    $countingRuntime = new ParametersResolver($counting);
    $numberParameter = (new ReflectionMethod(EagerExample::class, '__construct'))->getParameters()[0];
    $numberTarget = $countingRuntime->target($numberParameter);
    same([0], $countingRuntime->resolverSlotsFor($numberTarget), 'Resolver slot list must include the matching resolver');
    same([0], $countingRuntime->resolverSlotsFor($numberTarget), 'Resolver slot list must be reusable');
    same(1, $counting->supportsCalls, 'supports() must be cached per target and resolver-chain version');
    $pass();

    $runtimeMutatingResolver = new MutatingResolver();
    $runtimeMutatingParameters = new ParametersResolver($runtimeMutatingResolver);
    $runtimeMutatingResolver->chain = $runtimeMutatingParameters;
    throws(
        static fn() => $runtimeMutatingParameters->resolverSlotsFor(
            $runtimeMutatingParameters->target($numberParameter),
        ),
        LogicException::class,
        'supports() must not structurally mutate the runtime resolver chain',
    );
    $pass();

    $runtimeMutatingHandler = new MutatingHandlerRegistryHandler();
    $runtimeMutatingRegistry = new AttributeHandlerRegistry();
    $runtimeMutatingHandler->registry = $runtimeMutatingRegistry;
    $runtimeMutatingRegistry->add($runtimeMutatingHandler);
    $runtimeMutatingProcessor = new AttributeProcessor($runtimeMutatingRegistry);
    throws(
        static fn() => $runtimeMutatingProcessor->invocations(
            new ReflectionClass(EagerExample::class),
        ),
        LogicException::class,
        'supportsAttribute() must not structurally mutate the handler registry',
    );
    $pass();

    $requiredParameter = (new ReflectionMethod(RequiredNumberExample::class, '__construct'))->getParameters()[0];
    throws(
        static function () use ($requiredParameter): void {
            $parameters = new ParametersResolver(new WrongPositionResolver());
            $parameters->resolveParameter(
                $parameters->target($requiredParameter),
                new ParameterResolutionContext(),
            );
        },
        ResolutionException::class,
        'Runtime parameter resolution must reject a resolver result for another position',
    );
    throws(
        static function () use ($requiredParameter): void {
            $parameters = new ParametersResolver(new WrongValueResolver());
            $parameters->resolveParameter(
                $parameters->target($requiredParameter),
                new ParameterResolutionContext(),
            );
        },
        ResolutionException::class,
        'Runtime parameter resolution must reject values that violate the declared type',
    );
    throws(
        static function () use ($requiredParameter): void {
            $parameters = new ParametersResolver(new MalformedResultResolver());
            $parameters->resolveParameter(
                $parameters->target($requiredParameter),
                new ParameterResolutionContext(),
            );
        },
        ResolutionException::class,
        'Runtime parameter resolution must reject malformed resolver tuples',
    );
    $checks += 3;

    $sealedParameters = new ParametersResolver(new FixedResolver());
    $sealedParameters->seal();
    throws(
        static fn() => $sealedParameters->add(new FixedResolver()),
        \LogicException::class,
        'Sealed parameter resolver pipeline must reject runtime mutation',
    );
    $sealedHandlers = new AttributeHandlerRegistry();
    $sealedHandlers->add(new SkipConstructorHandler());
    $sealedHandlers->seal();
    throws(
        static fn() => $sealedHandlers->add(new ValueHandler()),
        \LogicException::class,
        'Sealed attribute registry must reject runtime mutation',
    );
    $checks += 2;

    $fixed = new FixedResolver();
    $parameterRuntime = new ParametersResolver($fixed);

    // Unwritable properties are rejected before a handler can perform side effects.
    $sideEffect = new SideEffectHandler();
    $unwritableRegistry = new AttributeHandlerRegistry();
    $unwritableRegistry->add($sideEffect);
    $unwritableProcessor = new AttributeProcessor($unwritableRegistry);
    $unwritable = new UnwritableExample();
    $unwritableContext = new ObjectCreationContext(new ReflectionClass(UnwritableExample::class));
    $unwritableContext->initialize($unwritable);
    $unwritableProcessor->process(new ReflectionClass(UnwritableExample::class), AttributePhase::AfterInstantiation, $unwritableContext);
    same(0, $sideEffect->calls, 'Static and initialized readonly properties must be skipped before value resolution');
    same(2, $unwritable->readonlyValue, 'Initialized readonly property must remain unchanged');
    $pass();

    // Fingerprinting must not invoke property hooks.
    $hooked = new FingerprintHooks();
    GeneratedEntryResolverFingerprint::objects([$hooked]);
    same(0, $hooked->reads, 'Runtime fingerprinting must use raw backing values and skip virtual hooks');
    $pass();

    $closureFileA = sys_get_temp_dir() . '/componenta-di-closure-a.php';
    $closureFileB = sys_get_temp_dir() . '/componenta-di-closure-b.php';
    $closureSource = "<?php\nreturn static fn(int \$value): int => \$value + 1;\n";
    file_put_contents($closureFileA, $closureSource);
    file_put_contents($closureFileB, $closureSource);
    $closureA = require $closureFileA;
    $closureB = require $closureFileB;
    same(
        GeneratedEntryResolverFingerprint::objects([new ClosureHolder($closureA)]),
        GeneratedEntryResolverFingerprint::objects([new ClosureHolder($closureB)]),
        'Closure configuration fingerprints must not depend on absolute deployment paths',
    );
    $pass();

    verify(
        GeneratedEntryResolverFingerprint::objects([new ChildFingerprintState(1)])
            !== GeneratedEntryResolverFingerprint::objects([new ChildFingerprintState(2)]),
        'Private state declared by a parent class must participate in the runtime fingerprint',
    );
    $pass();

    $anonymousFileA = sys_get_temp_dir() . '/componenta-di-anonymous-a.php';
    $anonymousFileB = sys_get_temp_dir() . '/componenta-di-anonymous-b.php';
    $anonymousSource = <<<'PHP'
<?php
return new class(5) {
    public function __construct(private int $value) {}
};
PHP;
    file_put_contents($anonymousFileA, $anonymousSource);
    file_put_contents($anonymousFileB, $anonymousSource);
    $anonymousA = require $anonymousFileA;
    $anonymousB = require $anonymousFileB;
    same(
        GeneratedEntryResolverFingerprint::objectTypes([$anonymousA]),
        GeneratedEntryResolverFingerprint::objectTypes([$anonymousB]),
        'Anonymous extension type identity must not depend on the deployment path',
    );
    same(
        GeneratedEntryResolverFingerprint::objects([$anonymousA]),
        GeneratedEntryResolverFingerprint::objects([$anonymousB]),
        'Anonymous extension state fingerprint must not depend on the deployment path',
    );
    $checks += 2;

    // Core ids and extension registries have one canonical contract.
    verify(
        ProtectedServiceIds::contains(\Componenta\Config\Config::class),
        'Config service id must be protected by the composition root',
    );
    same(
        \Componenta\Config\Config::class,
        ProtectedServiceIds::bootstrapType(\Componenta\Config\Config::class),
        'Bootstrap type metadata must come from the protected-id registry',
    );
    verify(
        ProtectedServiceIds::contains(ProxyFactoryInterface::class),
        'Container-owned proxy factory id must be protected',
    );
    same(
        null,
        ProtectedServiceIds::bootstrapType(ProxyFactoryInterface::class),
        'Container-owned ids must not be accepted through the bootstrap map',
    );

    $duplicateResolver = new NullResolver();
    throws(
        static fn() => new ParametersResolver($duplicateResolver, $duplicateResolver),
        InvalidArgumentException::class,
        'The same parameter resolver instance must not be registered twice',
    );

    $duplicateHandler = new ValueHandler();
    $duplicateHandlerRegistry = new AttributeHandlerRegistry();
    $duplicateHandlerRegistry->add($duplicateHandler);
    throws(
        static fn() => $duplicateHandlerRegistry->add($duplicateHandler),
        InvalidArgumentException::class,
        'The same attribute handler instance must not be registered twice',
    );

    $mutableMetadataHandler = new MutableMetadataHandler();
    $mutableMetadataRegistry = new AttributeHandlerRegistry();
    throws(
        static fn() => $mutableMetadataRegistry->add($mutableMetadataHandler),
        InvalidArgumentException::class,
        'Mutable handler ordering metadata must be rejected at registration',
    );
    $checks += 7;

    // Compile/runtime contracts must not retain redundant metadata or a
    // second runtime-fallback compile API.
    verify(
        !trait_exists('Componenta\\DI\\Compile\\Attribute\\RuntimeAttributeHandlerCode'),
        'Runtime-only attribute handlers must use the generic exact-slot fallback',
    );
    verify(
        !interface_exists('Componenta\\DI\\Resolver\\Entry\\InstantiatorInterface'),
        'Reflection construction must not retain the redundant instantiator interface',
    );
    verify(
        !is_subclass_of(
            Componenta\DI\Resolver\Attribute\Handler\InitHandler::class,
            Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface::class,
        ),
        'InitHandler must not expose redundant specialized compile semantics',
    );
    $constructorNames = static fn(string $class): array => array_map(
        static fn(ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod($class, '__construct'))->getParameters(),
    );
    verify(
        !in_array('usesHandler', $constructorNames(Componenta\DI\Compile\Attribute\GeneratedAttributeCode::class), true),
        'GeneratedAttributeCode must not retain the unused usesHandler flag',
    );
    verify(
        !in_array('terminal', $constructorNames(Componenta\DI\Compile\Parameter\GeneratedParameterCode::class), true),
        'GeneratedParameterCode must not retain the unused terminal flag',
    );
    verify(
        !in_array('phase', $constructorNames(Componenta\DI\Resolver\Attribute\AttributeInvocation::class), true),
        'AttributeInvocation phase must be owned only by its phase-specific list',
    );
    verify(
        !in_array('containerExpression', $constructorNames(Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext::class), true),
        'Parameter code context must not expose an unused container expression',
    );
    verify(
        !property_exists(Componenta\DI\Resolver\Attribute\AttributeProcessor::class, 'handlerList'),
        'AttributeProcessor must not duplicate the registry handler-list API',
    );
    $checks += 8;

    // A runtime-only attribute is not instantiated while the factory is
    // compiled. It is instantiated exactly when the generated entry runs.
    Verification\SideEffect::$instances = 0;
    $compileSideEffectHandler = new SideEffectHandler();
    $compileSideEffectRegistry = new AttributeHandlerRegistry();
    $compileSideEffectRegistry->add($compileSideEffectHandler);
    $compileSideEffectProcessor = new AttributeProcessor($compileSideEffectRegistry);
    $compileSideEffectParameters = new ParametersResolver();
    $compileSideEffectGenerators = new ParameterResolverCodeGeneratorRegistry();
    $compileSideEffectFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $compileSideEffectParameters,
            $compileSideEffectGenerators,
        ),
        $compileSideEffectProcessor,
        new AttributeCodeGenerator(),
    );
    $compileSideEffectGenerator = new GeneratedEntryResolverGenerator(
        $compileSideEffectFactory,
        $compileSideEffectParameters,
        $compileSideEffectProcessor,
        $compileSideEffectGenerators,
    );
    $compileSideEffectFile = sys_get_temp_dir() . '/componenta-di-stage15-attribute-side-effect.php';
    $compileSideEffectCode = $compileSideEffectGenerator->generate(
        [WritableSideEffectExample::class],
        'Verification\\GeneratedAttributeSideEffect',
    );
    same(
        0,
        Verification\SideEffect::$instances,
        'Code generation must not instantiate runtime-only attributes',
    );
    $compileSideEffectWriter = new GeneratedEntryResolverWriter();
    $compileSideEffectWriter->write($compileSideEffectFile, $compileSideEffectCode);
    $compileSideEffectResolver = (new GeneratedEntryResolverLoader())->load(
        $compileSideEffectFile,
        [],
        $compileSideEffectProcessor->registry->handlers,
        new FakeProxyFactory(),
    );
    verify($compileSideEffectResolver !== null, 'Runtime-only attribute generated resolver must load');
    $compileSideEffectEntry = $compileSideEffectResolver->resolve(WritableSideEffectExample::class);
    same(99, $compileSideEffectEntry->value, 'Runtime-only attribute handler must execute by exact slot');
    same(1, Verification\SideEffect::$instances, 'Runtime-only attribute must be instantiated once at entry creation');
    $checks += 5;

    // Generated factory/runtime pipeline.
    $resolverGenerators = new ParameterResolverCodeGeneratorRegistry();
    $resolverGenerators->register(FixedResolver::class, new FixedGenerator());
    $registry = new AttributeHandlerRegistry();
    $registry->add(new SkipConstructorHandler());
    $registry->add(new StrategyHandler(ProxyEntry::class, CreationStrategy::Proxy, 200));
    $registry->add(new StrategyHandler(LazyEntry::class, CreationStrategy::Lazy, 100));
    $valueHandler = new ValueHandler();
    $registry->add($valueHandler);
    $registry->add(new Componenta\DI\Resolver\Entry\SetUpRunner(new FakeInvoker($parameterRuntime)));
    $processor = new AttributeProcessor($registry);
    $factoryGenerator = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameterRuntime, $resolverGenerators),
        $processor,
        new AttributeCodeGenerator(),
    );
    $entryGenerator = new GeneratedEntryResolverGenerator($factoryGenerator, $parameterRuntime, $processor, $resolverGenerators);
    $generatedFile = sys_get_temp_dir() . '/componenta-di-stage15-generated.php';
    $writer = new GeneratedEntryResolverWriter();
    $generatedCode = $entryGenerator->generate([
        EagerExample::class,
        NoCtorExample::class,
        PrivateNoCtorExample::class,
        PrivateConstructorExample::class,
        LazyExample::class,
        ProxyExample::class,
        WithSetup::class,
    ], 'Verification\\Generated');
    verify(str_contains($generatedCode, var_export(ParametersResolver::class, true)), 'Generated source contract must fingerprint ParametersResolver orchestration');
    verify(str_contains($generatedCode, var_export(AttributeHandlerRegistry::class, true)), 'Generated source contract must fingerprint handler registry ordering');
    verify(str_contains($generatedCode, var_export(\Componenta\DI\Resolver\TypeHints::class, true)), 'Generated source contract must fingerprint autowire type classification');
    verify(!str_contains($generatedCode, 'ENTRY_CLASSES'), 'Generated resolver must not retain unused entry-class metadata');
    $writer->write($generatedFile, $generatedCode);

    $proxy = new FakeProxyFactory();
    $loader = new GeneratedEntryResolverLoader();
    $generated = $loader->load($generatedFile, $parameterRuntime->resolverList, $processor->registry->handlers, $proxy);
    verify($generated instanceof Componenta\DI\Resolver\Entry\EntryResolverInterface, 'Generated resolver must load for the exact runtime');
    $pass();

    $eager = $generated->resolve(EagerExample::class);
    $noCtor = $generated->resolve(NoCtorExample::class);
    $privateNoCtor = $generated->resolve(PrivateNoCtorExample::class);
    throws(
        static fn(): object => $generated->resolve(PrivateConstructorExample::class),
        ResolutionException::class,
        'Generated resolver must report inaccessible constructor as ResolutionException',
    );
    $lazy = $generated->resolve(LazyExample::class);
    $proxied = $generated->resolve(ProxyExample::class);
    $setup = $generated->resolve(WithSetup::class);

    same(7, $eager->number, 'Generated eager constructor argument');
    same(42, $eager->property, 'Generated property handler');
    same(1, $eager->writes, 'Reflection property write must invoke PHP 8.4 set hook');
    verify($noCtor instanceof NoCtorExample && $noCtor->value === 42, 'NoConstructor pipeline must skip the throwing constructor');
    verify($privateNoCtor instanceof PrivateNoCtorExample && $privateNoCtor->value === 82, 'Generated NoConstructor must support private constructors');
    verify($lazy instanceof LazyExample && $lazy->number === 7 && $lazy->property === 9 && $proxy->lazyCalls === 1, 'Lazy pipeline must initialize the real entry and post handlers');
    verify($proxied instanceof ProxyExample && $proxied->number === 7 && $proxied->property === 11 && $proxy->proxyCalls === 1, 'Proxy pipeline must use the common eager lifecycle');
    verify($setup instanceof WithSetup && $setup->booted && $setup->setupValue === 7, 'SetUp parameters must use the same parameter chain');
    $checks += 13;

    same(null, $loader->load($generatedFile, [], $processor->registry->handlers, $proxy), 'Different parameter slot order must reject generated code');
    $pass();

    // Reflection fallback must execute the same lifecycle as generated factories.
    $reflectionProxy = new FakeProxyFactory();
    $reflection = new ReflectionResolver(
        new InstanceCreator($parameterRuntime),
        $processor,
        $reflectionProxy,
    );
    $anonymousEntry = new class () {};
    verify(!$reflection->can(\Closure::class), 'Reflection resolver must reject non-instantiable internal classes');
    verify(!$reflection->can($anonymousEntry::class), 'Reflection resolver must reject anonymous class ids');
    throws(
        static fn(): string => $entryGenerator->generate([$anonymousEntry::class], 'Verification\\AnonymousGenerated'),
        \InvalidArgumentException::class,
        'Generated resolver must apply the same anonymous-class eligibility rule',
    );
    $reflectionEager = $reflection->resolve(EagerExample::class);
    $reflectionNoCtor = $reflection->resolve(NoCtorExample::class);
    verify($reflection->can(PrivateNoCtorExample::class), 'Reflection resolver must claim concrete classes with private constructors');
    $reflectionPrivateNoCtor = $reflection->resolve(PrivateNoCtorExample::class);
    throws(
        static fn(): object => $reflection->resolve(PrivateConstructorExample::class),
        ResolutionException::class,
        'Reflection resolver must report inaccessible constructor as ResolutionException',
    );
    $reflectionLazy = $reflection->resolve(LazyExample::class);
    $reflectionProxyEntry = $reflection->resolve(ProxyExample::class);
    $reflectionSetup = $reflection->resolve(WithSetup::class);

    verify($reflectionEager->number === 7 && $reflectionEager->property === 42, 'Reflection eager pipeline must match generated semantics');
    verify($reflectionNoCtor instanceof NoCtorExample && $reflectionNoCtor->value === 42, 'Reflection NoConstructor pipeline must match generated semantics');
    verify($reflectionPrivateNoCtor instanceof PrivateNoCtorExample && $reflectionPrivateNoCtor->value === 82, 'Reflection NoConstructor must support private constructors');
    verify($reflectionLazy->number === 7 && $reflectionLazy->property === 9 && $reflectionProxy->lazyCalls === 1, 'Reflection lazy pipeline must match generated semantics');
    verify($reflectionProxyEntry->number === 7 && $reflectionProxyEntry->property === 11 && $reflectionProxy->proxyCalls === 1, 'Reflection proxy pipeline must match generated semantics');
    verify($reflectionSetup->booted && $reflectionSetup->setupValue === 7, 'Reflection SetUp pipeline must match generated semantics');
    $checks += 11;

    // Object defaults must be evaluated per resolution in both runtime and
    // generated non-fast paths. Reusing the reflected object would leak state.
    $objectDefaultParameters = new ParametersResolver(
        new NullResolver(),
        new DefaultValueResolver(),
    );
    $objectDefaultProcessor = new AttributeProcessor(new AttributeHandlerRegistry());
    $objectDefaultReflection = new ReflectionResolver(
        new InstanceCreator($objectDefaultParameters),
        $objectDefaultProcessor,
        $reflectionProxy,
    );
    $runtimeDefaultA = $objectDefaultReflection->resolve(ObjectDefaultExample::class);
    $runtimeDefaultB = $objectDefaultReflection->resolve(ObjectDefaultExample::class);
    verify(
        $runtimeDefaultA->value !== $runtimeDefaultB->value,
        'Reflection resolution must create a fresh object-valued default per call',
    );

    $objectDefaultGenerators = DefaultParameterResolverCodeGenerators::create();
    $objectDefaultFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $objectDefaultParameters,
            $objectDefaultGenerators,
        ),
        $objectDefaultProcessor,
        new AttributeCodeGenerator(),
    );
    $objectDefaultGenerator = new GeneratedEntryResolverGenerator(
        $objectDefaultFactory,
        $objectDefaultParameters,
        $objectDefaultProcessor,
        $objectDefaultGenerators,
    );
    $objectDefaultFile = sys_get_temp_dir() . '/componenta-di-stage15-object-default.php';
    CountedDefault::$instances = 0;
    $objectDefaultCode = $objectDefaultGenerator->generate(
        [ObjectDefaultExample::class],
        'Verification\\GeneratedObjectDefault',
    );
    same(
        0,
        CountedDefault::$instances,
        'Code generation must not evaluate an object-valued constructor default',
    );
    $writer->write($objectDefaultFile, $objectDefaultCode);
    $objectDefaultGenerated = $loader->load(
        $objectDefaultFile,
        $objectDefaultParameters->resolverList,
        [],
        $proxy,
    );
    verify($objectDefaultGenerated !== null, 'Object-default generated resolver must load');
    $generatedDefaultA = $objectDefaultGenerated->resolve(ObjectDefaultExample::class);
    $generatedDefaultB = $objectDefaultGenerated->resolve(ObjectDefaultExample::class);
    verify(
        $generatedDefaultA->value !== $generatedDefaultB->value,
        'Generated non-fast resolution must create a fresh object-valued default per call',
    );
    same(
        2,
        CountedDefault::$instances,
        'Generated resolution must evaluate the object default once per entry creation',
    );
    $checks += 5;

    // Private attributed members declared by parents are part of the same
    // metadata pipeline for reflection and generated factories.
    $parentPrivateReflection = $reflection->resolve(ChildPrivateAttributeExample::class);
    same(66, $parentPrivateReflection->inheritedPrivate(), 'Reflection must process inherited private attributed properties');
    $parentPrivateFile = sys_get_temp_dir() . '/componenta-di-stage15-parent-private.php';
    $writer->write($parentPrivateFile, $entryGenerator->generate(
        [ChildPrivateAttributeExample::class],
        'Verification\\GeneratedParentPrivate',
    ));
    $parentPrivateGenerated = $loader->load(
        $parentPrivateFile,
        $parameterRuntime->resolverList,
        $processor->registry->handlers,
        $proxy,
    );
    verify($parentPrivateGenerated !== null, 'Parent-private generated resolver must load');
    same(
        66,
        $parentPrivateGenerated->resolve(ChildPrivateAttributeExample::class)->inheritedPrivate(),
        'Generated pipeline must process inherited private attributed properties',
    );
    $checks += 3;

    // Attribute objects are execution snapshots, never shared mutable metadata.
    $mutableHandler = new MutatingAttributeHandler();
    $mutableRegistry = new AttributeHandlerRegistry();
    $mutableRegistry->add($mutableHandler);
    $mutableProcessor = new AttributeProcessor($mutableRegistry);
    $mutableReflection = new ReflectionResolver(
        new InstanceCreator(new ParametersResolver()),
        $mutableProcessor,
        $proxy,
    );
    same(31, $mutableReflection->resolve(MutableAttributeExample::class)->value, 'First reflection invocation receives the declared attribute state');
    same(31, $mutableReflection->resolve(MutableAttributeExample::class)->value, 'Second reflection invocation receives a fresh attribute object');
    $mutableFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            new ParametersResolver(),
            new ParameterResolverCodeGeneratorRegistry(),
        ),
        $mutableProcessor,
        new AttributeCodeGenerator(),
    );
    $mutableCodeA = $mutableFactory->generate(MutableAttributeExample::class, 'createMutableA')->code;
    $mutableCodeB = $mutableFactory->generate(MutableAttributeExample::class, 'createMutableB')->code;
    verify(str_contains($mutableCodeA, ', 31);'), 'First compilation must receive the declared mutable attribute state');
    verify(str_contains($mutableCodeB, ', 31);'), 'Repeated compilation must receive a fresh mutable attribute object');
    $checks += 4;

    // Property-hook failures and constructor engine errors use the same DI
    // exception contract in reflection and generated paths.
    throws(
        static fn() => $reflection->resolve(ThrowingHookExample::class),
        ResolutionException::class,
        'Reflection property-hook failures must be wrapped as ResolutionException',
    );
    throws(
        static fn() => $reflection->resolve(ThrowingConstructorExample::class),
        ResolutionException::class,
        'Reflection constructor engine errors must be wrapped as ResolutionException',
    );
    $errorFile = sys_get_temp_dir() . '/componenta-di-stage15-errors.php';
    $writer->write($errorFile, $entryGenerator->generate([
        ThrowingHookExample::class,
        ThrowingConstructorExample::class,
        ThrowingLazyConstructorExample::class,
        ThrowingProxyConstructorExample::class,
    ], 'Verification\\GeneratedErrors'));
    $errorGenerated = $loader->load(
        $errorFile,
        $parameterRuntime->resolverList,
        $processor->registry->handlers,
        $nativeProxyFactory = new NativeProxyFactory(),
    );
    verify($errorGenerated !== null, 'Generated error-contract resolver must load');
    throws(
        static fn() => $errorGenerated->resolve(ThrowingHookExample::class),
        ResolutionException::class,
        'Generated property-hook failures must be wrapped as ResolutionException',
    );
    throws(
        static fn() => $errorGenerated->resolve(ThrowingConstructorExample::class),
        ResolutionException::class,
        'Generated eager constructor engine errors must be wrapped as ResolutionException',
    );
    $throwingLazy = $errorGenerated->resolve(ThrowingLazyConstructorExample::class);
    throws(
        static fn() => $throwingLazy->touch,
        ResolutionException::class,
        'Generated delayed lazy constructor errors must be wrapped as ResolutionException',
    );
    $throwingProxy = $errorGenerated->resolve(ThrowingProxyConstructorExample::class);
    throws(
        static fn() => $throwingProxy->touch,
        ResolutionException::class,
        'Generated delayed proxy constructor errors must be wrapped as ResolutionException',
    );
    $checks += 7;

    // Variadics remain unsupported by the parameter pipeline, but code
    // generation itself must not reject classes whose before-handlers disable
    // their constructor.
    $variadicRuntime = new ReflectionResolver(
        new InstanceCreator(new ParametersResolver()),
        new AttributeProcessor(new AttributeHandlerRegistry()),
        $proxy,
    );
    throws(
        static fn() => $variadicRuntime->resolve(VariadicExample::class),
        ResolutionException::class,
        'Reflection variadic resolution must fail with a DI exception',
    );
    $variadicParameters = new ParametersResolver();
    $variadicGenerators = new ParameterResolverCodeGeneratorRegistry();
    $variadicFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $variadicParameters,
            $variadicGenerators,
        ),
        $processor,
        new AttributeCodeGenerator(),
    );
    $variadicGenerator = new GeneratedEntryResolverGenerator(
        $variadicFactory,
        $variadicParameters,
        $processor,
        $variadicGenerators,
    );
    $variadicFile = sys_get_temp_dir() . '/componenta-di-stage15-variadic.php';
    $writer->write($variadicFile, $variadicGenerator->generate([
        VariadicExample::class,
        NoCtorVariadicExample::class,
        ByReferenceExample::class,
        NoCtorByReferenceExample::class,
    ], 'Verification\\GeneratedVariadic'));
    $variadicGenerated = $loader->load(
        $variadicFile,
        [],
        $processor->registry->handlers,
        $proxy,
    );
    verify($variadicGenerated !== null, 'Generated variadic resolver must compile and load');
    throws(
        static fn() => $variadicGenerated->resolve(VariadicExample::class),
        ResolutionException::class,
        'Generated variadic resolution must fail only when the constructor is executed',
    );
    same(
        73,
        $variadicGenerated->resolve(NoCtorVariadicExample::class)->value,
        'NoConstructor handler must bypass an otherwise unsupported variadic constructor',
    );
    throws(
        static fn() => $variadicRuntime->resolve(ByReferenceExample::class, ['value' => 1]),
        ResolutionException::class,
        'Reflection by-reference resolution must fail without emitting a PHP warning',
    );
    throws(
        static fn() => $variadicGenerated->resolve(ByReferenceExample::class, ['value' => 1]),
        ResolutionException::class,
        'Generated by-reference resolution must use the same DI error contract',
    );
    same(
        74,
        $variadicGenerated->resolve(NoCtorByReferenceExample::class)->value,
        'NoConstructor handler must bypass an otherwise unsupported by-reference constructor',
    );
    $checks += 7;

    // Failed PHP lazy-object realizations are retried. Each retry must receive
    // fresh mutable creation state while preserving the selected strategy.
    $reflectionRetryHandler = new RetryValueHandler();
    $reflectionRetryRegistry = new AttributeHandlerRegistry();
    $reflectionRetryRegistry->add(new StrategyHandler(ProxyEntry::class, CreationStrategy::Proxy, 200));
    $reflectionRetryRegistry->add(new StrategyHandler(LazyEntry::class, CreationStrategy::Lazy, 100));
    $reflectionRetryRegistry->add($reflectionRetryHandler);
    $reflectionRetryProcessor = new AttributeProcessor($reflectionRetryRegistry);
    $nativeProxyFactory = new NativeProxyFactory();
    $reflectionRetry = new ReflectionResolver(
        new InstanceCreator($parameterRuntime),
        $reflectionRetryProcessor,
        $nativeProxyFactory,
    );
    $retryLazy = $reflectionRetry->resolve(RetryLazyExample::class);
    throws(
        static fn() => $retryLazy->property,
        RuntimeException::class,
        'Reflection lazy realization must surface the first handler failure',
    );
    same(77, $retryLazy->property, 'Reflection lazy realization must succeed on the PHP retry');
    same(2, $reflectionRetryHandler->attemptsFor(RetryLazyExample::class), 'Reflection lazy retry must execute the handler twice');
    $retryProxy = $reflectionRetry->resolve(RetryProxyExample::class);
    throws(
        static fn() => $retryProxy->property,
        RuntimeException::class,
        'Reflection proxy realization must surface the first handler failure',
    );
    same(78, $retryProxy->property, 'Reflection proxy realization must succeed on the PHP retry');
    same(2, $reflectionRetryHandler->attemptsFor(RetryProxyExample::class), 'Reflection proxy retry must execute the handler twice');
    $checks += 6;

    $generatedRetryHandler = new RetryValueHandler();
    $generatedRetryRegistry = new AttributeHandlerRegistry();
    $generatedRetryRegistry->add(new StrategyHandler(ProxyEntry::class, CreationStrategy::Proxy, 200));
    $generatedRetryRegistry->add(new StrategyHandler(LazyEntry::class, CreationStrategy::Lazy, 100));
    $generatedRetryRegistry->add($generatedRetryHandler);
    $generatedRetryProcessor = new AttributeProcessor($generatedRetryRegistry);
    $generatedRetryFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameterRuntime, $resolverGenerators),
        $generatedRetryProcessor,
        new AttributeCodeGenerator(),
    );
    $generatedRetryGenerator = new GeneratedEntryResolverGenerator(
        $generatedRetryFactory,
        $parameterRuntime,
        $generatedRetryProcessor,
        $resolverGenerators,
    );
    $generatedRetryFile = sys_get_temp_dir() . '/componenta-di-stage15-retry.php';
    $writer->write($generatedRetryFile, $generatedRetryGenerator->generate([
        RetryLazyExample::class,
        RetryProxyExample::class,
    ], 'Verification\GeneratedRetry'));
    $generatedRetry = $loader->load(
        $generatedRetryFile,
        $parameterRuntime->resolverList,
        $generatedRetryProcessor->registry->handlers,
        $nativeProxyFactory,
    );
    verify($generatedRetry !== null, 'Generated retry resolver must load');
    $generatedRetryLazy = $generatedRetry->resolve(RetryLazyExample::class);
    throws(
        static fn() => $generatedRetryLazy->property,
        RuntimeException::class,
        'Generated lazy realization must surface the first handler failure',
    );
    same(77, $generatedRetryLazy->property, 'Generated lazy realization must succeed on the PHP retry');
    same(2, $generatedRetryHandler->attemptsFor(RetryLazyExample::class), 'Generated lazy retry must execute the handler twice');
    $generatedRetryProxy = $generatedRetry->resolve(RetryProxyExample::class);
    throws(
        static fn() => $generatedRetryProxy->property,
        RuntimeException::class,
        'Generated proxy realization must surface the first handler failure',
    );
    same(78, $generatedRetryProxy->property, 'Generated proxy realization must succeed on the PHP retry');
    same(2, $generatedRetryHandler->attemptsFor(RetryProxyExample::class), 'Generated proxy retry must execute the handler twice');
    $checks += 7;

    $changedRegistry = new AttributeHandlerRegistry();
    $changedRegistry->add(new SkipConstructorHandler());
    $changedRegistry->add(new StrategyHandler(ProxyEntry::class, CreationStrategy::Lazy, 200));
    $changedRegistry->add(new StrategyHandler(LazyEntry::class, CreationStrategy::Proxy, 100));
    $changedRegistry->add(new ValueHandler());
    $changedRegistry->add(new Componenta\DI\Resolver\Entry\SetUpRunner(new FakeInvoker($parameterRuntime)));
    $changedProcessor = new AttributeProcessor($changedRegistry);
    same(null, $loader->load($generatedFile, $parameterRuntime->resolverList, $changedProcessor->registry->handlers, $proxy), 'Different handler state must reject generated code');
    $pass();

    // Generated ArrayTyped code must preserve complete DNF matching.
    $typedParameters = new ParametersResolver(new ArrayTypedResolver());
    $typedGenerators = new ParameterResolverCodeGeneratorRegistry();
    $typedGenerators->register(ArrayTypedResolver::class, new ArrayTypedResolverCodeGenerator());
    $emptyHandlers = new AttributeHandlerRegistry();
    $emptyProcessor = new AttributeProcessor($emptyHandlers);

    $orderedParameter = (new ReflectionMethod(
        Verification\OrderedAttributeEntry::class,
        '__construct',
    ))->getParameters()[0];
    $orderedTarget = new Componenta\DI\Resolver\Target\ParameterTarget($orderedParameter);
    $orderedAttribute = $orderedTarget->firstAttribute(
        Verification\OrderedBaseAttribute::class,
    );
    verify(
        $orderedAttribute instanceof Verification\OrderedChildAttribute
            && $orderedAttribute->value === 'child',
        'Inherited parameter attributes must preserve native declaration order',
    );

    $attributeSources = $emptyProcessor->sourceAttributeClasses(
        new ReflectionClass(Verification\AttributeFingerprintEntry::class),
    );
    verify(
        in_array(Verification\UnsupportedMetadata::class, $attributeSources, true),
        'Source metadata must include unsupported class/property/method/parameter attributes',
    );
    verify(
        in_array('Verification\\LateBoundMetadata', $attributeSources, true),
        'Source metadata must retain missing attribute class names',
    );
    $missingAttributeFingerprint = GeneratedEntryResolverFingerprint::sources([
        'Verification\\LateBoundMetadata',
    ]);
    $lateBoundAttributeFile = sys_get_temp_dir() . '/componenta-di-stage16-late-attribute.php';
    file_put_contents(
        $lateBoundAttributeFile,
        "<?php\nnamespace Verification; #[\\Attribute(\\Attribute::TARGET_CLASS)] final readonly class LateBoundMetadata {}\n",
    );
    require $lateBoundAttributeFile;
    $loadedAttributeFingerprint = GeneratedEntryResolverFingerprint::sources([
        Verification\LateBoundMetadata::class,
    ]);
    verify(
        $missingAttributeFingerprint !== $loadedAttributeFingerprint,
        'A missing attribute class becoming available must invalidate generated metadata',
    );
    eval('namespace Verification; final class EvalOnlyFingerprintClass {}');
    throws(
        static fn() => GeneratedEntryResolverFingerprint::sources([
            Verification\EvalOnlyFingerprintClass::class,
        ]),
        LogicException::class,
        'User-defined eval classes without stable source files must be rejected',
    );
    $checks += 4;
    $scopedParameters = (new ReflectionMethod(
        Verification\ScopedChild::class,
        '__construct',
    ))->getParameters();
    $selfTarget = new Componenta\DI\Resolver\Target\ParameterTarget($scopedParameters[0]);
    $parentTarget = new Componenta\DI\Resolver\Target\ParameterTarget($scopedParameters[1]);
    same(Verification\ScopedChild::class, $selfTarget->className, '`self` must resolve in its declaring class scope');
    same([Verification\ScopedChild::class], $selfTarget->typeNames, '`self` type-key metadata must use the concrete class');
    same(Verification\ScopedParent::class, $parentTarget->className, '`parent` must resolve to the declaring parent class');
    same([Verification\ScopedParent::class], $parentTarget->typeNames, '`parent` type-key metadata must use the concrete parent');
    $scopedSelf = (new ReflectionClass(Verification\ScopedChild::class))->newInstanceWithoutConstructor();
    $scopedParent = new Verification\ScopedParent();
    verify($selfTarget->accepts($scopedSelf), '`self` compatibility must accept the declaring class');
    verify(!$selfTarget->accepts($scopedParent), '`self` compatibility must reject the parent instance');
    verify($parentTarget->accepts($scopedSelf), '`parent` compatibility must accept subclasses');
    verify($parentTarget->accepts($scopedParent), '`parent` compatibility must accept the parent instance');

    $scopedRuntime = new ParametersResolver(new ArrayResolver(), new ArrayTypedResolver());
    $scopedResolved = $scopedRuntime->resolve($scopedParameters, [
        Verification\ScopedChild::class => $scopedSelf,
        Verification\ScopedParent::class => $scopedParent,
    ]);
    same($scopedSelf, $scopedResolved[0], 'Runtime type-key resolution must support `self`');
    same($scopedParent, $scopedResolved[1], 'Runtime type-key resolution must support `parent`');
    $checks += 10;

    $typedFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($typedParameters, $typedGenerators),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $typedEntryGenerator = new GeneratedEntryResolverGenerator($typedFactory, $typedParameters, $emptyProcessor, $typedGenerators);
    $typedFile = sys_get_temp_dir() . '/componenta-di-stage15-dnf.php';
    $writer->write($typedFile, $typedEntryGenerator->generate([DnfEntry::class], 'Verification\\GeneratedDnf'));
    $typedGenerated = $loader->load($typedFile, $typedParameters->resolverList, [], $proxy);
    verify($typedGenerated !== null, 'DNF generated resolver must load');
    same($both, $typedGenerated->resolve(DnfEntry::class, ['candidate' => $both])->value, 'Generated ArrayTyped code must accept complete intersection');
    throws(
        static fn() => $typedGenerated->resolve(DnfEntry::class, ['candidate' => new LeftOnly()]),
        ResolutionException::class,
        'Generated ArrayTyped code must reject partial intersection',
    );
    $checks += 3;

    $scopedGenerators = new ParameterResolverCodeGeneratorRegistry();
    $scopedGenerators->register(
        ArrayResolver::class,
        new Componenta\DI\Compile\Parameter\Generator\ArrayResolverCodeGenerator(),
    );
    $scopedGenerators->register(ArrayTypedResolver::class, new ArrayTypedResolverCodeGenerator());
    $scopedFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($scopedRuntime, $scopedGenerators),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $scopedEntryGenerator = new GeneratedEntryResolverGenerator(
        $scopedFactory,
        $scopedRuntime,
        $emptyProcessor,
        $scopedGenerators,
    );
    $scopedFile = sys_get_temp_dir() . '/componenta-di-stage16-scoped-types.php';
    $writer->write($scopedFile, $scopedEntryGenerator->generate([
        Verification\ScopedChild::class,
    ], 'Verification\GeneratedScopedTypes'));
    $scopedGenerated = $loader->load(
        $scopedFile,
        $scopedRuntime->resolverList,
        [],
        $proxy,
    );
    verify($scopedGenerated !== null, 'Scoped-type generated resolver must load');
    $scopedEntry = $scopedGenerated->resolve(Verification\ScopedChild::class, [
        Verification\ScopedChild::class => $scopedSelf,
        Verification\ScopedParent::class => $scopedParent,
    ]);
    same($scopedSelf, $scopedEntry->selfValue, 'Generated type-key resolution must support `self`');
    same($scopedParent, $scopedEntry->parentValue, 'Generated type-key resolution must support `parent`');
    $checks += 3;

    $wrongGeneratedParameters = new ParametersResolver(new WrongPositionResolver());
    $wrongGeneratedProcessor = new AttributeProcessor(new AttributeHandlerRegistry());
    $wrongGeneratedGenerators = new ParameterResolverCodeGeneratorRegistry();
    $wrongGeneratedFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $wrongGeneratedParameters,
            $wrongGeneratedGenerators,
        ),
        $wrongGeneratedProcessor,
        new AttributeCodeGenerator(),
    );
    $wrongGeneratedGenerator = new GeneratedEntryResolverGenerator(
        $wrongGeneratedFactory,
        $wrongGeneratedParameters,
        $wrongGeneratedProcessor,
        $wrongGeneratedGenerators,
    );
    $wrongGeneratedFile = sys_get_temp_dir() . '/componenta-di-stage15-wrong-result.php';
    $writer->write($wrongGeneratedFile, $wrongGeneratedGenerator->generate(
        [RequiredNumberExample::class],
        'Verification\GeneratedWrongResult',
    ));
    $wrongGenerated = $loader->load(
        $wrongGeneratedFile,
        $wrongGeneratedParameters->resolverList,
        [],
        $proxy,
    );
    verify($wrongGenerated !== null, 'Generated runtime-fallback resolver must load');
    throws(
        static fn() => $wrongGenerated->resolve(RequiredNumberExample::class),
        ResolutionException::class,
        'Generated runtime fallback must validate the resolver tuple position',
    );
    $checks += 2;

    // Pipeline mutation during code generation is rejected instead of producing
    // an artifact that can never match its own runtime.
    $mutating = new MutatingResolver();
    $mutatingParameters = new ParametersResolver($mutating);
    $mutating->chain = $mutatingParameters;
    $mutatingGenerators = new ParameterResolverCodeGeneratorRegistry();
    $mutatingGenerators->register(MutatingResolver::class, new FixedGenerator());
    $mutatingFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($mutatingParameters, $mutatingGenerators),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $mutatingEntryGenerator = new GeneratedEntryResolverGenerator(
        $mutatingFactory,
        $mutatingParameters,
        $emptyProcessor,
        $mutatingGenerators,
    );
    throws(
        static fn() => $mutatingEntryGenerator->generate([EagerExample::class], 'Verification\\Mutating'),
        LogicException::class,
        'Mutating an extension pipeline during code generation must fail fast',
    );
    $pass();

    $mutatingGeneratorRegistry = new ParameterResolverCodeGeneratorRegistry();
    $mutatingCodeGenerator = new MutatingCodeGeneratorRegistryGenerator();
    $mutatingCodeGenerator->registry = $mutatingGeneratorRegistry;
    $mutatingGeneratorRegistry->register(FixedResolver::class, $mutatingCodeGenerator);
    $mutatingGeneratorFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameterRuntime, $mutatingGeneratorRegistry),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $mutatingGeneratorEntry = new GeneratedEntryResolverGenerator(
        $mutatingGeneratorFactory,
        $parameterRuntime,
        $emptyProcessor,
        $mutatingGeneratorRegistry,
    );
    throws(
        static fn() => $mutatingGeneratorEntry->generate(
            [EagerExample::class],
            'Verification\MutatingGeneratorRegistry',
        ),
        LogicException::class,
        'Mutating the code-generator registry during compilation must fail fast',
    );
    $pass();

    $impure = new CountingResolver();
    $impureParameters = new ParametersResolver($impure);
    $impureGenerators = new ParameterResolverCodeGeneratorRegistry();
    $impureFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $impureParameters,
            $impureGenerators,
        ),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $impureEntryGenerator = new GeneratedEntryResolverGenerator(
        $impureFactory,
        $impureParameters,
        $emptyProcessor,
        $impureGenerators,
    );
    throws(
        static fn() => $impureEntryGenerator->generate(
            [EagerExample::class],
            'Verification\ImpureApplicability',
        ),
        LogicException::class,
        'supports() must be observationally pure during generated-code compilation',
    );
    $pass();

    // Source fingerprint includes custom code-generator implementation files.
    $externalFile = sys_get_temp_dir() . '/componenta-di-external-generator.php';
    file_put_contents($externalFile, <<<'PHP'
<?php
namespace Verification\External;
final class Generator implements \Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface
{
    public function generate(
        \Componenta\DI\Resolver\Parameter\ParameterResolverInterface $resolver,
        \Componenta\DI\Resolver\Target\ParameterTarget $target,
        \Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext $context,
    ): \Componenta\DI\Compile\Parameter\GeneratedResolverCode {
        return \Componenta\DI\Compile\Parameter\GeneratedResolverCode::terminal(
            sprintf('%s = 7; goto %s;', $context->argumentVariable, $context->resolvedLabel),
        );
    }
}
PHP);
    require $externalFile;
    $externalRegistry = new ParameterResolverCodeGeneratorRegistry();
    $externalRegistry->register(FixedResolver::class, new Verification\External\Generator());
    $externalFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($parameterRuntime, $externalRegistry),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $externalGenerator = new GeneratedEntryResolverGenerator($externalFactory, $parameterRuntime, $emptyProcessor, $externalRegistry);
    $externalGeneratedFile = sys_get_temp_dir() . '/componenta-di-stage15-external.php';
    $writer->write($externalGeneratedFile, $externalGenerator->generate([EagerExample::class], 'Verification\\GeneratedExternal'));
    verify($loader->load($externalGeneratedFile, $parameterRuntime->resolverList, [], $proxy) !== null, 'Generated resolver must initially accept unchanged custom generator source');
    file_put_contents($externalFile, "\n// changed after generation\n", FILE_APPEND);
    same(null, $loader->load($externalGeneratedFile, $parameterRuntime->resolverList, [], $proxy), 'Changing custom code-generator source must invalidate generated resolver');
    $checks += 2;

    $dynamicSuffix = bin2hex(random_bytes(6));
    $dynamicNamespace = 'Verification\Dynamic' . $dynamicSuffix;
    $dynamicAttributeClass = $dynamicNamespace . '\DynamicValue';
    $dynamicEntryClass = $dynamicNamespace . '\DynamicEntry';
    $dynamicAttributeFile = sys_get_temp_dir() . '/componenta-di-dynamic-attribute-' . $dynamicSuffix . '.php';
    $dynamicEntryFile = sys_get_temp_dir() . '/componenta-di-dynamic-entry-' . $dynamicSuffix . '.php';
    $dynamicGeneratedFile = sys_get_temp_dir() . '/componenta-di-stage15-dynamic-' . $dynamicSuffix . '.php';
    file_put_contents($dynamicAttributeFile, sprintf(<<<'PHP'
<?php
namespace %s;
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class DynamicValue
{
    public function __construct(public int $value) {}
}
PHP, $dynamicNamespace));
    file_put_contents($dynamicEntryFile, sprintf(<<<'PHP'
<?php
namespace %s;
final class DynamicEntry
{
    #[DynamicValue(123)]
    public int $value;
}
PHP, $dynamicNamespace));
    require $dynamicAttributeFile;
    require $dynamicEntryFile;
    $dynamicRegistry = new AttributeHandlerRegistry();
    $dynamicRegistry->add(new DynamicAttributeHandler($dynamicAttributeClass));
    $dynamicProcessor = new AttributeProcessor($dynamicRegistry);
    $dynamicParameters = new ParametersResolver();
    $dynamicParameterGenerators = new ParameterResolverCodeGeneratorRegistry();
    $dynamicFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $dynamicParameters,
            $dynamicParameterGenerators,
        ),
        $dynamicProcessor,
        new AttributeCodeGenerator(),
    );
    $dynamicGenerator = new GeneratedEntryResolverGenerator(
        $dynamicFactory,
        $dynamicParameters,
        $dynamicProcessor,
        $dynamicParameterGenerators,
    );
    $writer->write($dynamicGeneratedFile, $dynamicGenerator->generate(
        [$dynamicEntryClass],
        'Verification\GeneratedDynamic' . $dynamicSuffix,
    ));
    $dynamicResolver = $loader->load(
        $dynamicGeneratedFile,
        [],
        $dynamicProcessor->registry->handlers,
        $proxy,
    );
    verify($dynamicResolver !== null, 'Generated resolver must accept unchanged attribute implementation source');
    same(123, $dynamicResolver->resolve($dynamicEntryClass)->value, 'Dynamic attribute handler must run before source invalidation');
    file_put_contents($dynamicAttributeFile, "
// changed attribute implementation
", FILE_APPEND);
    same(
        null,
        $loader->load($dynamicGeneratedFile, [], $dynamicProcessor->registry->handlers, $proxy),
        'Changing an attribute implementation source must invalidate generated resolver',
    );
    $checks += 3;

    // Unsupported attributes are part of the generated artifact's source
    // contract too: changing one can make it inherit an already-supported
    // attribute in the next deployment even though the entry itself is
    // unchanged.
    $unsupportedSuffix = bin2hex(random_bytes(6));
    $unsupportedNamespace = 'Verification\\Unsupported' . $unsupportedSuffix;
    $unsupportedAttributeClass = $unsupportedNamespace . '\\Marker';
    $unsupportedEntryClass = $unsupportedNamespace . '\\Entry';
    $unsupportedAttributeFile = sys_get_temp_dir() . '/componenta-di-unsupported-attribute-' . $unsupportedSuffix . '.php';
    $unsupportedEntryFile = sys_get_temp_dir() . '/componenta-di-unsupported-entry-' . $unsupportedSuffix . '.php';
    $unsupportedGeneratedFile = sys_get_temp_dir() . '/componenta-di-stage16-unsupported-' . $unsupportedSuffix . '.php';
    file_put_contents($unsupportedAttributeFile, sprintf(<<<'PHP'
<?php
namespace %s;
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Marker {}
PHP, $unsupportedNamespace));
    file_put_contents($unsupportedEntryFile, sprintf(<<<'PHP'
<?php
namespace %s;
#[Marker]
final class Entry {}
PHP, $unsupportedNamespace));
    require $unsupportedAttributeFile;
    require $unsupportedEntryFile;
    $unsupportedParameters = new ParametersResolver();
    $unsupportedProcessor = new AttributeProcessor(new AttributeHandlerRegistry());
    $unsupportedGenerators = new ParameterResolverCodeGeneratorRegistry();
    $unsupportedFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator($unsupportedParameters, $unsupportedGenerators),
        $unsupportedProcessor,
        new AttributeCodeGenerator(),
    );
    $unsupportedGenerator = new GeneratedEntryResolverGenerator(
        $unsupportedFactory,
        $unsupportedParameters,
        $unsupportedProcessor,
        $unsupportedGenerators,
    );
    $writer->write($unsupportedGeneratedFile, $unsupportedGenerator->generate(
        [$unsupportedEntryClass],
        'Verification\\GeneratedUnsupported' . $unsupportedSuffix,
    ));
    verify(
        $loader->load($unsupportedGeneratedFile, [], [], $proxy) !== null,
        'Generated resolver must initially accept unchanged unsupported attribute source',
    );
    file_put_contents($unsupportedAttributeFile, "\n// changed unsupported attribute implementation\n", FILE_APPEND);
    same(
        null,
        $loader->load($unsupportedGeneratedFile, [], [], $proxy),
        'Changing unsupported attribute source must invalidate generated resolver',
    );
    $checks += 2;

    // Empty-context factories with only declared defaults use native PHP
    // defaults instead of allocating the full runtime creation pipeline.
    $defaultParameters = new ParametersResolver(
        new ArrayResolver(),
        new DefaultValueResolver(),
    );
    $defaultGenerators = DefaultParameterResolverCodeGenerators::create();
    $defaultFactory = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $defaultParameters,
            $defaultGenerators,
        ),
        $emptyProcessor,
        new AttributeCodeGenerator(),
    );
    $optionalFactory = $defaultFactory->generate(
        OptionalDefaultsExample::class,
        'createOptionalDefaults',
    );
    verify(
        str_contains($optionalFactory->code, 'if ($parameters === [])'),
        'Optional declared defaults must generate an empty-context native fast path',
    );
    $noArgumentsFactory = $defaultFactory->generate(
        NoArgumentsExample::class,
        'createNoArguments',
    );
    verify(
        !str_contains($noArgumentsFactory->code, ObjectCreationContext::class),
        'A no-argument factory must compile to a direct new expression',
    );
    $customOptionalFactory = $factoryGenerator->generate(
        OptionalDefaultsExample::class,
        'createCustomOptional',
    );
    verify(
        !str_contains($customOptionalFactory->code, 'if ($parameters === [])'),
        'A context-independent custom resolver must disable the native-default fast path',
    );

    $fastEntryGenerator = new GeneratedEntryResolverGenerator(
        $defaultFactory,
        $defaultParameters,
        $emptyProcessor,
        $defaultGenerators,
    );
    $fastFile = sys_get_temp_dir() . '/componenta-di-stage15-fast.php';
    $writer->write($fastFile, $fastEntryGenerator->generate([
        OptionalDefaultsExample::class,
        NoArgumentsExample::class,
    ], 'Verification\GeneratedFast'));
    $fastResolver = $loader->load(
        $fastFile,
        $defaultParameters->resolverList,
        [],
        $proxy,
    );
    verify($fastResolver !== null, 'Native-default fast-path resolver must load');
    $fastDefault = $fastResolver->resolve(OptionalDefaultsExample::class);
    $fastOverride = $fastResolver->resolve(
        OptionalDefaultsExample::class,
        ['number' => 21],
    );
    same(13, $fastDefault->number, 'Native fast path must preserve declared defaults');
    same('default', $fastDefault->name, 'Native fast path must preserve all declared defaults');
    same(21, $fastOverride->number, 'Non-empty context must keep generated resolver semantics');
    verify(
        $fastResolver->resolve(NoArgumentsExample::class) instanceof NoArgumentsExample,
        'Direct no-argument factory must create the expected entry',
    );
    $checks += 8;

    // Atomic writer preserves previous valid file on invalid code.
    $stableFile = sys_get_temp_dir() . '/componenta-di-stage15-stable.php';
    $writer->write($stableFile, "<?php\nreturn 'stable';\n");
    $before = file_get_contents($stableFile);
    throws(
        static fn() => $writer->write($stableFile, "<?php\nthis is invalid php\n"),
        RuntimeException::class,
        'Invalid generated PHP must be rejected',
    );
    same($before, file_get_contents($stableFile), 'Atomic writer must preserve previous valid generated file');
    throws(
        static fn() => $writer->write(
            $stableFile,
            "<?php\nfinal class DuplicateGeneratedMethod { public function run(): void {} public function run(): void {} }\n",
        ),
        RuntimeException::class,
        'Compile-time fatal errors in generated PHP must be rejected',
    );
    same(
        $before,
        file_get_contents($stableFile),
        'Compile-time validation failure must preserve the previous generated file',
    );
    $checks += 4;

    // Composite insertion clears cached ownership and gives generated resolver precedence.
    $composite = new CompositeResolver();
    $reflectionOwner = new OwnerResolver('service', 'reflection');
    $generatedOwner = new OwnerResolver('service', 'generated');
    $composite->addResolver($reflectionOwner);
    throws(
        static fn() => $composite->addResolver($reflectionOwner),
        \InvalidArgumentException::class,
        'Composite resolver must reject duplicate resolver instances',
    );
    same('reflection', $composite->resolve('service'), 'Initial owner must be cached');
    verify($composite->addResolverBefore($generatedOwner, OwnerResolver::class), 'Insertion before a matching resolver must succeed');
    same('generated', $composite->resolve('service'), 'Insertion must clear owner cache and change precedence');
    $checks += 4;

    foreach ([
        $generatedFile,
        $generatedRetryFile,
        $compileSideEffectFile,
        $objectDefaultFile,
        $parentPrivateFile,
        $errorFile,
        $variadicFile,
        $typedFile,
        $scopedFile,
        $wrongGeneratedFile,
        $externalFile,
        $externalGeneratedFile,
        $dynamicAttributeFile,
        $dynamicEntryFile,
        $dynamicGeneratedFile,
        $unsupportedAttributeFile,
        $unsupportedEntryFile,
        $unsupportedGeneratedFile,
        $fastFile,
        $stableFile,
        $lateBoundAttributeFile,
        $closureFileA,
        $closureFileB,
        $anonymousFileA,
        $anonymousFileB,
    ] as $file) {
        @unlink($file);
    }

    echo "componenta-di verification passed: {$checks} checks\n";
}
