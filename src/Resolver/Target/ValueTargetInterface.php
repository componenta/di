<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use ReflectionType;
use Reflector;

/** Common metadata required by provider/transform/fallback value pipelines. */
interface ValueTargetInterface
{
    public string $name { get; }

    public ?ReflectionType $type { get; }

    /** @return list<class-string> */
    public array $typeNames { get; }

    /** @return class-string|null */
    public ?string $className { get; }

    public bool $allowsNull { get; }

    public string $declaringContext { get; }

    public function reflector(): Reflector;

    public function accepts(mixed $value): bool;
}
