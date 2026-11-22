<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Internal\Resolver\Entry\AttachedEntryBindings;
use Componenta\DI\Internal\Resolver\Entry\CompositeScope;
use Componenta\DI\Internal\Resolver\Entry\EntryBindings;
use Componenta\DI\Internal\RuntimeCollaboratorOwnership;

/** Composes explicit definitions and ordered fallback resolvers. */
final class CompositeResolver extends CompositeScope implements EntryResolverInterface
{
    private readonly EntryBindings $bindings;

    public function __construct(EntryResolverInterface|DefinitionResolverInterface ...$providers)
    {
        $this->bindings = new EntryBindings(...array_map(
            static fn(EntryResolverInterface|DefinitionResolverInterface $provider): EntryBindings|EntryResolverInterface|DefinitionResolverInterface
                => $provider instanceof self ? $provider->bindings : $provider,
            $providers,
        ));
    }

    public function can(string $id): bool
    {
        return $this->bindings->can($id);
    }

    /** @param array<string|int,mixed>|EntryParameters $params */
    public function resolve(string $id, array|EntryParameters $params = []): mixed
    {
        return $this->bindings->resolve($id, $params);
    }

    /** Registers a definition in a standalone composition. Attached roots use Container::set(). */
    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        $this->bindings->setDefinition($id, $definition);
    }

    protected function attach(RuntimeCollaboratorOwnership $ownership): AttachedEntryBindings
    {
        return $this->bindings->attach($ownership);
    }

    private function __clone() {}
}
