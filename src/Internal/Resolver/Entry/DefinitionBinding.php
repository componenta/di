<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;

/** Immutable registration snapshot, independent of its originating composite. @internal */
final readonly class DefinitionBinding
{
    public function __construct(
        public string $id,
        public DefinitionResolverInterface $strategy,
        public DefinitionInterface $definition,
        private object $owner,
    ) {}

    public function belongsTo(object $owner): bool
    {
        return $this->owner === $owner;
    }
}
