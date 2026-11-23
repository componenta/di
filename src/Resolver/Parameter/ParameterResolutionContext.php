<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Closure;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\ResolutionDiagnostics;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextScope;
use ReflectionParameter;
use Throwable;

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

    protected function parameterFailure(ReflectionParameter $parameter, ?string $reason, ?Throwable $previous): ResolutionException
    {
        return ResolutionDiagnostics::parameter(
            $parameter,
            $reason,
            ResolutionDiagnostics::argumentTypes($this->suppliedArguments),
            ResolutionDiagnostics::argumentTypes($this->resolvedArguments),
            $previous,
        );
    }

    private function resolve(int $position, mixed $value): void
    {
        $this->resolvedArguments[$position] = $value;
    }
}
