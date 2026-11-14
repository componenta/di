<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;

/** Declarative factory input awaiting semantic admission. @internal */
final readonly class ConfiguredFactoryDefinition implements DefinitionInterface
{
    public function __construct(public mixed $factory) {}
}
