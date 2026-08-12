<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;

/**
 * Mutable state of one parameter-resolution operation.
 *
 * Explicit invocation arguments and ambient resolution context are separate
 * immutable inputs. Resolved values are accumulated in-place while parameters
 * are processed in declaration order.
 */
final class ParameterResolutionContext
{
    /** @var array<int, mixed> */
    public private(set) array $resolved;

    /** @var array<string|int, mixed> */
    public readonly array $arguments;

    /** @var array<string|int, mixed> */
    public readonly array $context;

    /** @var array<string|int, true> */
    private array $consumedArguments = [];

    /**
     * Backward-compatible merged view for resolver diagnostics and custom
     * resolvers. Explicit arguments win when the same key exists in context.
     *
     * @var array<string|int, mixed>
     */
    public array $provided {
        get => array_replace($this->context, $this->arguments);
    }

    /** @var array<string|int, mixed> */
    public array $unusedArguments {
        get => array_diff_key($this->arguments, $this->consumedArguments);
    }

    /**
     * The first parameter intentionally keeps its historical `$provided` name
     * so named construction by third-party resolvers remains source-compatible.
     * It now represents explicit invocation arguments; ambient values belong in
     * the third `$context` parameter.
     *
     * @param array<string|int, mixed> $provided
     * @param array<int, mixed>        $resolved
     * @param array<string|int, mixed> $context
     */
    public function __construct(
        array $provided = [],
        array $resolved = [],
        array $context = [],
    ) {
        $this->arguments = $provided;
        $this->context = $context;
        $this->resolved = $resolved;
    }

    public function resolve(int $position, mixed $value): void
    {
        $this->resolved[$position] = $value;
    }

    public function consumeArgument(string|int $key): void
    {
        if (array_key_exists($key, $this->arguments)) {
            $this->consumedArguments[$key] = true;
        }
    }

    public function assertArgumentsConsumed(): void
    {
        if ($this->unusedArguments === []) {
            return;
        }

        throw ResolutionException::forUnusedArguments(
            $this->unusedArguments,
            $this->resolved,
        );
    }
}
