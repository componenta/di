<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

/**
 * Mutable state of one parameter-resolution operation.
 *
 * Provided values are immutable input. Resolved values are
 * accumulated in-place while parameters are processed in declaration order.
 */
final class ParameterResolutionContext
{
    /** @var array<int, mixed> */
    public private(set) array $resolved;

    /**
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed>        $resolved
     */
    public function __construct(
        public readonly array $provided = [],
        array $resolved = [],
    ) {
        $this->resolved = $resolved;
    }

    public function resolve(int $position, mixed $value): void
    {
        $this->resolved[$position] = $value;
    }

}
