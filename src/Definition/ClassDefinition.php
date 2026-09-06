<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

use Componenta\DI\Exception\InvalidConfigurationException;

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
final readonly class ClassDefinition implements DefinitionInterface
{
    /**
     * @param class-string $value Class name to instantiate.
     * @param array<string|int, mixed> $constructorParams
     * @param list<array{
     *     method: non-empty-string,
     *     params: array<string|int, mixed>
     * }> $methodCalls
     */
    public function __construct(
        public string $value,
        public array $constructorParams = [],
        public array $methodCalls = [],
        public bool $autowire = false,
    ) {}

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
        return new self($this->value, $params, $this->methodCalls, $this->autowire);
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

        $methodCalls = $this->methodCalls;
        $methodCalls[] = [
            'method' => $method,
            'params' => $params,
        ];

        return new self($this->value, $this->constructorParams, $methodCalls, $this->autowire);
    }

    /** Enables type-based DI fallback for arguments absent from the definition and runtime input. */
    public function autowire(bool $enabled = true): self
    {
        return new self($this->value, $this->constructorParams, $this->methodCalls, $enabled);
    }
}
