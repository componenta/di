<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\Config\ContainerValue;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\NullContainer;
use Componenta\Reflection\Reflection;
use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use WeakMap;

/** Validates the public configuration forms accepted by FactoryResolver. */
final class FactorySpecificationValidator
{
    /** @var WeakMap<ReflectionFunctionAbstract, true>|null */
    private static ?WeakMap $validatedCallables = null;

    private static ?ContainerValue $containerArgument = null;

    public static function assertValid(string $id, mixed $factory): void
    {
        if ($factory instanceof FactoryDefinition) {
            self::assertKnownCallable($id, $factory->value);
            return;
        }

        if ($factory instanceof ClassDefinition) {
            self::assertValidClassDefinition($id, $factory);
            return;
        }

        if ($factory instanceof CompiledFactoryDefinition) {
            if (CompiledFactoryDefinition::decode($factory->encode()) !== null) {
                return;
            }

            throw new InvalidConfigurationException(sprintf(
                'Compiled factory definition for "%s" is malformed.',
                $id,
            ));
        }

        if (CompiledFactoryDefinition::isEncodedValue($factory)) {
            if (CompiledFactoryDefinition::decode($factory) !== null) {
                return;
            }

            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" contains a malformed compiled factory definition.',
                $id,
            ));
        }

        // A string callable is ambiguous with a factory service id. FactoryResolver
        // gives an existing service id precedence, so validate the resolved callable
        // only after that lookup has happened.
        if (is_string($factory) && $factory !== '') {
            return;
        }

        if (is_callable($factory)) {
            self::assertResolvedCallable($id, $factory);
            return;
        }

        if (self::isDeferredCallable($factory)) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Factory "%s" must be callable, a non-empty service id, '
            . '[service|object, method], FactoryDefinition, ClassDefinition '
            . 'or CompiledFactoryDefinition; got %s.',
            $id,
            get_debug_type($factory),
        ));
    }

    /**
     * Validates a callable after factory-service resolution removed any ambiguity
     * between a callable string and a service id.
     */
    public static function assertResolvedCallable(string $id, callable $factory): void
    {
        // Lazy factories use LazyServiceFactoryInterface::lazy(), not __invoke().
        // The interface itself fixes that method's runtime signature.
        if ($factory instanceof LazyServiceFactoryInterface) {
            return;
        }

        try {
            $reflection = Reflection::callable($factory);
        } catch (InvalidArgumentException) {
            // Magic __call()/__callStatic() callables have no concrete method whose
            // signature can be inspected. Keep them valid and defer to PHP dispatch.
            return;
        }

        $validated = self::$validatedCallables ??= new WeakMap();
        if (isset($validated[$reflection])) {
            return;
        }

        if (self::isMagicClosureTrampoline($reflection)) {
            $validated[$reflection] = true;
            return;
        }

        self::assertValidArgumentCount($id, $reflection);

        $parameters = $reflection->getParameters();
        $scope = self::callableScope($reflection);
        self::assertRuntimeArgumentCompatible(
            $id,
            $parameters,
            0,
            self::containerArgument(),
            $scope,
        );
        self::assertRuntimeArgumentCompatible($id, $parameters, 1, [], $scope);

        $validated[$reflection] = true;
    }

    private static function assertKnownCallable(string $id, callable $factory): void
    {
        // FactoryDefinition currently unwraps to the same raw string used for a
        // service id, so preserve FactoryResolver's service-id precedence here too.
        if (is_string($factory)) {
            return;
        }

        self::assertResolvedCallable($id, $factory);
    }

    private static function assertValidArgumentCount(
        string $id,
        ReflectionFunctionAbstract $reflection,
    ): void {
        $required = $reflection->getNumberOfRequiredParameters();
        if ($required > 2) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" callable requires %d arguments, but the factory runtime supplies 2.',
                $id,
                $required,
            ));
        }

        if ($reflection->isInternal()
            && !$reflection->isVariadic()
            && $reflection->getNumberOfParameters() < 2
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" internal callable accepts at most %d arguments, '
                . 'but the factory runtime supplies 2.',
                $id,
                $reflection->getNumberOfParameters(),
            ));
        }
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param ReflectionClass<object>|null $scope
     */
    private static function assertRuntimeArgumentCompatible(
        string $id,
        array $parameters,
        int $position,
        mixed $argument,
        ?ReflectionClass $scope,
    ): void {
        $parameter = self::parameterForPosition($parameters, $position);
        if ($parameter === null) {
            return;
        }

        $type = $parameter->getType();
        if ($type === null) {
            return;
        }

        try {
            if (ReflectionType::match($type, $argument, strict: true, scope: $scope)) {
                return;
            }
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigurationException(
                sprintf(
                    'Factory "%s" callable parameter #%d ($%s) type "%s" cannot be validated: %s',
                    $id,
                    $position + 1,
                    $parameter->getName(),
                    (string) $type,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }

        throw new InvalidConfigurationException(sprintf(
            'Factory "%s" callable parameter #%d ($%s) has incompatible type "%s"; '
            . 'the factory runtime passes %s in this position.',
            $id,
            $position + 1,
            $parameter->getName(),
            (string) $type,
            get_debug_type($argument),
        ));
    }

    /** @param list<ReflectionParameter> $parameters */
    private static function parameterForPosition(array $parameters, int $position): ?ReflectionParameter
    {
        if (isset($parameters[$position])) {
            return $parameters[$position];
        }

        if ($parameters === []) {
            return null;
        }

        $last = $parameters[count($parameters) - 1];

        return $last->isVariadic() ? $last : null;
    }

    /** @return ReflectionClass<object>|null */
    private static function callableScope(ReflectionFunctionAbstract $reflection): ?ReflectionClass
    {
        if ($reflection instanceof ReflectionMethod) {
            return $reflection->getDeclaringClass();
        }

        if ($reflection instanceof ReflectionFunction) {
            return $reflection->getClosureScopeClass();
        }

        return null;
    }

    /**
     * PHP represents __call()/__callStatic() closures as internal trampolines.
     * Their reflected parameter list depends on how the closure was created and
     * does not reliably describe the positional arguments accepted by magic dispatch.
     */
    private static function isMagicClosureTrampoline(
        ReflectionFunctionAbstract $reflection,
    ): bool {
        if (!$reflection instanceof ReflectionFunction || !$reflection->isInternal()) {
            return false;
        }

        $name = $reflection->getName();
        $scope = $reflection->getClosureScopeClass();

        // ReflectionMethod::getClosure() may expose a non-public internal method.
        // It retains that method's declaring scope even when bound to a subclass
        // whose __call() would make a reconstructed callable look like magic dispatch.
        if ($scope !== null && $scope->hasMethod($name)) {
            $method = $scope->getMethod($name);
            if ($method->isInternal()
                && $method->getDeclaringClass()->getName() === $scope->getName()
            ) {
                return false;
            }
        }

        $bound = $reflection->getClosureThis();
        if ($bound !== null) {
            $candidate = [$bound, $name];
        } else {
            $class = $reflection->getClosureCalledClass()
                ?? $reflection->getClosureScopeClass();
            if ($class === null) {
                return false;
            }

            $candidate = [$class->getName(), $name];
        }

        if (!is_callable($candidate)) {
            return false;
        }

        try {
            Reflection::callable($candidate);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    }

    private static function containerArgument(): ContainerValue
    {
        return self::$containerArgument ??= new ContainerValue(new NullContainer());
    }

    private static function assertValidClassDefinition(
        string $id,
        ClassDefinition $definition,
    ): void {
        $className = self::runtimeClassName($definition);

        if (!class_exists($className) && !interface_exists($className)) {
            throw new InvalidConfigurationException(sprintf(
                'Class definition for "%s" targets unavailable class "%s".',
                $id,
                $className,
            ));
        }

        /** @var class-string $className */
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass($className);

        if (!$class->isInstantiable()) {
            throw new InvalidConfigurationException(sprintf(
                'Class definition for "%s" targets non-instantiable class "%s".',
                $id,
                $className,
            ));
        }

        $magicCall = $class->hasMethod('__call')
            && $class->getMethod('__call')->isPublic();

        foreach ($definition->methodCalls as $index => $call) {
            if (!is_array($call)
                || !array_key_exists('method', $call)
                || !is_string($call['method'])
                || $call['method'] === ''
                || !array_key_exists('params', $call)
                || !is_array($call['params'])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Class definition for "%s" contains malformed method call #%d; '
                    . 'expected array{method: non-empty-string, params: array}.',
                    $id,
                    $index,
                ));
            }

            $method = $call['method'];

            if (!$class->hasMethod($method)) {
                if ($magicCall) {
                    continue;
                }

                throw new InvalidConfigurationException(sprintf(
                    'Class definition for "%s" calls missing method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }

            if (!$class->getMethod($method)->isPublic() && !$magicCall) {
                throw new InvalidConfigurationException(sprintf(
                    'Class definition for "%s" calls non-public method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }
        }
    }

    /**
     * Treat the documented class-string as untrusted runtime input at the
     * configuration boundary; PHP cannot enforce the PHPDoc type itself.
     */
    private static function runtimeClassName(ClassDefinition $definition): string
    {
        return $definition->value;
    }

    private static function isDeferredCallable(mixed $factory): bool
    {
        if (!is_array($factory)
            || array_keys($factory) !== [0, 1]
            || !is_string($factory[1])
            || $factory[1] === ''
        ) {
            return false;
        }

        if (is_object($factory[0])) {
            return false;
        }

        // A string owner may be an opaque service id. Its object and method
        // cannot be validated until the container resolves that service.
        return is_string($factory[0]) && $factory[0] !== '';
    }

    private function __construct() {}
}
