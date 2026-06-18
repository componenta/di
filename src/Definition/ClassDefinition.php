<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Class instantiation with constructor params and method calls.
 *
 * Immutable builder-style definition: `constructor()` and `method()` return
 * a new definition instance with the requested change applied.
 *
 * @example
 * ```php
 * ClassDefinition::create(UserService::class)
 *     ->constructor(['timeout' => 30])
 *     ->method('setLogger', [Definition::reference(LoggerInterface::class)])
 * ```
 */
final readonly class ClassDefinition implements DefinitionInterface
{
    /**
     * @param class-string $value Class name to instantiate.
     * @param array<string|int, mixed> $constructorParams
     * @param array<string, array<string|int, mixed>> $methodCalls
     */
    public function __construct(
        public string $value,
        public array $constructorParams = [],
        public array $methodCalls = [],
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
        return new self($this->value, $params, $this->methodCalls);
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function method(string $method, array $params = []): self
    {
        $methodCalls = $this->methodCalls;
        $methodCalls[$method] = $params;

        return new self($this->value, $this->constructorParams, $methodCalls);
    }
}
