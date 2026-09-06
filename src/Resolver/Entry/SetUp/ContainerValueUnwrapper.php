<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ContainerEntry;
use Componenta\Config\ContainerValue;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\LazyValue;
use ReflectionNamedType;
use ReflectionParameter;

/** Resolves Componenta config value objects used by #[SetUp]. */
final readonly class ContainerValueUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(private ContainerValue $container) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof ContainerEntry
            || $value instanceof ConfigEntry
            || $value instanceof EnvironmentEntry
            || $value instanceof LazyValue;
    }

    public function unwrap(
        mixed $value,
        string $key,
        ?ReflectionParameter $parameter = null,
    ): mixed {
        return match (true) {
            $value instanceof ContainerEntry => $value->resolve($this->container),
            $value instanceof ConfigEntry => $value->resolve($this->container->config),
            $value instanceof EnvironmentEntry => $this->resolveEnvironmentEntry($value, $parameter),
            $value instanceof LazyValue => $value->resolve($this->container),
            default => $value,
        };
    }

    private function resolveEnvironmentEntry(
        EnvironmentEntry $entry,
        ?ReflectionParameter $parameter,
    ): mixed {
        $environment = $this->container->config->environment;
        $value = $entry->resolve($environment);

        if ($parameter === null || ($value === null && $parameter->allowsNull())) {
            return $value;
        }

        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        if (!in_array($typeName, ['string', 'int', 'float', 'bool', 'array'], true)) {
            return $value;
        }

        $leaf = new Config(['value' => $value], $environment);

        return match ($typeName) {
            'string' => $leaf->string('value'),
            'int' => $leaf->int('value'),
            'float' => $leaf->float('value'),
            'bool' => $leaf->bool('value'),
            'array' => $leaf->array('value'),
        };
    }
}
