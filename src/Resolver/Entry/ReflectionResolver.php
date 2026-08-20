<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\Reflection\Reflection;
use ReflectionClass;

/** Reflection fallback for entries without an explicit definition. */
final class ReflectionResolver implements EntryResolverInterface
{
    public function __construct(private readonly ObjectPipeline $objects) {}

    public function can(string $id): bool
    {
        $class = $this->reflect($id);
        return $class !== null && $this->objects->canCreate($class);
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): object
    {
        $class = $this->reflect($id);
        if ($class === null || !$this->objects->canCreate($class)) {
            throw NotFoundException::forService($id);
        }
        return $this->objects->create($class, $params);
    }

    /** @return ReflectionClass<object>|null */
    private function reflect(string $id): ?ReflectionClass
    {
        return Reflection::class($id);
    }
}
