<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\DelegatorException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Keeps track of delegator callables attached to container entries and applies
 * them in registration order.
 *
 * @internal
 */
final class DelegatorRegistry
{
    /** @var array<string, list<mixed>> Raw delegators keyed by entry id. */
    private array $raw = [];

    /** @var array<string, list<callable>> Normalised callables cache. */
    private array $callables = [];

    /**
     * Raw callable service id -> decorated entry ids whose normalised chain
     * depends on that id. Used to invalidate decorated results when an alias
     * used by a deferred delegator is retargeted.
     *
     * @var array<string, array<string, true>>
     */
    private array $dependents = [];

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
    ) {}

    public function register(string $id, mixed $delegator): void
    {
        $this->raw[$id][] = $delegator;

        foreach (self::referenceIds($delegator) as $referenceId) {
            $this->dependents[$referenceId][$id] = true;
        }

        unset($this->callables[$id]);
    }

    public function invalidate(string $id): void
    {
        unset($this->callables[$id]);
    }

    /**
     * Invalidates every normalised delegator chain that references the supplied
     * service id and returns the decorated entry ids whose resolved cache must
     * also be dropped by the container.
     *
     * @return list<string>
     */
    public function invalidateDependency(string $id): array
    {
        $entries = array_keys($this->dependents[$id] ?? []);

        foreach ($entries as $entry) {
            unset($this->callables[$entry]);
        }

        return $entries;
    }

    /**
     * @param ContainerInterface $container Container passed as the second delegator argument.
     * @throws DelegatorException
     */
    public function apply(string $id, mixed $entry, ContainerInterface $container): mixed
    {
        if (!isset($this->raw[$id])) {
            return $entry;
        }

        $callables = $this->callables[$id] ??= $this->resolveChain($id);

        foreach ($callables as $callable) {
            try {
                $entry = $callable($entry, $container);
            } catch (ContainerExceptionInterface $e) {
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
            } catch (ContainerExceptionInterface $e) {
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

        if (is_string($delegator)
            || (is_array($delegator)
                && isset($delegator[0])
                && is_string($delegator[0]))
        ) {
            return $this->callableResolver->resolve($delegator);
        }

        if (is_callable($delegator)) {
            return $delegator;
        }

        return $this->callableResolver->resolve($delegator);
    }

    /** @return list<string> */
    private static function referenceIds(mixed $delegator): array
    {
        if (is_string($delegator) && $delegator !== '') {
            return [$delegator];
        }

        if (is_array($delegator)
            && array_keys($delegator) === [0, 1]
            && is_string($delegator[0])
            && $delegator[0] !== ''
        ) {
            return [$delegator[0]];
        }

        return [];
    }
}
