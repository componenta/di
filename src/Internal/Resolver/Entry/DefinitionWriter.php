<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Resolver\Entry\CompositeResolver;

/** Writes to the binding table of one attached composite. @internal */
final readonly class DefinitionWriter
{
    /**
     * @param Closure(CompositeResolver, string, DefinitionInterface):DefinitionBinding $prepare
     * @param Closure(CompositeResolver, DefinitionBinding, MutationRetirement):void $publish
     * @param Closure(CompositeResolver, string, MutationRetirement):void $retire
     */
    public function __construct(
        private CompositeResolver $resolver,
        private Closure $prepare,
        private Closure $publish,
        private Closure $retire,
    ) {}

    public function prepare(string $id, DefinitionInterface $definition): DefinitionBinding
    {
        return ($this->prepare)($this->resolver, $id, $definition);
    }

    public function publish(DefinitionBinding $binding, MutationRetirement $retirement): void
    {
        ($this->publish)($this->resolver, $binding, $retirement);
    }

    public function retire(string $id, MutationRetirement $retirement): void
    {
        ($this->retire)($this->resolver, $id, $retirement);
    }
}
