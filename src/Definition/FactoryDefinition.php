<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Entry produced by a factory callable.
 *
 * Factory callables may declare any 0/1/2-argument prefix compatible with the
 * runtime `(ContainerValue, array)` invocation ABI.
 *
 * @example
 * ```php
 * new FactoryDefinition(fn(ContainerInterface $c, array $context) => new Service($c->get(Dep::class)))
 * ```
 */
final readonly class FactoryDefinition implements DefinitionInterface
{
    /** @var callable */
    public mixed $value;

    public function __construct(callable $value)
    {
        $this->value = $value;
    }
}
