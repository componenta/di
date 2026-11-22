<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Container;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Internal\AvailableEntryLookup;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ConstructionParameterScope;

/** Parameters for one construction that can be forwarded without losing their origin. */
final class EntryParameters extends ConstructionParameterScope
{
    /** @var array<string|int,mixed> */
    public array $values {
        get => ResolutionMetadata::snapshot($this->arguments);
    }

    /** Keeps the origin of the remaining arguments. */
    public function without(string|int $key): self
    {
        if (!array_key_exists($key, $this->arguments)) {
            return $this;
        }

        $values = $this->arguments;
        $factoryValues = $this->factoryValues;
        unset($values[$key], $factoryValues[$key]);
        return new self($values, $factoryValues, $this->provenance?->without($key));
    }

    /** @param non-empty-string $entry */
    public function forwardTo(FactoryInterface $factory, string $entry): object
    {
        return match (true) {
            $factory instanceof Container => AvailableEntryLookup::make($factory, $entry, $this),
            $factory instanceof EntryParametersFactoryInterface => $factory->makeEntry($entry, $this),
            default => $factory->make($entry, $this->factoryValues),
        };
    }
}
