<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\Config\ConfigEntry;
use Componenta\Config\ContainerEntry;
use Componenta\Config\ContainerValue;
use Componenta\Config\LazyValue;

/**
 * Unwraps Componenta config value-objects inside SetUp params.
 *
 * This keeps SetUp runtime defaults aligned with factory defaults:
 * - ContainerEntry resolves another container entry.
 * - ConfigEntry resolves a value from the application Config.
 * - LazyValue executes explicitly and receives ContainerValue.
 *
 * Plain callables remain values and are not executed.
 */
final readonly class ContainerValueUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(
        private ContainerValue $container,
    ) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof ContainerEntry
            || $value instanceof ConfigEntry
            || $value instanceof LazyValue;
    }

    public function unwrap(mixed $value, string $key): mixed
    {
        return match (true) {
            $value instanceof ContainerEntry => $value->resolve($this->container),
            $value instanceof ConfigEntry => $value->resolve($this->container->config),
            $value instanceof LazyValue => $value->resolve($this->container),
            default => $value,
        };
    }
}
