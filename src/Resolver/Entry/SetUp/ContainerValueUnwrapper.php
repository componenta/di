<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\ConfigEntry;
use Componenta\Config\ContainerEntry;
use Componenta\Config\ContainerValue;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\LazyValue;

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

    public function unwrap(mixed $value, string $key): mixed
    {
        return match (true) {
            $value instanceof ContainerEntry => $value->resolve($this->container),
            $value instanceof ConfigEntry => $value->resolve($this->container->config),
            $value instanceof EnvironmentEntry => $value->resolve($this->container->config->environment),
            $value instanceof LazyValue => $value->resolve($this->container),
            default => $value,
        };
    }
}
