<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;

/**
 * Thrown when a cycle is detected while resolving services or aliases.
 *
 * The chain that produced the cycle is kept on the exception so consumers
 * can render it without re-parsing the message.
 */
final class CircularDependencyException extends RuntimeException implements ExceptionInterface
{
    /**
     * @param list<string> $chain Resolution chain ending with the repeated id.
     */
    public function __construct(
        public readonly array $chain,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf(
                'Circular dependency detected: %s.',
                implode(' -> ', $chain),
            );
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * Cycle during service resolution.
     *
     * @param list<string> $chain
     */
    public static function forService(array $chain): self
    {
        return new self($chain);
    }

    /**
     * Cycle in an alias chain.
     *
     * @param list<string> $chain
     */
    public static function forAlias(array $chain): self
    {
        return new self(
            $chain,
            sprintf('Circular alias reference: %s.', implode(' -> ', $chain)),
        );
    }
}
