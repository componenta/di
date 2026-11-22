<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Object\ObjectPipeline;
use ReflectionClass;

use function Componenta\DI\Internal\is_entry_class_eligible;

/** Reflection fallback for entries without an explicit definition. */
final class ReflectionResolver implements EntryResolverInterface
{
    /** @var array<string,ReflectionClass<object>> */
    private array $classes = [];

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

    /** @param array<string|int, mixed>|EntryParameters $params */
    public function resolve(string $id, array|EntryParameters $params = []): object
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

        $key = strtolower(str_starts_with($id, '\\') ? substr($id, 1) : $id);
        if (isset($this->classes[$key])) {
            return $this->classes[$key];
        }
        if (!class_exists($id) && !interface_exists($id, false) && !trait_exists($id, false)) {
            return null;
        }

        $class = new ReflectionClass($id);
        if (in_array($class->getName(), $this->excluded, true) || !is_entry_class_eligible($class)) {
            return null;
        }
        return $this->classes[$key] = $class;
    }
}
