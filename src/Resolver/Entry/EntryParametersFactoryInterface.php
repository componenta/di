<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\FactoryInterface;

/** Factory adapter that can continue an existing entry construction. */
interface EntryParametersFactoryInterface extends FactoryInterface
{
    /** @param non-empty-string $entry */
    public function makeEntry(string $entry, EntryParameters $parameters): object;
}
