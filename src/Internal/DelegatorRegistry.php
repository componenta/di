<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\ExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Keeps track of delegator callables attached to container entries and applies
 * them in registration order.
 *
 * @internal
 */
final class DelegatorRegistry
{
    /** @var array<string, list<mixed>> */
    private array $raw = [];
    /** @var array<string, list<callable>> */
    private array $callables = [];
    /** @var array<string, array<string, true>> */
    private array $dependents = [];

    public function __construct(private readonly CallableResolverInterface $callableResolver) {}

    public function register(string $id, mixed $delegator): void
    {
        $delegator = DependencyConfiguration::normalizeDelegatorSpecification($delegator, $id);
        $this->raw[$id][] = $delegator;

        foreach (self::dependencyIds($delegator) as $dependencyId) {
            $this->dependents[$dependencyId][$id] = true;
        }

        unset($this->callables[$id]);
    }

    public function invalidate(string $id): void
    {
        unset($this->callables[$id]);
    }

    /** @return list<string> */
    public function entryIds(): array
    {
        return array_keys($this->raw);
    }

    /** @return list<string> */
    public function deferredDependencies(): array
    {
        return array_keys($this->dependents);
    }

    /**
     * Invalidates every delegator chain transitively depending on any supplied
     * dependency id. The traversal is cycle-safe because an entry may itself be
     * used as a deferred callable dependency by another entry (or by a cycle).
     *
     * @param iterable<string> $dependencyIds
     * @return list<string>
     */
    public function invalidateDependencies(iterable $dependencyIds): array
    {
        /** @var list<string> $queue */
        $queue = [];
        /** @var array<string, true> $queued */
        $queued = [];
        /** @var array<string, true> $visited */
        $visited = [];
        /** @var array<string, true> $entries */
        $entries = [];

        foreach ($dependencyIds as $dependencyId) {
            if (isset($queued[$dependencyId])) {
                continue;
            }
            $queued[$dependencyId] = true;
            $queue[] = $dependencyId;
        }

        for ($offset = 0; isset($queue[$offset]); ++$offset) {
            $dependencyId = $queue[$offset];
            if (isset($visited[$dependencyId])) {
                continue;
            }
            $visited[$dependencyId] = true;

            foreach ($this->dependents[$dependencyId] ?? [] as $entry => $_) {
                if (!isset($entries[$entry])) {
                    $entries[$entry] = true;
                    unset($this->callables[$entry]);
                }

                if (!isset($queued[$entry])) {
                    $queued[$entry] = true;
                    $queue[] = $entry;
                }
            }
        }

        return array_keys($entries);
    }

    /** @throws DelegatorException */
    public function apply(string $id, mixed $entry, ContainerInterface $container): mixed
    {
        if (!isset($this->raw[$id])) {
            return $entry;
        }

        $callables = $this->callables[$id] ??= $this->resolveChain($id);
        foreach ($callables as $callable) {
            try {
                $entry = $callable($entry, $container);
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw DelegatorException::forEntry($id, $e);
            }
        }
        return $entry;
    }

    /** @return list<callable> */
    private function resolveChain(string $id): array
    {
        $callables = [];
        foreach ($this->raw[$id] as $delegator) {
            try {
                $callables[] = $this->normalize($delegator);
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                throw DelegatorException::forEntry($id, $e);
            }
        }
        return $callables;
    }

    private function normalize(mixed $delegator): callable
    {
        if ($delegator instanceof Closure) {
            return $delegator;
        }
        if (is_string($delegator)) {
            return $this->callableResolver->resolve($delegator);
        }
        if (is_callable($delegator)) {
            return $delegator;
        }
        return $this->callableResolver->resolve($delegator);
    }

    /** @return list<string> */
    private static function dependencyIds(mixed $delegator): array
    {
        if (is_string($delegator)) {
            if ($delegator === '') {
                return [];
            }

            $ids = [$delegator => true];
            if (str_contains($delegator, '::')) {
                [$owner, $method] = explode('::', $delegator, 2);
                if ($owner !== ''
                    && $method !== ''
                    && (class_exists($owner) || interface_exists($owner))
                    && method_exists($owner, $method)
                    && !(new ReflectionMethod($owner, $method))->isStatic()
                ) {
                    $ids[$owner] = true;
                }
            }
            return array_keys($ids);
        }

        if (is_array($delegator)
            && !is_callable($delegator)
            && array_keys($delegator) === [0, 1]
            && is_string($delegator[0])
            && $delegator[0] !== ''
        ) {
            return [$delegator[0]];
        }

        return [];
    }
}
