<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

use function Componenta\DI\Internal\require_public_callable;

/**
 * Entry produced by a factory callable.
 *
 * Factory callables may declare any 0/1/2-argument prefix compatible with the
 * runtime `(ContainerValue, array)` invocation ABI.
 *
 * @example
 * ```php
 * new FactoryDefinition(fn(ContainerValue $c, array $params) => new Service($c->container->get(Dep::class)))
 * ```
 */
final readonly class FactoryDefinition implements DefinitionInterface
{
    /** @var callable */
    public mixed $value;

    /** @param callable $value */
    public function __construct(callable|array $value)
    {
        // The array branch defers callable validation until its slots are copied.
        $this->value = is_array($value)
            ? require_public_callable(array_map(static fn(mixed $slot): mixed => $slot, $value))
            : $value;
    }
}
