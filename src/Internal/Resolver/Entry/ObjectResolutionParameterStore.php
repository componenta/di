<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use WeakMap;

/** Keeps raw internal resolution parameters outside extension-facing object contexts. @internal */
final class ObjectResolutionParameterStore
{
    /** @var WeakMap<ObjectCreationContext, array<string|int, mixed>|Closure():array<string|int,mixed>> */
    private WeakMap $parameters;
    /** @var WeakMap<ObjectCreationContext,list<Closure():void>>|null */
    private ?WeakMap $setupSourceChecks = null;

    public function __construct()
    {
        $this->parameters = new WeakMap();
    }

    /** @param array<string|int, mixed>|Closure():array<string|int,mixed> $parameters */
    public function attach(ObjectCreationContext $context, array|Closure $parameters): void
    {
        $this->parameters[$context] = $parameters;
    }

    /** @return array<string|int, mixed> */
    public function get(ObjectCreationContext $context): array
    {
        $parameters = $this->parameters[$context] ?? [];
        if ($parameters instanceof Closure) {
            $parameters = $parameters();
            $this->parameters[$context] = $parameters;
        }

        return $parameters;
    }

    public function copy(ObjectCreationContext $from, ObjectCreationContext $to): void
    {
        if (isset($this->parameters[$from])) {
            $this->parameters[$to] = $this->parameters[$from];
        }
    }

    /** @param Closure():void $check */
    public function retainSetupSourceCheck(ObjectCreationContext $context, Closure $check): void
    {
        $checks = $this->setupSourceChecks ??= new WeakMap();
        $checks[$context] ??= [];
        $checks[$context][] = $check;
    }

    public function assertSetupSourcesUnchanged(ObjectCreationContext $context): void
    {
        foreach ($this->setupSourceChecks[$context] ?? [] as $check) {
            $check();
        }
    }
}
