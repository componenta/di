<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\DelegatorException;
use Psr\Container\ContainerExceptionInterface;
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

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
    ) {}

    public function register(string $id, mixed $delegator): void
    {
        $delegator = DependencyConfiguration::normalizeDelegatorSpecification(
            $delegator,
            $id,
        );
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
    public function deferredDependencies(): array
    {
        return array_keys($this->dependents);
    }

    /**
     * @param iterable<string> $dependencyIds
     * @return list<string>
     */
    public function invalidateDependencies(iterable $dependencyIds): array
    {
        /** @var array<string, true> $entries */
        $entries = [];

        foreach ($dependencyIds as $dependencyId) {
            foreach ($this->dependents[$dependencyId] ?? [] as $entry => $_) {
                $entries[$entry] = true;
            }
        }

        foreach ($entries as $entry => $_) {
            unset($this->callables[$entry]);
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
