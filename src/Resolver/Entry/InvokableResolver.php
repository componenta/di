<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use ReflectionClass;
use Throwable;

/** Validates and executes zero-argument class definitions without registrations. */
final class InvokableResolver implements DefinitionResolverInterface
{
    /** @param array<string|int, mixed> $params */
    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): object
    {
        if (!$validated instanceof InvokableDefinition) {
            throw InvalidConfigurationException::forUnsupportedDefinition($validated, self::class);
        }
        $class = $validated->value;
        try {
            return new $class();
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        if (!$definition instanceof InvokableDefinition) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        return new InvokableDefinition(self::validatedClass($definition->value));
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
