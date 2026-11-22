<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;
use Componenta\DI\Resolver\Entry\EntryParameters;

/** Captures and forwards a construction's arguments and their origin. @internal */
abstract class ConstructionParameters extends ConstructionParameterScope
{
    /** @param array<string|int,mixed> $parameters */
    public static function capture(array $parameters = [], ?MappedRequestContext $provenance = null): EntryParameters
    {
        $values = ResolutionMetadata::publicParameters($parameters);
        return new EntryParameters(ResolutionMetadata::invocationSlots($values), $values, $provenance);
    }

    /** @param array<string|int,mixed>|EntryParameters $parameters */
    public static function from(array|EntryParameters $parameters): EntryParameters
    {
        if ($parameters instanceof EntryParameters) {
            return $parameters;
        }
        if ($parameters === []) {
            /** @var EntryParameters|null $empty */
            static $empty = null;
            return $empty ??= new EntryParameters([], [], null);
        }
        return self::capture($parameters);
    }

    public static function provenance(EntryParameters $parameters): ?MappedRequestContext
    {
        return $parameters->provenance;
    }

    /** @return array<string|int,mixed> */
    public static function invocationArguments(EntryParameters $parameters): array
    {
        return $parameters->arguments;
    }

    public static function resolveDefinition(EntryParameters $parameters, DefinitionResolverInterface $strategy, string $id, DefinitionInterface $definition): mixed
    {
        return $strategy->resolveDefinition($id, $definition, $parameters->factoryValues);
    }
}
