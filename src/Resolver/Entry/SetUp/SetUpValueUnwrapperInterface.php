<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\DI\Exception\ExceptionInterface;

/**
 * Unwraps a value-object that appears inside {@see \Componenta\DI\Attribute\SetUp::$params}.
 *
 * SetUp params are plain PHP values merged into the parameter resolution
 * context. When one of them is a value-object like {@see \Componenta\DI\Attribute\EntryId},
 * {@see \Componenta\DI\Attribute\Config} or {@see \Componenta\DI\Attribute\Env}, it must
 * be converted to its resolved value before being passed to the method.
 *
 * Each implementation handles exactly one value type (SRP). New types are
 * added by writing a new unwrapper - no need to modify existing code (OCP).
 */
interface SetUpValueUnwrapperInterface
{
    /**
     * Returns true when this unwrapper recognises the given value.
     */
    public function supports(mixed $value): bool;

    /**
     * Converts a supported value to its resolved form.
     *
     * @param string $key Name of the key in the SetUp::$params array. Useful
     *                    when the value-object does not carry its own identifier
     *                    (e.g. implicit-name Env/Config).
     *
     * @throws ExceptionInterface If resolution fails and cannot fall back to a
     *                            default. Implementations must not leak raw
     *                            PSR-11 / framework exceptions.
     */
    public function unwrap(mixed $value, string $key): mixed;
}
