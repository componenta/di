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

    /** @param list<class-string|InvokableDefinition> $invokables */
    public function __construct(array $invokables = [])
    {
        foreach ($invokables as $invokable) {
            if ($invokable instanceof InvokableDefinition) {
                $this->setDefinition($invokable->value, $invokable);
                continue;
            }

            if (!is_string($invokable) || $invokable === '') {
                throw new InvalidConfigurationException(
                    'Invokable class must be a non-empty string or InvokableDefinition.',
                );
            }

            $this->invokables[$invokable] = $invokable;
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
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof InvokableDefinition;
    }
}
