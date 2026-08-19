<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\InvalidConfigurationException;

/** Cross-attribute cardinality policy for one semantic capability. */
final readonly class CapabilityPolicy
{
    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function __construct(
        public string $capability,
        public ?int $maxPerTarget = null,
    ) {
        if (!is_a($capability, AttributeCapabilityInterface::class, true)) {
            throw new InvalidConfigurationException(sprintf(
                'Capability "%s" must implement %s.',
                $capability,
                AttributeCapabilityInterface::class,
            ));
        }

        if ($maxPerTarget !== null && $maxPerTarget < 1) {
            throw new InvalidConfigurationException(
                'Capability maxPerTarget must be null or at least 1.',
            );
        }
    }
}
