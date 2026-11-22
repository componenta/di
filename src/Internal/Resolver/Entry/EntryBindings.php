<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\RuntimeCollaboratorOwnership;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Throwable;

/** Owns explicit bindings and ordered fallback resolution. @internal */
final class EntryBindings
{
    /**
     * @var array{
     *     prepare: Closure(self, string, DefinitionInterface):DefinitionBinding,
     *     publish: Closure(self, DefinitionBinding, MutationRetirement):void,
     *     retire: Closure(self, string, MutationRetirement):void
     * }|null
     */
    private static ?array $writerOperations = null;

    /** @var list<EntryResolverInterface> */
    private array $fallbacks = [];
    /** @var list<DefinitionResolverInterface> */
    private array $strategies = [];
    /** @var array<string, DefinitionBinding> */
    private array $bindings = [];
    private readonly object $bindingOwner;
    private bool $attached = false;

    public function __construct(self|EntryResolverInterface|DefinitionResolverInterface ...$providers)
    {
        $this->bindingOwner = new \stdClass();
        $direct = [];
        $strategies = [];
        foreach ($providers as $provider) {
            $identity = spl_object_id($provider);
            if (isset($direct[$identity])) {
                throw new InvalidConfigurationException(sprintf(
                    'Entry resolver %s is registered more than once.',
                    $provider instanceof self ? CompositeResolver::class : $provider::class,
                ));
            }
            $direct[$identity] = true;

            if ($provider instanceof self) {
                if ($provider->attached) {
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

    /** @internal A local configuration failure describes a resolution error for the requested entry. */
    public function knows(string $id): bool
    {
        try {
            return $this->can($id);
        } catch (InvalidConfigurationException) {
            return true;
        }
    }

    /** @param array<string|int, mixed>|EntryParameters $params */
    public function resolve(string $id, array|EntryParameters $params = []): mixed
    {
        $binding = $this->bindings[$id] ?? null;
        if ($binding !== null) {
            if ($params instanceof EntryParameters) {
                return ConstructionParameters::resolveDefinition($params, $binding->strategy, $id, $binding->definition);
            }
            return $binding->strategy->resolveDefinition(
                $id,
                $binding->definition,
                ResolutionMetadata::publicParameters($params),
            );
        }

        $fallback = $this->fallback($id) ?? throw NotFoundException::forService($id);
        return $fallback->resolve($id, ConstructionParameters::from($params));
    }

    /**
     * Resolves a shared entry without caller parameters. False means no selected route.
     *
     * @internal
     */
    public function tryResolveShared(string $id, mixed &$entry): bool
    {
        $binding = $this->bindings[$id] ?? null;
        if ($binding !== null) {
            $entry = $binding->strategy->resolveDefinition($id, $binding->definition, []);
            return true;
        }

        $fallback = $this->fallback($id);
        if ($fallback === null) {
            return false;
        }
        $entry = $fallback->resolve($id, ConstructionParameters::from([]));
        return true;
    }

    /** Registers a definition in a standalone composition. Attached roots use Container::set(). */
    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if ($this->attached) {
            throw new InvalidConfigurationException('An attached composite must be mutated through its container.');
        }
        $binding = $this->prepareDefinition($id, $definition);
        $retirement = new MutationRetirement($id);
        $this->publishDefinition($binding, $retirement, attached: false);
        $retirement->release();
    }

    /** @internal */
    public function attach(RuntimeCollaboratorOwnership $ownership): AttachedEntryBindings
    {
        $operations = self::$writerOperations ??= [
            'prepare' => static fn(self $resolver, string $id, DefinitionInterface $definition): DefinitionBinding
                => $resolver->prepareDefinition($id, $definition),
            'publish' => static function (self $resolver, DefinitionBinding $binding, MutationRetirement $retirement): void {
                $resolver->publishDefinition($binding, $retirement);
            },
            'retire' => static function (self $resolver, string $id, MutationRetirement $retirement): void {
                $resolver->retireDefinition($id, $retirement);
            },
        ];

        $attached = new AttachedEntryBindings($this, $operations['prepare'], $operations['publish'], $operations['retire']);
        // Loading the port can reenter container construction before attachment.
        $ownership->attach($this->attached);
        return $attached;
    }

    private function prepareDefinition(string $id, DefinitionInterface $definition): DefinitionBinding
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

    private function publishDefinition(
        DefinitionBinding $binding,
        MutationRetirement $retirement,
        bool $attached = true,
    ): void {
        if (!$binding->belongsTo($this->bindingOwner)) {
            throw new InvalidConfigurationException('A prepared definition belongs to another composite.');
        }
        // Validation and collaborator autoloading can attach a standalone composite.
        if ($this->attached !== $attached) {
            throw new InvalidConfigurationException('Definition publication requires its container mutation authority.');
        }
        $retirement->retain($this->bindings[$binding->id] ?? null);
        $this->bindings[$binding->id] = $binding;
    }

    private function retireDefinition(string $id, MutationRetirement $retirement): void
    {
        $retirement->retain($this->bindings[$id] ?? null);
        unset($this->bindings[$id]);
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
