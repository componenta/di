<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use WeakMap;

/**
 * Resolves aliases to their target identifiers.
 *
 * Uses path compression so every alias encountered in a successfully resolved
 * chain becomes an O(1) lookup until the map changes.
 *
 * @internal
 */
final class AliasResolver
{
    private const string ATTACHED_WRITE_ERROR = 'Change an attached alias resolver through Container::alias().';

    /** @var WeakMap<self, object>|null */
    private static ?WeakMap $owners = null;

    public private(set) int $revision = 0;

    /** @var array<string, string> Cache of fully resolved aliases. */
    private array $resolved = [];

    /** @var array<string, string> */
    private array $map;

    /** @param array<string, string> $map Alias to target map. */
    public function __construct(array $map = [])
    {
        $owners = self::$owners ??= new WeakMap();
        $resolver = $this;
        if (isset($owners[$resolver])) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }
        $routes = $map === [] ? [] : (new AliasGraphBuilder($map))->aliases;
        if (isset($owners[$resolver])) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }

        ++$this->revision;
        $this->map = $routes;
        $this->resolved = [];
    }

    /** @internal */
    public static function fromGraph(AliasGraphBuilder $graph): self
    {
        $resolver = new self();
        $resolver->map = $graph->aliases;
        return $resolver;
    }

    public function attach(RuntimeCollaboratorOwnership $ownership, bool &$rootAttached): void
    {
        $ownership->claim(self::$owners ??= new WeakMap(), $rootAttached);
    }

    public function resolve(string $id): string
    {
        if (!isset($this->map[$id])) {
            return $id;
        }

        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        $current = $id;
        $path = [];
        $visited = [];

        while (isset($this->map[$current])) {
            $path[] = $current;
            $visited[$current] = true;
            $next = $this->map[$current];

            if (isset($visited[$next])) {
                throw CircularDependencyException::forAlias([...$path, $next]);
            }

            if (isset($this->resolved[$next])) {
                $current = $this->resolved[$next];
                break;
            }

            $current = $next;
        }

        foreach ($path as $alias) {
            $this->resolved[$alias] = $current;
        }

        return $current;
    }

    public function set(string $alias, string $target): static
    {
        $owners = self::$owners ??= new WeakMap();
        $resolver = $this;
        if (isset($owners[$resolver])) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }
        self::assertNonEmpty($alias, $target);

        if ($alias === $target) {
            throw InvalidConfigurationException::forSelfReferencingAlias($alias);
        }

        do {
            $revision = $this->revision;
            $this->assertNoCycle($alias, $target);
            // A cyclic destructor can attach this resolver during validation.
            if (isset($owners[$resolver])) {
                throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
            }
        } while ($revision !== $this->revision);

        $this->writeRoute($alias, $target);
        return $this;
    }

    /** Publishes a validated route if the live graph still has the validated revision. */
    public function publishValidated(string $alias, string $target, int $revision, object $authority): bool
    {
        $owners = self::$owners;
        $resolver = $this;
        if (($owners[$resolver] ?? null) !== $authority) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }
        if ($this->revision !== $revision) {
            return false;
        }
        $this->writeRoute($alias, $target);
        return true;
    }

    public function has(string $alias): bool
    {
        return isset($this->map[$alias]);
    }

    public function target(string $alias): ?string
    {
        return $this->map[$alias] ?? null;
    }

    /** @return int The graph revision at the start of validation. */
    public function validateProtectedRoutes(): int
    {
        $revision = $this->revision;
        foreach (ProtectedServiceIds::ids() as $id) {
            $target = $this->map[$id] ?? null;
            if ($target !== null && !($id === ConfigAttribute::KEY && $target === Config::class)) {
                throw new InvalidConfigurationException(sprintf('Cannot replace protected DI alias "%s".', $id));
            }
        }
        return $revision;
    }

    /** @return array<string, string> Previous canonical ids for aliases whose target changes. */
    public function changedTargets(self $candidate): array
    {
        $changed = [];
        foreach ($this->map + $candidate->map as $alias => $_) {
            $alias = (string) $alias;
            $previous = $this->resolve($alias);
            if ($candidate->resolve($alias) !== $previous) {
                $changed[$alias] = $previous;
            }
        }
        return $changed;
    }

    private static function assertNonEmpty(string $alias, string $target): void
    {
        if ($alias === '') {
            throw new InvalidConfigurationException('Alias id must be a non-empty string.');
        }

        if ($target === '') {
            throw new InvalidConfigurationException('Alias target must be a non-empty DI id.');
        }
    }

    private function writeRoute(string $alias, string $target): void
    {
        ++$this->revision;
        $this->map[$alias] = $target;
        $this->resolved = [];
    }

    /**
     * Ensures that inserting `$alias -> $target` into the current map does not
     * create a cycle.
     *
     * @throws CircularDependencyException If a cycle would be created.
     */
    private function assertNoCycle(string $alias, string $target): void
    {
        $path = [$alias, $target];
        $visited = [$alias => true, $target => true];
        $current = $target;

        while (isset($this->map[$current])) {
            $next = $this->map[$current];
            $path[] = $next;

            if (isset($visited[$next])) {
                throw CircularDependencyException::forAlias($path);
            }

            $visited[$next] = true;
            $current = $next;
        }
    }
}
