<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\Reflection\Reflection;

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
        if (in_array($id, $this->excluded, true)) {
            return false;
        }

        $class = Reflection::class($id);
        return $class !== null && $this->objects->canCreate($class);
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): object
    {
        if (in_array($id, $this->excluded, true)) {
            throw NotFoundException::forService($id);
        }

        $class = Reflection::class($id);
        if ($class === null || !$this->objects->canCreate($class)) {
            throw NotFoundException::forService($id);
        }
        return $this->objects->create($class, $params);
    }
}
