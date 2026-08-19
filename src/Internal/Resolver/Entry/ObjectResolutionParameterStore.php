<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use WeakMap;

/** Keeps raw internal resolution parameters outside extension-facing object contexts. @internal */
final class ObjectResolutionParameterStore
{
    /** @var WeakMap<ObjectCreationContext, array<string|int, mixed>> */
    private WeakMap $parameters;

    public function __construct()
    {
        $this->parameters = new WeakMap();
    }

    /** @param array<string|int, mixed> $parameters */
    public function attach(ObjectCreationContext $context, array $parameters): void
    {
        $this->parameters[$context] = $parameters;
    }

    /** @return array<string|int, mixed> */
    public function get(ObjectCreationContext $context): array
    {
        return $this->parameters[$context] ?? [];
    }

    public function copy(ObjectCreationContext $from, ObjectCreationContext $to): void
    {
        if (isset($this->parameters[$from])) {
            $this->parameters[$to] = $this->parameters[$from];
        }
    }
}
