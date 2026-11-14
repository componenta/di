<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\DefinitionBinding;
use Componenta\DI\Internal\Resolver\Entry\EntryResolverContext;
use Throwable;

/** Owns explicit bindings and ordered fallback resolution. */
final class CompositeResolver implements EntryResolverInterface
{
    /** @var list<EntryResolverInterface> */
    private array $fallbacks = [];
    /** @var list<DefinitionResolverInterface> */
    private array $strategies = [];
    /** @var array<string, DefinitionBinding> */
    private array $bindings = [];
    private readonly object $bindingOwner;
    private ?object $authority = null;

    public function __construct(EntryResolverInterface|DefinitionResolverInterface ...$providers)
    {
        $this->bindingOwner = new \stdClass();
        $direct = [];
        $strategies = [];
        foreach ($providers as $provider) {
            $identity = spl_object_id($provider);
            if (isset($direct[$identity])) {
                throw new InvalidConfigurationException(sprintf(
                    'Entry resolver %s is registered more than once.',
                    $provider::class,
                ));
            }
            $direct[$identity] = true;

            if ($provider instanceof self) {
                if ($provider->authority !== null) {
                    throw new InvalidConfigurationException('An attached composite cannot be imported as a snapshot.');
                }
                foreach ($provider->strategies as $strategy) {
                    $strategies[spl_object_id($strategy)] ??= $strategy;
                }
                foreach ($provider->fallbacks as $fallback) {
                    $this->fallbacks[] = $fallback;
                }
                foreach ($provider->bindings as $id => $binding) {
                    if (isset($this->bindings[$id]) && $this->bindings[$id] !== $binding) {
                        throw new InvalidConfigurationException(sprintf(
                            'Composite snapshots contain different definitions for DI id "%s".',
                            $id,
                        ));
                    }
                    $this->bindings[$id] = $binding;
                }
                continue;
            }

            if ($provider instanceof DefinitionResolverInterface) {
                $strategies[$identity] ??= $provider;
            }
            if ($provider instanceof EntryResolverInterface) {
                $this->fallbacks[] = $provider;
            }
        }
        $this->strategies = array_values($strategies);
    }

    public function can(string $id): bool
    {
        return isset($this->bindings[$id]) || $this->fallback($id) !== null;
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): mixed
    {
        $binding = $this->bindings[$id] ?? null;
        if ($binding !== null) {
            return $binding->strategy->resolveDefinition(
                $id,
                $binding->definition,
                ResolutionMetadata::publicParameters($params),
            );
        }

        $fallback = $this->fallback($id) ?? throw NotFoundException::forService($id);
        return $fallback->resolve($id, EntryResolverContext::for($fallback, $params));
    }

    /** Registers a definition in a standalone composition. Attached roots use Container::set(). */
    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if ($this->authority !== null) {
            throw new InvalidConfigurationException('An attached composite must be mutated through its container.');
        }
        $binding = $this->prepareDefinition($id, $definition);
        $retirement = new MutationRetirement($id);
        $this->publishDefinition($binding, $retirement);
        $retirement->release();
    }

    /** @internal */
    public function attach(object $authority): void
    {
        if ($this->authority !== null) {
            throw new InvalidConfigurationException('A composite can be attached to only one container.');
        }
        $this->authority = $authority;
    }

    /** @internal */
    public function prepareDefinition(string $id, DefinitionInterface $definition): DefinitionBinding
    {
        if ($id === '') {
            throw new InvalidConfigurationException('Definition ids must be non-empty strings.');
        }
        try {
            foreach ($this->strategies as $strategy) {
                if ($strategy->supportsDefinition($definition)) {
                    return new DefinitionBinding(
                        $id,
                        $strategy,
                        $strategy->validateDefinition($id, $definition),
                        $this->bindingOwner,
                    );
                }
            }
            throw InvalidConfigurationException::forInvalidDefinition($definition);
        } catch (ExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidConfigurationException(
                sprintf('Failed to register a definition for DI id "%s".', $id),
                previous: $exception,
            );
        }
    }

    /** @internal */
    public function publishDefinition(
        DefinitionBinding $binding,
        MutationRetirement $retirement,
        ?object $authority = null,
    ): void {
        if (!$binding->belongsTo($this->bindingOwner)) {
            throw new InvalidConfigurationException('A prepared definition belongs to another composite.');
        }
        if ($authority !== $this->authority) {
            throw new InvalidConfigurationException('Definition publication requires its container mutation authority.');
        }
        $retirement->retain($this->bindings[$binding->id] ?? null);
        $this->bindings[$binding->id] = $binding;
    }

    private function fallback(string $id): ?EntryResolverInterface
    {
        foreach ($this->fallbacks as $fallback) {
            if ($fallback->can($id)) {
                return $fallback;
            }
        }
        return null;
    }
}
