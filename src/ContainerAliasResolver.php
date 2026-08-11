<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Cache-aware public alias-management facade exposed by the container.
 *
 * Resolution and lookup delegate to the canonical alias registry, while
 * mutations go through Container::alias() so cached entries and delegator
 * callable caches are invalidated consistently.
 *
 * @internal
 */
final readonly class ContainerAliasResolver implements AliasResolverInterface
{
    public function __construct(
        private AliasResolverInterface $aliases,
        private Container $container,
    ) {}

    public function resolve(string $id): string
    {
        return $this->aliases->resolve($id);
    }

    public function set(string $alias, string $target): static
    {
        $this->container->alias($alias, $target);

        return $this;
    }

    public function has(string $alias): bool
    {
        return $this->aliases->has($alias);
    }
}
