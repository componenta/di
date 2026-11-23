<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use WeakMap;

/**
 * Keeps raw internal resolution parameters outside extension-facing object contexts.
 *
 * @internal
 */
final class ObjectResolutionParameterStore
{
    /** @var WeakMap<ObjectCreationContext, EntryParameters|(Closure():EntryParameters)> */
    private WeakMap $parameters;
    /** @var WeakMap<ObjectCreationContext,list<Closure():void>>|null */
    private ?WeakMap $setupSourceChecks = null;

    public function __construct()
    {
        $this->parameters = new WeakMap();
    }

    /** @param array<string|int, mixed>|EntryParameters|(Closure():EntryParameters) $parameters */
    public function attach(ObjectCreationContext $context, array|Closure|EntryParameters $parameters): void
    {
        if (isset($this->parameters[$context])) {
            throw new LogicException('This object creation attempt already owns its parameters.');
        }
        $this->parameters[$context] = $parameters instanceof Closure ? $parameters : ConstructionParameters::from($parameters);
    }

    public function get(ObjectCreationContext $context): EntryParameters
    {
        $parameters = $this->parameters[$context] ?? ConstructionParameters::from([]);
        if ($parameters instanceof Closure) {
            $parameters = $parameters();
            $this->parameters[$context] = $parameters;
        }

        return $parameters;
    }

    public function copy(ObjectCreationContext $from, ObjectCreationContext $to): void
    {
        if (isset($this->parameters[$from])) {
            $this->attach($to, $this->parameters[$from]);
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
        foreach ($this->setupSourceChecks($context) as $check) {
            $check();
        }
    }

    /** @return list<Closure():void> */
    public function setupSourceChecks(ObjectCreationContext $context): array
    {
        return $this->setupSourceChecks[$context] ?? [];
    }
}
