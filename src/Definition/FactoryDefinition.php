<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Entry produced by a factory callable.
 *
 * @example
 * ```php
 * new FactoryDefinition(fn(ContainerInterface $c) => new Service($c->get(Dep::class)))
 * ```
 */
final readonly class FactoryDefinition implements DefinitionInterface
{
    /**
     * @var callable
     */
    public mixed $value;

    public function __construct(callable $value)
    {
        $this->value = $value;
    }
}
