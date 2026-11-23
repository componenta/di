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

    /** @param (Closure():bool)|null $hasSharedValue */
    public function can(string $id, ?Closure $hasSharedValue = null): bool
    {
        return $this->select($id, $hasSharedValue) !== null;
    }

    /**
     * A local configuration failure describes a resolution error for the requested entry.
     * @param (Closure():bool)|null $hasSharedValue
     */
    public function knows(string $id, ?Closure $hasSharedValue = null): bool
    {
        try {
            return $this->can($id, $hasSharedValue);
        } catch (InvalidConfigurationException) {
            return true;
        }
    }

    /** @param array<string|int, mixed>|EntryParameters $params */
    public function resolve(string $id, array|EntryParameters $params = []): mixed
    {
        if (is_array($params) && $params !== [] && !isset($this->bindings[$id])) {
            // Fallback availability can autoload metadata before its resolver runs.
            // Forward the same captured arguments while retaining native factory values.
            $params = ConstructionParameters::from($params);
        }
        $selected = $this->select($id) ?? throw NotFoundException::forService($id);
        return $this->resolveSelected($id, $selected, $params);
    }

    /** @param array<string|int, mixed>|EntryParameters $params */
    public function resolveSelected(
        string $id,
        DefinitionBinding|EntryResolverInterface $selected,
        array|EntryParameters $params,
    ): mixed {
        if ($selected instanceof DefinitionBinding) {
            if ($params instanceof EntryParameters) {
                return ConstructionParameters::resolveDefinition($params, $selected->strategy, $id, $selected->definition);
            }
            return $selected->strategy->resolveDefinition(
                $id,
                $selected->definition,
                ResolutionMetadata::publicParameters($params),
            );
        }

        return $selected->resolve($id, ConstructionParameters::from($params));
    }

    /**
     * Selects a route while allowing a newly published shared value to stop probing.
     *
     * @param (Closure():bool)|null $hasSharedValue
     */
    public function select(string $id, ?Closure $hasSharedValue = null): DefinitionBinding|EntryResolverInterface|null
    {
        $binding = $this->bindings[$id] ?? null;
        if ($binding !== null) {
            return $binding;
        }

        foreach ($this->fallbacks as $fallback) {
            $available = $fallback->can($id);
            // Autoloading and application availability checks can publish entries.
            if ($hasSharedValue !== null && $hasSharedValue()) {
                return null;
            }
            $binding = $this->bindings[$id] ?? null;
            if ($binding !== null) {
                return $binding;
            }
            if ($available) {
                return $fallback;
            }
        }
        return null;
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

}
