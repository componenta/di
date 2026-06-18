<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\DelegatorException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Keeps track of delegator (decorator) callables attached to container
 * entries and applies them in order.
 *
 * Delegators are stored in their raw registration form (Closure, service id,
 * `[class, method]`) and normalised to callables on first use; the resolved
 * callables are cached until the registry is invalidated for that entry.
 *
 * Container-typed exceptions raised by a delegator surface unchanged
 * (PSR-11 contract); other Throwables are wrapped into {@see DelegatorException}.
 *
 * @internal
 */
final class DelegatorRegistry
{
    /** @var array<string, list<mixed>> Raw delegators keyed by entry id. */
    private array $raw = [];

    /** @var array<string, list<callable>> Normalised callables cache. */
    private array $callables = [];

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
    ) {}

    /**
     * Records a new delegator and invalidates the resolved-callable cache for
     * the entry so subsequent applications re-normalise the chain.
     */
    public function register(string $id, mixed $delegator): void
    {
        $this->raw[$id][] = $delegator;
        unset($this->callables[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->raw[$id]);
    }

    /**
     * Drops the resolved-callable cache for the entry; raw registrations are
     * preserved. Container invokes this from its invalidation flow so the
     * chain is re-normalised on next use.
     */
    public function invalidate(string $id): void
    {
        unset($this->callables[$id]);
    }

    /**
     * Runs every registered delegator on the entry in registration order.
     *
     * @param ContainerInterface $container Container reference passed as the
     *                                      second argument to each delegator.
     *
     * @throws DelegatorException If a delegator or its resolution raised a
     *                            non-container exception.
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

    /**
     * @return list<callable>
     *
     * @throws DelegatorException
     */
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

        if (is_callable($delegator)) {
            return $delegator;
        }

        return $this->callableResolver->resolve($delegator);
    }
}
