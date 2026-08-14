<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Throwable;

/** Resolves explicitly registered invokable classes with zero-argument construction. */
class InvokableResolver implements DefinitionAwareResolverInterface
{
    /** @var array<string, class-string> */
    private array $invokables = [];

    /** @var array<string, true> */
    private array $runtimeDefinitionIds = [];

    /** @param list<class-string> $invokables */
    public function __construct(array $invokables = [])
    {
        foreach ($invokables as $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException(
                    'Invokable class must be a non-empty string.',
                );
            }

            $this->invokables[$class] = $class;
        }
    }

    public function can(string $id): bool
    {
        return isset($this->invokables[$id]);
    }

    /** @param array<string|int, mixed> $context */
    public function resolve(string $id, array $context = []): object
    {
        if (!$this->can($id)) {
            throw NotFoundException::forService($id);
        }

        $class = $this->invokables[$id];

        try {
            return new $class();
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if (!$definition instanceof InvokableDefinition) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        if ($definition->value === '') {
            throw new InvalidConfigurationException(
                'Invokable definition class must be a non-empty string.',
            );
        }

        $this->invokables[$id] = $definition->value;
        $this->runtimeDefinitionIds[$id] = true;
    }

    public function removeDefinition(string $id): void
    {
        if (!isset($this->runtimeDefinitionIds[$id])) {
            return;
        }

        unset($this->invokables[$id], $this->runtimeDefinitionIds[$id]);
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof InvokableDefinition;
    }
}
