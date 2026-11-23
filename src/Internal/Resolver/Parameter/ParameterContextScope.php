<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestPublication;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Fiber;
use ReflectionParameter;
use Throwable;

/** Keeps invocation writes and mapped result checks with their resolution owner. @internal */
abstract class ParameterContextScope
{
    // Extensions can reuse a context in nested calls and across Fibers.
    /** @var array<int,list<MappedRequestPublication|null>> */
    private array $mappedResultScopes = [];

    /**
     * @param array<string|int,mixed> $provided
     * @param array<int,mixed> $resolved
     * @return array{ParameterResolutionContext, Closure(int,mixed):void, ParameterResolutionContext}
     */
    abstract protected static function startInvocation(array $provided, array $resolved): array;

    /** @return array<string|int,mixed> */
    abstract protected function invocationArguments(): array;

    abstract protected function parameterFailure(ReflectionParameter $parameter, ?string $reason, ?Throwable $previous): ResolutionException;

    final protected function beginMappedResults(): int
    {
        $execution = self::executionId();
        $this->mappedResultScopes[$execution][] = null;
        return $execution;
    }

    final protected function endMappedResults(int $execution): ?MappedRequestPublication
    {
        $publication = array_pop($this->mappedResultScopes[$execution]);
        if ($this->mappedResultScopes[$execution] === []) {
            unset($this->mappedResultScopes[$execution]);
        }
        return $publication;
    }

    /** @param list<Closure():void> $checks */
    final protected function keepMappedResultChecks(object $result, array $checks): void
    {
        $execution = self::executionId();
        if (isset($this->mappedResultScopes[$execution])) {
            $scopes = &$this->mappedResultScopes[$execution];
            $publication = array_pop($scopes) ?? new MappedRequestPublication();
            $scopes[] = $publication;
            $publication->record($result, $checks);
        }
    }

    private static function executionId(): int
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? 0 : spl_object_id($fiber);
    }
}
