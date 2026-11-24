<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

/**
 * Validates an append-only alias graph in declaration order.
 *
 * @internal
 */
final class AliasGraphBuilder
{
    /** @var array<string, non-empty-string> */
    public private(set) array $aliases = [];

    /** @var array<string, non-empty-string> */
    private array $parents = [];

    /** @param array<string, string> $aliases */
    public function __construct(array $aliases = [])
    {
        foreach ($aliases as $alias => $target) {
            if (!is_string($alias) || !is_string($target)) {
                throw new InvalidConfigurationException('Alias ids and targets must be strings.');
            }
            $this->append($alias, $target);
        }
    }

    public function append(string $alias, string $target): void
    {
        if ($alias === '') {
            throw new InvalidConfigurationException('Alias id must be a non-empty string.');
        }
        if ($target === '') {
            throw new InvalidConfigurationException('Alias target must be a non-empty DI id.');
        }
        if ($alias === $target) {
            throw InvalidConfigurationException::forSelfReferencingAlias($alias);
        }
        if (isset($this->aliases[$alias])) {
            throw new LogicException('An alias can only be appended once.');
        }

        $terminal = $this->resolve($target);
        if ($terminal === $alias) {
            // Follow the original edges only for the error path.
            $path = [$alias];
            for ($current = $target; $current !== $alias; $current = $this->aliases[$current]) {
                $path[] = $current;
            }
            throw CircularDependencyException::forAlias([...$path, $alias]);
        }
        $this->aliases[$alias] = $target;
        $this->parents[$alias] = $terminal;
    }

    /**
     * An endpoint may become an alias in a later append. Its parent link
     * redirects previously compressed paths without walking every ancestor.
     *
     * @return ($id is non-empty-string ? non-empty-string : string)
     */
    public function resolve(string $id): string
    {
        while (isset($this->parents[$id])) {
            $parent = $this->parents[$id];
            $next = $this->parents[$parent] ?? $parent;
            $this->parents[$id] = $next;
            $id = $next;
        }
        return $id;
    }
}
