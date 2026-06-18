<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Componenta\DI\Definition\DefinitionInterface;
use RuntimeException;

/**
 * Raised when the container or builder is given invalid configuration.
 *
 * Covers both static configuration errors (self-referencing aliases, invalid
 * factory shapes) and definition-level errors (unknown/unsupported definition
 * types) - previously split between this class and `InvalidDefinitionException`.
 */
final class InvalidConfigurationException extends RuntimeException implements ExceptionInterface
{
    public static function forSelfReferencingAlias(string $alias): self
    {
        return new self(
            sprintf('Self-referencing alias: "%s".', $alias),
        );
    }

    /**
     * Definition type is not recognised by any resolver in the chain.
     */
    public static function forInvalidDefinition(DefinitionInterface $definition): self
    {
        return new self(
            sprintf('Definition of type "%s" is not supported.', $definition::class),
        );
    }

    /**
     * Definition type is not supported by the specific resolver.
     */
    public static function forUnsupportedDefinition(
        DefinitionInterface $definition,
        string $resolverClass,
    ): self {
        return new self(
            sprintf(
                'Definition of type "%s" is not supported by resolver "%s".',
                $definition::class,
                $resolverClass,
            ),
        );
    }
}
