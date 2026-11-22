<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Resolver\Entry\EntryParameters;

/**
 * Resolves and publishes entries through one attached composite.
 *
 * @internal
 */
final readonly class AttachedEntryBindings
{
    /**
     * @param Closure(EntryBindings, string, DefinitionInterface):DefinitionBinding $prepare
     * @param Closure(EntryBindings, DefinitionBinding, MutationRetirement):void $publish
     * @param Closure(EntryBindings, string, MutationRetirement):void $retire
     */
    public function __construct(
        private EntryBindings $resolver,
        private Closure $prepare,
        private Closure $publish,
        private Closure $retire,
    ) {}

    public function can(string $id): bool
    {
        return $this->resolver->can($id);
    }

    public function knows(string $id): bool
    {
        return $this->resolver->knows($id);
    }

    /** @param array<string|int,mixed>|EntryParameters $params */
    public function resolve(string $id, array|EntryParameters $params): mixed
    {
        return $this->resolver->resolve($id, $params);
    }

    public function tryResolveShared(string $id, mixed &$entry): bool
    {
        return $this->resolver->tryResolveShared($id, $entry);
    }

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
