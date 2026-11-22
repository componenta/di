<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Closure;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreation;
use Componenta\DI\Internal\Resolver\Entry\ObjectPipelineScope;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;

/** Creates objects through constructor resolution and attribute handling. */
final class ObjectPipeline extends ObjectPipelineScope
{
    public function __construct(AttributeProcessor $attributes, ParametersResolver $parameters, ProxyFactoryInterface $proxies)
    {
        parent::__construct(new ObjectCreation($attributes, $parameters, $proxies));
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     */
    public function prepare(string|ReflectionClass $class): void
    {
        $this->creation->prepare($class);
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     */
    public function canCreate(string|ReflectionClass $class): bool
    {
        return $this->creation->canCreate($class);
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     * @param array<string|int,mixed>|EntryParameters $params Immediate argument source. Leave empty when supplying a callback.
     * @param Closure(object):void|null $configure Runs after constructor and attribute initialization.
     * @param (Closure():(array<string|int,mixed>|EntryParameters))|null $resolveParameters Supplies the arguments on demand.
     */
    public function create(
        string|ReflectionClass $class,
        array|EntryParameters $params = [],
        ?Closure $configure = null,
        ?Closure $resolveParameters = null,
    ): object {
        return $this->creation->create($class, $params, $configure, $resolveParameters);
    }
}
