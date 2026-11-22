<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\CallableReflector;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\Reflection\ReflectionType;
use Error;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use WeakMap;

use function Componenta\DI\Internal\is_abstract_static_dispatch_error;
use function Componenta\DI\Internal\is_entry_class_eligible;
use function Componenta\DI\Internal\is_magic_closure_trampoline;

/**
 * Validates factory forms and their runtime `(ContainerValue, array)` ABI.
 *
 * @internal
 */
final class FactorySpecificationValidator
{
    /** @var WeakMap<Closure,true>|null */
    private static ?WeakMap $validatedClosures = null;
    /** @var array<string,true> */
    private static array $validatedDeclarations = [];
    private static ?ContainerValue $containerArgument = null;

    public static function assertValid(string $id, mixed $factory): void
    {
        if ($factory instanceof FactoryDefinition) {
            self::assertKnownCallable($id, $factory->value);
            return;
        }
        if ($factory instanceof ClassDefinition) {
            self::assertClassDefinition($id, $factory);
            return;
        }
        if ($factory instanceof LazyServiceFactoryInterface) {
            return;
        }

        if (is_string($factory) && $factory !== '') {
            return;
        }
        try {
            if (is_callable($factory)) {
                self::assertResolvedCallable($id, $factory);
                return;
            }
        } catch (Error $error) {
            if (!is_abstract_static_dispatch_error($error, __FILE__)) {
                throw $error;
            }
        }
        if (self::isDeferredCallable($factory)) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Factory "%s" has unsupported type %s.',
            $id,
            get_debug_type($factory),
        ));
    }

    public static function assertResolvedCallable(string $id, callable $factory): void
    {
        if ($factory instanceof LazyServiceFactoryInterface) {
            return;
        }

        if (is_array($factory)) {
            if ($factory[0] instanceof Closure && strcasecmp($factory[1], '__invoke') === 0) {
                $factory = $factory[0];
            } elseif (str_contains($factory[1], '::')) {
                $factory = Closure::fromCallable($factory);
            }
        } elseif (is_string($factory) && substr_count($factory, '::') > 1) {
            // PHP permits class aliases that contain the method separator.
            $factory = Closure::fromCallable($factory);
        }

        if ($factory instanceof Closure) {
            $validated = self::$validatedClosures ??= new WeakMap();
            if (!isset($validated[$factory])) {
                $reflection = new ReflectionFunction($factory);
                if (!is_magic_closure_trampoline($reflection)) {
                    self::assertSignature($id, $reflection);
                }
                $validated[$factory] = true;
            }
            return;
        }

        $reflection = CallableReflector::reflect($factory);
        if ($reflection === null) {
            return;
        }
        $key = $reflection instanceof ReflectionMethod
            ? $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName()
            : $reflection->getName();
        if (!isset(self::$validatedDeclarations[$key])) {
            self::assertSignature($id, $reflection);
            self::$validatedDeclarations[$key] = true;
        }
    }

    private static function assertSignature(string $id, ReflectionFunctionAbstract $reflection): void
    {
        self::assertArgumentCount($id, $reflection);

        /** @var list<ReflectionParameter> $parameters */
        $parameters = array_values($reflection->getParameters());
        $scope = self::callableScope($reflection);
        self::assertArgument($id, $parameters, 0, self::containerArgument(), $scope);
        self::assertArgument($id, $parameters, 1, [], $scope);
    }

    private static function assertKnownCallable(string $id, callable $factory): void
    {
        if (!is_string($factory)) {
            self::assertResolvedCallable($id, $factory);
        }
    }

    private static function assertArgumentCount(string $id, ReflectionFunctionAbstract $reflection): void
    {
        $required = $reflection->getNumberOfRequiredParameters();
        if ($required > 2) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" requires %d arguments, but the factory runtime supplies 2.',
                $id,
                $required,
            ));
        }

        if ($reflection->isInternal()
            && !$reflection->isVariadic()
            && $reflection->getNumberOfParameters() < 2
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" internal callable accepts at most %d arguments, but the factory runtime supplies 2.',
                $id,
                $reflection->getNumberOfParameters(),
            ));
        }
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param ReflectionClass<object>|null $scope
     */
    private static function assertArgument(
        string $id,
        array $parameters,
        int $position,
        mixed $argument,
        ?ReflectionClass $scope,
    ): void {
        $parameter = self::parameterAt($parameters, $position);
        if ($parameter === null || $parameter->getType() === null) {
            return;
        }

        try {
            $valid = ReflectionType::match(
                $parameter->getType(),
                $argument,
                strict: true,
                scope: $scope,
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" parameter #%d type cannot be validated: %s',
                $id,
                $position + 1,
                $e->getMessage(),
            ), previous: $e);
        }

        if (!$valid) {
            throw new InvalidConfigurationException(sprintf(
                'Factory "%s" parameter #%d ($%s) type "%s" is incompatible with runtime argument %s.',
                $id,
                $position + 1,
                $parameter->getName(),
                (string) $parameter->getType(),
                get_debug_type($argument),
            ));
        }
    }

    /** @param list<ReflectionParameter> $parameters */
    private static function parameterAt(array $parameters, int $position): ?ReflectionParameter
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

    private static function containerArgument(): ContainerValue
    {
        if (self::$containerArgument !== null) {
            return self::$containerArgument;
        }

        /** @var ReflectionClass<ContainerValue> $class */
        $class = new ReflectionClass(ContainerValue::class);

        return self::$containerArgument = $class->newInstanceWithoutConstructor();
    }

    private static function assertClassDefinition(string $id, ClassDefinition $definition): void
    {
        $className = $definition->value;
        // The interface probe can autoload a class after the first probe failed.
        if (!class_exists($className) && !interface_exists($className) && !class_exists($className, false)) {
            throw new InvalidConfigurationException(sprintf(
                'ClassDefinition for "%s" references unknown class "%s".',
                $id,
                $className,
            ));
        }

        /** @var class-string $className */
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass($className);
        if (!is_entry_class_eligible($class) || !$class->isInstantiable()) {
            throw new InvalidConfigurationException(sprintf(
                'ClassDefinition for "%s" targets runtime-ineligible class "%s".',
                $id,
                $className,
            ));
        }

        $magicCall = $class->hasMethod('__call') && $class->getMethod('__call')->isPublic();
        foreach ($definition->methodCalls as $index => $call) {
            if (!is_array($call)
                || !array_key_exists('method', $call)
                || !is_string($call['method'])
                || $call['method'] === ''
                || !array_key_exists('params', $call)
                || !is_array($call['params'])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'ClassDefinition for "%s" contains malformed method call #%d.',
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
                    'ClassDefinition for "%s" calls missing method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }

            if (!$class->getMethod($method)->isPublic() && !$magicCall) {
                throw new InvalidConfigurationException(sprintf(
                    'ClassDefinition for "%s" calls non-public method "%s::%s".',
                    $id,
                    $className,
                    $method,
                ));
            }
        }
    }

    private static function isDeferredCallable(mixed $factory): bool
    {
        return is_array($factory)
            && array_keys($factory) === [0, 1]
            && is_string($factory[0])
            && $factory[0] !== ''
            && is_string($factory[1])
            && $factory[1] !== '';
    }

    private function __construct() {}
}
