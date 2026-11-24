<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

use Componenta\DI\Internal\ResolutionMetadata;

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

    public function __construct(callable $value)
    {
        $this->value = is_array($value) ? ResolutionMetadata::snapshot($value) : $value;
    }
}
