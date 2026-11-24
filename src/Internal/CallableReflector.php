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
}
