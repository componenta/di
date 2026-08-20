<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/** Materializes a native parameter default from a fresh reflector. @internal */
final class ParameterDefaultValue
{
    public static function materialize(ParameterTarget $target): mixed
    {
        if (!$target->hasDefault) {
            throw new LogicException(sprintf(
                'Parameter "$%s" of %s has no native default value.',
                $target->name,
                $target->declaringContext,
            ));
        }

        $function = self::freshFunction($target->reflection->getDeclaringFunction());
        $parameter = $function->getParameters()[$target->position] ?? null;
        if ($parameter === null || !$parameter->isDefaultValueAvailable()) {
            throw new LogicException(sprintf(
                'Native default metadata for parameter "$%s" of %s is unavailable.',
                $target->name,
                $target->declaringContext,
            ));
        }

        return $parameter->getDefaultValue();
    }

    private static function freshFunction(ReflectionFunctionAbstract $function): ReflectionFunctionAbstract
    {
        if ($function instanceof ReflectionMethod) {
            return new ReflectionMethod(
                $function->getDeclaringClass()->getName(),
                $function->getName(),
            );
        }

        if (!$function instanceof ReflectionFunction) {
            throw new LogicException(sprintf(
                'Unsupported declaring function reflector %s.',
                $function::class,
            ));
        }

        if ($function->isClosure()) {
            return new ReflectionFunction($function->getClosure());
        }

        return new ReflectionFunction($function->getName());
    }

    private function __construct() {}
}
