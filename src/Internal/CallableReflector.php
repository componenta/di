<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;

/** Builds a DI-owned reflector for a concrete callable declaration. @internal */
final class CallableReflector
{
    public static function reflect(callable $callable): ReflectionFunction|ReflectionMethod|null
    {
        if ($callable instanceof Closure) {
            return new ReflectionFunction($callable);
        }
        if (is_array($callable)) {
            [$owner, $method] = $callable;
        } elseif (is_string($callable)) {
            $separator = strrpos($callable, '::');
            if ($separator === false) {
                return new ReflectionFunction($callable);
            }
            $owner = substr($callable, 0, $separator);
            $method = substr($callable, $separator + 2);
            if (str_contains($owner, '::') && !is_callable([$owner, $method])) {
                return null;
            }
        } elseif (is_object($callable)) {
            return new ReflectionMethod($callable::class, '__invoke');
        } else {
            throw new LogicException('Unsupported callable representation.');
        }

        if ($owner instanceof Closure && strcasecmp($method, '__invoke') === 0) {
            return new ReflectionFunction($owner);
        }
        $class = is_object($owner) ? $owner::class : $owner;
        if (!method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        return $reflection->isPublic() && (is_object($owner) || $reflection->isStatic())
            ? $reflection
            : null;
    }

    /**
     * Restores named argument binding for a captured magic method without
     * reading the receiver from the caller's potentially rebound array.
     *
     * @return (array{object|class-string,string}&callable)|null
     */
    public static function nativeMagicMethod(mixed $source, Closure $resolved): ?array
    {
        if (is_array($source)) {
            $method = $source[1] ?? null;
            if (!is_string($method) || str_contains($method, '::')) {
                // An unqualified pair may select a different declaration.
                return null;
            }
        } elseif (!is_string($source) || !str_contains($source, '::')) {
            return null;
        }

        $reflection = new ReflectionFunction($resolved);
        if (!is_magic_closure_trampoline($reflection)) {
            return null;
        }

        $receiver = $reflection->getClosureThis();
        $class = $reflection->getClosureCalledClass();
        $method = $reflection->getName();
        if ($class === null || str_contains($method, '::')) {
            return null;
        }

        // A diagnostic handler can rebind the source method after PHP selects
        // qualified dispatch. Use an unqualified pair only for the same hook.
        $hook = $receiver === null ? '__callStatic' : '__call';
        if (($receiver !== null && $receiver::class !== $class->getName())
            || !$class->hasMethod($hook)
            || $class->getMethod($hook)->getDeclaringClass()->getName() !== $reflection->getClosureScopeClass()?->getName()
            || ($class->hasMethod($method) && $class->getMethod($method)->isPublic())
        ) {
            return null;
        }

        /** @var array{object|class-string,string}&callable $target */
        $target = [$receiver ?? $class->getName(), $method];
        return $target;
    }
}
