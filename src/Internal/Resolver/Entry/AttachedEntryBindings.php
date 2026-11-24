<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;

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

    public function hasBinding(string $id): bool
    {
        return $this->resolver->hasBinding($id);
    }

    /** @param Closure():bool $hasSharedValue */
    public function can(string $id, Closure $hasSharedValue): bool
    {
        return $this->resolver->can($id, $hasSharedValue);
    }

    /** @param Closure():bool $hasSharedValue */
    public function knows(string $id, Closure $hasSharedValue): bool
    {
        return $this->resolver->knows($id, $hasSharedValue);
    }

    /** @param array<string|int,mixed>|EntryParameters $params */
    public function resolve(string $id, array|EntryParameters $params): mixed
    {
        return $this->resolver->resolve($id, $params);
    }

    /** @param Closure():bool $hasSharedValue */
    public function selectShared(string $id, Closure $hasSharedValue): DefinitionBinding|EntryResolverInterface|null
    {
        return $this->resolver->select($id, $hasSharedValue);
    }

    public function resolveSelectedShared(string $id, DefinitionBinding|EntryResolverInterface $selected): mixed
    {
        return $this->resolver->resolveSelected($id, $selected, []);
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
