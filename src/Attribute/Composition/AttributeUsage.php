<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** One composed DI attribute usage together with its semantic definition. */
final readonly class AttributeUsage
{
    /** @var ReflectionAttribute<object> */
    public ReflectionAttribute $reflection;

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function __construct(
        public object $attribute,
        public AttributeDefinition $definition,
        public ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        public int $declarationOrder,
    ) {
        $reflection = $target->getAttributes()[$declarationOrder] ?? null;
        if ($reflection === null) {
            throw new LogicException('Attribute usage reflection metadata is unavailable.');
        }
        $this->reflection = $reflection;
    }

    /** Creates an isolated runtime attribute instance for one handler invocation. */
    public function newInstance(): object
    {
        return $this->reflection->newInstance();
    }
}
