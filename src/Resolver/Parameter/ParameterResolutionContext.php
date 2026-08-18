<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

/**
 * Mutable state owned by one ParametersResolver invocation.
 *
 * This is deliberately internal to parameter resolution. Public container and
 * callable APIs continue to expose plain parameter arrays rather than a
 * framework-wide resolution context object.
 */
final class ParameterResolutionContext
{
    /** @var array<string|int, mixed> */
    public readonly array $provided;

    /** @var array<int, mixed> */
    public private(set) array $resolved;

    /**
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed> $resolved
     */
    public function __construct(array $provided = [], array $resolved = [])
    {
        $this->provided = $provided;
        $this->resolved = $resolved;
    }

    public function resolve(int $position, mixed $value): void
    {
        $this->resolved[$position] = $value;
    }
}
