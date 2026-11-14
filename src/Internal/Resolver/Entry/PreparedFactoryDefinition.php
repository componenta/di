<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;

/** Factory specification and its validation policy captured as one snapshot. @internal */
final readonly class PreparedFactoryDefinition implements DefinitionInterface
{
    public function __construct(
        public mixed $factory,
        public bool $validateResolvedCallable,
    ) {}
}
