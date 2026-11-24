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

    /** @var array<array-key, array<array-key, int>> Direct sources and their declaration order. */
    private array $incoming = [];

    /** @param array<string, string> $map Alias to target map. */
    public function __construct(array $map = [])
    {
        $owners = self::$owners ??= new WeakMap();
        $resolver = $this;
        if (isset($owners[$resolver])) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }
        $routes = $map === [] ? [] : (new AliasGraphBuilder($map))->aliases;
        $incoming = self::indexIncoming($routes);
        if (isset($owners[$resolver])) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }

        ++$this->revision;
        $this->map = $routes;
        $this->incoming = $incoming;
        $this->resolved = [];
    }

    /** @internal */
    public static function fromGraph(AliasGraphBuilder $graph): self
    {
        $resolver = new self();
        $resolver->map = $graph->aliases;
        $resolver->incoming = self::indexIncoming($graph->aliases);
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
        do {
            $revision = $this->revision;
            $this->validateTarget($alias, $target);
            $affected = $this->affectedIds($alias);
            // A cyclic destructor can attach this resolver during validation.
            if (isset($owners[$resolver])) {
                throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
            }
        } while ($revision !== $this->revision);

        $this->writeRoute($alias, $target, $affected);
        return $this;
    }

    /**
     * Publishes a validated route if the live graph still has the validated revision.
     * @param array<array-key, int> $affected
     */
    public function publishValidated(
        string $alias,
        string $target,
        int $revision,
        object $authority,
        array $affected,
    ): bool {
        $owners = self::$owners;
        $resolver = $this;
        if (($owners[$resolver] ?? null) !== $authority) {
            throw new InvalidConfigurationException(self::ATTACHED_WRITE_ERROR);
        }
        if ($this->revision !== $revision) {
            return false;
        }
        $this->writeRoute($alias, $target, $affected);
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

    /**
     * Returns the ID and its incoming alias family in declaration order.
     * @param positive-int|null $limit
     * @return ($limit is null ? array<array-key, int> : array<array-key, int>|null)
     */
    public function affectedIds(string $id, ?int $limit = null): ?array
    {
        $target = $this->map[$id] ?? null;
        $affected = [$id => $target === null ? count($this->map) : $this->incoming[$target][$id]];
        $queue = [$id];
        for ($index = 0; isset($queue[$index]); ++$index) {
            foreach ($this->incoming[$queue[$index]] ?? [] as $source => $order) {
                if ($limit !== null && count($affected) === $limit) {
                    return null;
                }
                $affected[$source] = $order;
                $queue[] = (string) $source;
            }
        }
        if (count($affected) > 1) {
            asort($affected, SORT_NUMERIC);
        }
        return $affected;
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

    /**
     * @param array<string, string> $routes
     * @return array<array-key, array<array-key, int>>
     */
    private static function indexIncoming(array $routes): array
    {
        $incoming = [];
        $order = 0;
        foreach ($routes as $alias => $target) {
            $incoming[$target][$alias] = $order++;
        }
        return $incoming;
    }

    /** @param array<array-key, int> $affected */
    private function writeRoute(string $alias, string $target, array $affected): void
    {
        ++$this->revision;
        $previous = $this->map[$alias] ?? null;
        if ($previous !== null && $previous !== $target) {
            unset($this->incoming[$previous][$alias]);
            if ($this->incoming[$previous] === []) {
                unset($this->incoming[$previous]);
            }
        }
        $this->map[$alias] = $target;
        $this->incoming[$target][$alias] = $affected[$alias];
        foreach ($affected as $id => $_) {
            unset($this->resolved[$id]);
        }
    }

    /**
     * Returns the prospective canonical destination of an alias edge.
     *
     * @throws CircularDependencyException If a cycle would be created.
     */
    public function validateTarget(string $alias, string $target): string
    {
        self::assertNonEmpty($alias, $target);
        if ($alias === $target) {
            throw InvalidConfigurationException::forSelfReferencingAlias($alias);
        }

        $resolvedTarget = $this->resolved[$target] ?? null;
        if ($resolvedTarget !== null) {
            $resolvedAlias = $this->resolved[$alias] ?? (isset($this->map[$alias]) ? null : $alias);
            if ($resolvedAlias !== null && $resolvedAlias !== $resolvedTarget) {
                // A chain through the alias would share its current destination.
                return $resolvedTarget;
            }
        }

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
        return $current;
    }
}
