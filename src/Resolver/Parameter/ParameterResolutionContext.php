<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;

/** Mutable state owned by one ParametersResolver invocation. */
final class ParameterResolutionContext
{
    /** @var array<string|int, mixed> */
    public readonly array $provided;

    public readonly ?MappedRequestContext $mappedRequest;

    /** @var array<int, mixed> */
    public private(set) array $resolved;

    /**
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed> $resolved
     */
    public function __construct(array $provided = [], array $resolved = [])
    {
        $this->mappedRequest = MappedRequestContext::get($provided);
        $this->provided = MappedRequestContext::strip($provided);
        $this->resolved = $resolved;
    }

    public function resolve(int $position, mixed $value): void
    {
        $this->resolved[$position] = $value;
    }
}
