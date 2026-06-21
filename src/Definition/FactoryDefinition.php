<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Entry produced by a factory callable.
 *
 * @example
 * ```php
 * new FactoryDefinition(fn(ContainerInterface $c, array $context) => new Service($c->get(Dep::class)))
 * ```
 */
final readonly class FactoryDefinition implements DefinitionInterface
{
    /**
     * @var callable(\Componenta\Config\ContainerValue, array<string|int, mixed>):mixed
     */
    public mixed $value;

    /**
     * @param callable(\Componenta\Config\ContainerValue, array<string|int, mixed>):mixed $value
     */
    public function __construct(callable $value)
    {
        $this->value = $value;
    }
}
