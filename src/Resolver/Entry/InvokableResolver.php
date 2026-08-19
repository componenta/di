<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use ReflectionClass;
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
            $class = $invokable instanceof InvokableDefinition ? $invokable->value : $invokable;
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException('Invokable class must be a non-empty class-string.');
            }

            $this->invokables[$class] = self::validatedClass($class);
        }
    }

    public function can(string $id): bool
    {
        return isset($this->invokables[$id]);
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): object
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

        $this->invokables[$id] = self::validatedClass($definition->value);
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof InvokableDefinition;
    }

    /** @return class-string */
    private static function validatedClass(string $class): string
    {
        if ($class === '' || !class_exists($class)) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" does not exist.',
                $class,
            ));
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" must be concrete and instantiable.',
                $class,
            ));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" constructor requires arguments; use a factory or autowiring instead.',
                $class,
            ));
        }

        /** @var class-string $resolved */
        $resolved = $reflection->getName();
        return $resolved;
    }
}
