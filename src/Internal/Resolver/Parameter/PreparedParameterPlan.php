<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Immutable prepared execution structure for one ordered parameter list.
 *
 * @internal
 */
final class PreparedParameterPlan
{
    /** @var list<ParameterTarget>|null */
    private ?array $sourceCandidates = null;

    /**
     * @param list<ParameterTarget> $targets
     */
    public function __construct(
        public readonly array $targets,
        public readonly int $resolverRevision,
        private readonly object $owner,
        public readonly bool $requiresFreshValidation,
    ) {}

    /** @return list<ParameterTarget> */
    public function sourceCandidates(): array
    {
        return $this->sourceCandidates ??= array_values(array_filter(
            $this->targets,
            static fn(ParameterTarget $target): bool => ParameterTargetAccess::declaration($target)->mayHaveAttributes,
        ));
    }

    public function belongsTo(object $owner): bool
    {
        return $this->owner === $owner;
    }
}
