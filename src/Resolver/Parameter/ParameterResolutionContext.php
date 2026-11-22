<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Closure;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextScope;

/** Arguments visible to resolvers during one invocation. */
final class ParameterResolutionContext extends ParameterContextScope
{
    /** @var array<string|int, mixed> */
    private readonly array $suppliedArguments;
    /** @var array<int, mixed> */
    private array $resolvedArguments;

    /** @var array<string|int, mixed> */
    public array $provided {
        get => ResolutionMetadata::snapshot($this->suppliedArguments);
    }

    /** @var array<int, mixed> */
    public array $resolved {
        get => ResolutionMetadata::snapshot($this->resolvedArguments);
    }

    /**
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed> $resolved
     */
    public function __construct(array $provided = [], array $resolved = [])
    {
        $this->suppliedArguments = ResolutionMetadata::invocationSlots($provided);
        $this->resolvedArguments = ResolutionMetadata::invocationSlots($resolved);
    }

    /**
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed> $resolved
     * @return array{self, Closure(int,mixed):void}
     */
    protected static function startInvocation(array $provided, array $resolved): array
    {
        $context = new self($provided, $resolved);
        return [$context, $context->resolve(...)];
    }

    /** @return array<string|int,mixed> */
    protected function invocationArguments(): array
    {
        return $this->suppliedArguments;
    }

    private function resolve(int $position, mixed $value): void
    {
        $this->resolvedArguments[$position] = $value;
    }
}
