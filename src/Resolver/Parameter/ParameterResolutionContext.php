<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Closure;

/** Arguments visible to resolvers during one invocation. */
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

    /**
     * @internal Gives the invocation owner the writer for a new context.
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed> $resolved
     * @return array{self, Closure(int,mixed):void}
     */
    public static function begin(array $provided, array $resolved): array
    {
        $context = new self($provided, $resolved);
        return [$context, $context->resolve(...)];
    }

    private function resolve(int $position, mixed $value): void
    {
        $this->resolved[$position] = $value;
    }
}
