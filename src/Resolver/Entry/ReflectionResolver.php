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
    /** @param list<class-string> $excluded */
    public function __construct(
        private readonly ObjectPipeline $objects,
        private readonly array $excluded = [],
    ) {}

    public function can(string $id): bool
    {
        $class = $this->candidate($id);
        return $class !== null && $this->objects->canCreate($class);
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): object
    {
        $class = $this->candidate($id);
        if ($class === null || !$this->objects->canCreate($class)) {
            throw NotFoundException::forService($id);
        }
        return $this->objects->create($class, $params);
    }

    /** @return ReflectionClass<object>|null */
    private function candidate(string $id): ?ReflectionClass
    {
        if (in_array($id, $this->excluded, true)) {
            return null;
        }

        $class = Reflection::class($id);
        return $class !== null && !in_array($class->getName(), $this->excluded, true) ? $class : null;
    }
}
