<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

/** Immutable result of compiling one class factory. */
final readonly class GeneratedFactory
{
    /**
     * @param class-string $class
     * @param list<class-string>|null $plainAutowireTypes
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $code,
        public ?array $plainAutowireTypes = null,
    ) {}
}
