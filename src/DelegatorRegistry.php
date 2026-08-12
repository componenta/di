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

    /** @var array<string, true> Entry ids with namespace-dependent delegators. */
    private array $deferredEntries = [];

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
    ) {}

    public function register(string $id, mixed $delegator): void
    {
        $this->raw[$id][] = $delegator;

        if (self::isDeferredReference($delegator)) {
            $this->deferredEntries[$id] = true;
        }

        unset($this->callables[$id]);
    }

    public function invalidate(string $id): void
    {
        unset($this->callables[$id]);
    }

    /**
     * Invalidates every normalised chain containing a deferred service-id
     * reference and returns the decorated entry ids whose resolved cache must
     * also be dropped by the container.
     *
     * Alias mutation must invalidate the complete deferred set rather than only
     * references whose raw id equals the changed alias: another reference can
     * point to an alias that resolves transitively through it. The same rule is
     * required when a service is replaced or a new external container changes
     * the PSR-11 namespace visible to CallableResolver.
     *
     * These mutations are control-plane operations, so correctness is preferred
     * over maintaining a reverse transitive alias graph here.
     *
     * @return list<string>
     */
    public function invalidateDeferred(): array
    {
        $entries = array_keys($this->deferredEntries);

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

        // Strings remain ambiguous with opaque service ids and must be resolved
        // through CallableResolver. Any already-valid non-string callable is an
        // explicit PHP callable and does not depend on container namespace.
        if (is_string($delegator)) {
            return $this->callableResolver->resolve($delegator);
        }

        if (is_callable($delegator)) {
            return $delegator;
        }

        return $this->callableResolver->resolve($delegator);
    }

    private static function isDeferredReference(mixed $delegator): bool
    {
        if (is_string($delegator)) {
            return $delegator !== '';
        }

        return is_array($delegator)
            && !is_callable($delegator)
            && array_keys($delegator) === [0, 1]
            && is_string($delegator[0])
            && $delegator[0] !== '';
    }
}
