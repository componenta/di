<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\ArraySnapshot;

/**
 * Declarative factory with explicit constructor arguments and ordered method calls.
 *
 * Target attributes are never executed. Type-based fallback is opt-in.
 * Fluent constructor(), call() and autowire() methods return
 * a new definition instance with the requested change applied. Repeated calls
 * to the same method are preserved and executed in registration order.
 *
 * @example
 * ```php
 * ClassDefinition::create(UserService::class)
 *     ->constructor(['timeout' => 30])
 *     ->call('setLogger', [Definition::reference(LoggerInterface::class)])
 * ```
 */
final class ClassDefinition implements DefinitionInterface
{
    // Snapshot code is available before the constructor receives the caller's arguments.
    use ArraySnapshot;

    /** @var array<string|int, mixed> */
    private readonly array $arguments;
    /** @var list<array{method: non-empty-string, params: array<string|int,mixed>}> */
    private readonly array $calls;
    private readonly bool $containsArrayReferences;

    /** @var array<string|int, mixed> */
    public array $constructorParams {
        get => $this->containsArrayReferences ? self::snapshot($this->arguments) : $this->arguments;
    }

    /** @var list<array{method: non-empty-string, params: array<string|int,mixed>}> */
    public array $methodCalls {
        get => $this->containsArrayReferences ? self::snapshot($this->calls) : $this->calls;
    }

    /**
     * @param class-string $value Class name to instantiate.
     * @param array<string|int, mixed> $constructorParams
     * @param list<array{
     *     method: non-empty-string,
     *     params: array<string|int, mixed>
     * }> $methodCalls
     */
    public function __construct(
        public readonly string $value,
        array $constructorParams = [],
        array $methodCalls = [],
        public readonly bool $autowire = false,
    ) {
        [$this->arguments, $this->calls] = self::snapshot(
            [$constructorParams, $methodCalls],
            $containsArrayReferences,
        );
        $this->containsArrayReferences = $containsArrayReferences;
    }

    /**
     * @param class-string $className
     */
    public static function create(string $className): self
    {
        return new self($className);
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function constructor(array $params): self
    {
        return new self($this->value, $params, $this->calls, $this->autowire);
    }

    /**
     * @param non-empty-string $method
     * @param array<string|int, mixed> $params
     */
    public function call(string $method, array $params = []): self
    {
        if ($method === '') {
            throw new InvalidConfigurationException(
                'Class definition method name must be a non-empty string.',
            );
        }

        $methodCalls = $this->calls;
        $methodCalls[] = [
            'method' => $method,
            'params' => $params,
        ];

        return new self($this->value, $this->arguments, $methodCalls, $this->autowire);
    }

    /** Enables type-based DI fallback for arguments absent from the definition and runtime input. */
    public function autowire(bool $enabled = true): self
    {
        return new self($this->value, $this->arguments, $this->calls, $enabled);
    }
}
