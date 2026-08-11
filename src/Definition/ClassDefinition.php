<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Class instantiation with constructor params and ordered method calls.
 *
 * Immutable builder-style definition: `constructor()` and `method()` return
 * a new definition instance with the requested change applied. Repeated calls
 * to the same method are preserved and executed in registration order.
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
     * @param list<array{
     *     method: non-empty-string,
     *     params: array<string|int, mixed>
     * }> $methodCalls
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
     * @param non-empty-string $method
     * @param array<string|int, mixed> $params
     */
    public function method(string $method, array $params = []): self
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

        return new self($this->value, $this->constructorParams, $methodCalls);
    }
}
