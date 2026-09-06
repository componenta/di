<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Componenta\DI\Definition\DefinitionInterface;
use RuntimeException;

/**
 * Raised when the container or builder is given invalid configuration.
 *
 * Covers static configuration errors, invalid definitions and specialized
 * configuration failures such as invalid attribute composition.
 */
class InvalidConfigurationException extends RuntimeException implements ExceptionInterface
{
    public static function forSelfReferencingAlias(string $alias): self
    {
        return new self(sprintf('Self-referencing alias: "%s".', $alias));
    }

    public static function forInvalidDefinition(DefinitionInterface $definition): self
    {
        return new self(sprintf(
            'Definition of type "%s" is not supported.',
            $definition::class,
        ));
    }

    public static function forUnsupportedDefinition(
        DefinitionInterface $definition,
        string $resolverClass,
    ): self {
        return new self(sprintf(
            'Definition of type "%s" is not supported by resolver "%s".',
            $definition::class,
            $resolverClass,
        ));
    }
}
