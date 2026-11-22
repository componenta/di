<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\Caster\CasterNotFoundException;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\VariadicArguments;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Error;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** @internal */
function is_abstract_static_dispatch_error(Error $error, string $source): bool
{
    // Only the native probe's error is recoverable. Application autoload may
    // itself fail with the same error type and message.
    return $error::class === Error::class
        && $error->getFile() === $source
        && ($error->getTrace()[0]['function'] ?? null) === 'is_callable'
        && str_starts_with($error->getMessage(), 'Cannot call abstract method ');
}

/** @internal */
function is_closure_reflector(ReflectionFunctionAbstract $reflection): bool
{
    // Closure::__invoke has instance-specific metadata even though PHP reports
    // it as an internal method with isClosure() === false.
    return $reflection->isClosure()
        || ($reflection instanceof ReflectionMethod
            && $reflection->getDeclaringClass()->getName() === Closure::class
            && $reflection->getName() === '__invoke');
}

/** @internal Observes availability without invoking an autoloader. */
function is_attribute_class_loaded(string $attributeClass): bool
{
    return \class_exists($attributeClass, false)
        || \interface_exists($attributeClass, false)
        || \trait_exists($attributeClass, false);
}

/** @internal */
function is_magic_closure_trampoline(ReflectionFunctionAbstract $reflection): bool
{
    if (!$reflection instanceof ReflectionFunction || !$reflection->isInternal()) {
        return false;
    }

    $name = $reflection->getName();
    $scope = $reflection->getClosureScopeClass();
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
        $class = $reflection->getClosureCalledClass() ?? $scope;
        if ($class === null) {
            return false;
        }
        $candidate = [$class->getName(), $name];
    }

    if (!is_callable($candidate)) {
        return false;
    }

    return CallableReflector::reflect($candidate) === null;
}

/**
 * @internal
 * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
 */
function describe_attribute_target(
    ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
): string {
    return match (true) {
        $target instanceof ReflectionParameter => sprintf(
            '$%s of %s',
            $target->getName(),
            $target->getDeclaringClass() !== null
                ? $target->getDeclaringClass()->getName() . '::' . $target->getDeclaringFunction()->getName() . '()'
                : $target->getDeclaringFunction()->getName() . '()',
        ),
        $target instanceof ReflectionProperty => sprintf(
            '%s::$%s',
            $target->getDeclaringClass()->getName(),
            $target->getName(),
        ),
        $target instanceof ReflectionMethod => sprintf(
            '%s::%s()',
            $target->getDeclaringClass()->getName(),
            $target->getName(),
        ),
        default => $target->getName(),
    };
}

/** @internal */
function normalize_env_name(string $name): string
{
    return strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name);
}

/**
 * @internal
 * @param ReflectionClass<object> $class
 */
function is_entry_class_eligible(ReflectionClass $class): bool
{
    if ($class->isAnonymous()
        || $class->isInterface()
        || $class->isTrait()
        || $class->isAbstract()
        || $class->isEnum()
    ) {
        return false;
    }

    return $class->isInstantiable() || $class->isUserDefined();
}

/**
 * @internal
 * @param array<mixed> $result
 * @return array{0:int,1:mixed}
 */
function validate_parameter_resolution_result(
    array $result,
    ParameterResolverInterface $resolver,
    ParameterTarget $target,
    ParameterResolutionContext $context,
): array {
    if (array_keys($result) !== [0, 1]
        || !is_int($result[0])
        || $result[0] !== $target->position
    ) {
        throw ResolutionException::forParameter(
            $target->reflection,
            reason: sprintf(
                'resolver "%s" returned an invalid result; expected [position %d, value]',
                $resolver::class,
                $target->position,
            ),
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    // Callable validation can autoload code that changes referenced tuple slots.
    // Keep the position and value that were selected for this validation.
    [$position, $value] = $result;

    if ($target->variadic) {
        return [$position, VariadicArguments::validate($target, $value, $context)];
    }

    if (!$target->accepts($value)) {
        throw ResolutionException::forParameter(
            $target->reflection,
            reason: sprintf(
                'resolver "%s" returned a value that does not satisfy the declared type',
                $resolver::class,
            ),
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    return [$position, $value];
}

/**
 * @internal
 * @param array<string|int,mixed> $data
 * @param array<string|int,string> $map
 * @param array<string,mixed> $defaults
 * @param array<string,string> $cast
 * @param array<string,array<string,mixed>> $sortMap
 * @param list<string> $exclude
 * @return array<string|int,mixed>
 */
function transform_request_mapper_data(
    array $data,
    array $map,
    array $defaults,
    array $cast,
    array $sortMap,
    array $exclude,
    CasterProviderInterface $provider,
): array {
    if ($map !== []) {
        $moves = [];
        $mappedSources = [];
        $targetOwners = [];

        foreach ($map as $rawFrom => $rawTo) {
            $from = (string) $rawFrom;
            $to = $rawTo;
            $optional = $from !== '' && $from[0] === '?';
            if ($optional) {
                $from = substr($from, 1);
            }
            if ($to === '') {
                throw new InvalidArgumentException('Mapped target key cannot be empty');
            }
            if (!array_key_exists($from, $data)) {
                if ($optional) {
                    continue;
                }
                throw new InvalidArgumentException(sprintf('Required key "%s" is missing', $from));
            }
            if (isset($targetOwners[$to]) && $targetOwners[$to] !== $from) {
                throw new InvalidArgumentException(sprintf(
                    'Mapped target key "%s" is produced by both "%s" and "%s"',
                    $to,
                    $targetOwners[$to],
                    $from,
                ));
            }
            $targetOwners[$to] = $from;
            $mappedSources[$from] = true;
            $moves[] = ['from' => $from, 'to' => $to, 'value' => $data[$from]];
        }

        foreach ($moves as $move) {
            if ($move['from'] !== $move['to']
                && array_key_exists($move['to'], $data)
                && !isset($mappedSources[$move['to']])
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Mapped target key "%s" already exists in input',
                    $move['to'],
                ));
            }
        }

        foreach ($moves as $move) {
            if ($move['from'] !== $move['to']) {
                unset($data[$move['from']]);
            }
        }
        foreach ($moves as $move) {
            $data[$move['to']] = $move['value'];
        }
    }

    if ($cast !== [] || $sortMap !== []) {
        // Array copies retain referenced cells. Own the slots before replacing
        // values so transformation cannot write through to caller-owned data.
        $values = [];
        foreach ($data as $key => $value) {
            $values[$key] = $value;
        }
        // Preserve the original next append key while replacing referenced slots.
        $data = array_replace($data, $values);
        unset($values, $value);
    }

    foreach ($cast as $key => $casterName) {
        if (array_key_exists($key, $data)) {
            $caster = $provider->provide($casterName)
                ?? throw CasterNotFoundException::forName($casterName);
            $data[$key] = $caster->cast($data[$key]);
        }
    }

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
        }
    }

    if ($sortMap !== []) {
        if (!array_key_exists('sort', $data)) {
            $data['orderBy'] = null;
        } else {
            $sort = $data['sort'];
            if (!is_string($sort) && !is_int($sort)) {
                throw new InvalidArgumentException(sprintf(
                    'Sort alias must be a string or integer; got %s.',
                    get_debug_type($sort),
                ));
            }
            $data['orderBy'] = $sortMap[(string) $sort] ?? null;
        }
        unset($data['sort'], $data['order']);
    }

    foreach ($exclude as $key) {
        unset($data[$key]);
    }

    return $data;
}
