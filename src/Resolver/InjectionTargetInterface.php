<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Unified view over a reflection target that can be injected into - either a
 * function/method parameter or a class property.
 *
 * The interface abstracts away the differences between {@see ReflectionParameter}
 * and {@see ReflectionProperty} so that resolvers written against a single
 * contract can cover both use cases without duplication.
 */
interface InjectionTargetInterface
{
    /**
     * Declared name (without the leading `$`).
     */
    public function getName(): string;

    /**
     * Declared type, or null for untyped targets.
     */
    public function getType(): ?ReflectionType;

    /**
     * Whether the declared type permits null (nullable type or `null` union).
     */
    public function allowsNull(): bool;

    /**
     * Whether a default value is declared.
     */
    public function isDefaultValueAvailable(): bool;

    /**
     * Declared default value. Callers must check {@see isDefaultValueAvailable()}
     * first; behaviour is undefined otherwise.
     */
    public function getDefaultValue(): mixed;

    /**
     * Positional index for parameters (0-based); `null` for properties.
     */
    public function getPosition(): ?int;

    /**
     * Fully-qualified context description - `Class::method()` for a parameter,
     * `Class::$prop` for a property - suitable for error messages.
     */
    public function getDeclaringContext(): string;

    /**
     * First attribute instance of the given class attached to this target, or
     * null if none.
     *
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    public function getFirstAttribute(string $attributeClass): ?object;

    /**
     * All attribute instances of the given class attached to this target.
     * Returns `null` when none are present (matches
     * {@see \Componenta\Reflection\Reflection::getMetadata()} conventions).
     *
     * @template T of object
     * @param class-string<T>|null $attributeClass
     * @return list<T>|null
     */
    public function getAttributes(?string $attributeClass = null): ?array;

    /**
     * The underlying reflector, useful for exception context and for the few
     * code paths that still need to poke at native reflection APIs.
     */
    public function getReflector(): ReflectionParameter|ReflectionProperty;
}
