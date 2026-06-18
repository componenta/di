<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\PropertyTarget;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Reads {@see Make} / {@see Proxy} metadata off a reflection target and
 * turns it into a resolver-ready configuration triple
 * `[entry: string, params: array, proxy: bool]`.
 *
 * The `proxy` flag is true when {@see Proxy} is present on the target,
 * signalling that the resolved instance must be wrapped in a virtual
 * proxy at injection time (deferred construction).
 *
 * Kept as a standalone class (composition) rather than a trait so
 * {@see \Componenta\DI\Resolver\MakeAttributeResolver} depends on behaviour
 * through a discrete collaborator - easier to substitute, easier to test.
 */
final readonly class FactoryConfigReader
{
    /**
     * Extracts the factory configuration attached to a parameter or property.
     *
     * Returns null when neither {@see Make} nor {@see Proxy} is present,
     * signalling that the caller should defer to the next resolver in the
     * chain.
     *
     * @return array{entry: string, params: array<string, mixed>, proxy: bool}|null
     */
    public function read(ReflectionParameter|ReflectionProperty $reflector): ?array
    {
        $target = $reflector instanceof ReflectionParameter
            ? new ParameterTarget($reflector)
            : new PropertyTarget($reflector);

        $make  = $target->getFirstAttribute(Make::class);
        $proxy = $target->getFirstAttribute(Proxy::class);

        if ($make === null && $proxy === null) {
            return null;
        }

        // Resolve entry: explicit > declared class/interface type > target name.
        $entry = $make?->entry
            ?? TypeHints::classOf($target->getType())
            ?? $target->getName();

        return [
            'entry'  => $entry,
            'params' => $make?->params ?? [],
            'proxy'  => $proxy !== null,
        ];
    }
}
