<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ResolutionContext;
use Throwable;

/** Resolves explicitly registered zero-argument invokable classes. */
final class InvokableResolver implements DefinitionAwareResolverInterface
{
    /** @var array<string, class-string> */
    private array $invokables = [];

    /** @param list<class-string|InvokableDefinition> $invokables */
    public function __construct(array $invokables = [])
    {
        foreach ($invokables as $invokable) {
            if ($invokable instanceof InvokableDefinition) {
                $this->setDefinition($invokable->value, $invokable);
            } elseif (is_string($invokable) && $invokable !== '') {
                $this->invokables[$invokable] = $invokable;
            } else {
                throw new InvalidConfigurationException('Invokable class must be a non-empty class-string.');
            }
        }
    }

    public function can(string $id): bool
    {
        return isset($this->invokables[$id]);
    }

    public function resolve(
        string $id,
        ResolutionContext $context = new ResolutionContext(),
    ): object {
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
        if (!$definition instanceof InvokableDefinition || $definition->value === '') {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }
        $this->invokables[$id] = $definition->value;
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof InvokableDefinition;
    }
}
