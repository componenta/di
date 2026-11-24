<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Error;
use ReflectionFunction;

/** Adapts the selected magic hook when a captured trampoline cannot preserve its arguments. @internal */
final class CallableMagicInvocation
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array{Closure,array{string,array<array-key,mixed>}}|null
     */
    public static function prepare(Closure $captured, array $arguments): ?array
    {
        $reflection = new ReflectionFunction($captured);
        if (!is_magic_closure_trampoline($reflection)) {
            return null;
        }

        $receiver = $reflection->getClosureThis();
        $scope = $reflection->getClosureScopeClass();
        $called = $reflection->getClosureCalledClass();
        $hook = $receiver === null ? '__callStatic' : '__call';
        if ($scope === null || $called === null || !$scope->hasMethod($hook)) {
            return null;
        }

        $handler = $scope->getMethod($hook)->getClosure($receiver);
        if ($receiver === null && $scope->getName() !== $called->getName()) {
            if ($called->isInternal()) {
                return null;
            }
            // Forward the captured hook itself to keep its declaration and
            // visibility, with the late static scope of the original route.
            $forward = static fn(string $name, array $values): mixed => \forward_static_call_array($handler, [$name, $values]);
            $handler = $forward->bindTo(null, $called->getName());
            if ($handler === null) {
                return null;
            }
        }

        return [$handler, [$reflection->getName(), self::pack($arguments)]];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array<array-key,mixed>
     */
    private static function pack(array $arguments): array
    {
        if (array_is_list($arguments)) {
            return $arguments;
        }
        $named = false;
        foreach ($arguments as $key => $_) {
            if (is_string($key)) {
                $named = true;
            } elseif ($named) {
                throw new Error('Cannot use positional argument after named argument');
            }
        }
        // PHP's magic argument array renumbers integer keys and retains refs.
        return array_merge($arguments);
    }
}
