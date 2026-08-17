<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Closure;
use Componenta\Config\ContainerValue;

/** @internal */
final readonly class MappedRequestAwareFactory
{
    /** @param Closure(ContainerValue, array<string|int, mixed>): object $factory */
    public function __construct(private Closure $factory) {}

    /** @param array<string|int, mixed> $context */
    public function __invoke(ContainerValue $container, array $context = []): object
    {
        return ($this->factory)($container, $context);
    }
}
