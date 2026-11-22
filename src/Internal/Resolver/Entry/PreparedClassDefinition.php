<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver;

/** Owns the validated class arguments without publishing their reference graph. @internal */
final readonly class PreparedClassDefinition implements DefinitionInterface
{
    /** @param Closure(FactoryResolver,array<string|int,mixed>):object $create */
    public function __construct(private Closure $create) {}

    /** @param array<string|int,mixed> $params */
    public function resolve(FactoryResolver $resolver, array $params): object
    {
        return ($this->create)($resolver, $params);
    }
}
