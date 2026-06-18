<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/**
 * Marks a class, parameter, or property for virtual-proxy instantiation.
 *
 * The wrapper is a generated subtype of the target class that forwards
 * all operations to a real backing instance produced lazily on first
 * observable access.
 *
 * `instanceof` and type hints work transparently; `get_class()` reports
 * the generated proxy class name, not the marked class.
 *
 * ## When to use
 *
 * Use {@see Proxy} when the wrapped class:
 *
 *  - Is built by an opaque factory whose constructor cannot be called
 *    directly on a pre-allocated instance (vendor builders, decorators,
 *    subclass-returning factories).
 *  - Is referenced through an interface and the concrete class is not
 *    known statically.
 *
 * For services where preserving class identity is important (the common
 * case), prefer {@see Lazy} which produces a lazy object - same class,
 * `get_class()` intact.
 *
 * Reference: {@see \ReflectionClass::newLazyProxy()} on PHP 8.4+.
 *
 * ## Examples
 *
 * ```php
 * // On class - every resolution returns a virtual proxy.
 * #[Proxy]
 * class HeavyService {}
 *
 * // On parameter - only this injection point gets a proxy.
 * public function __construct(
 *     #[Proxy] HeavyService $service,
 * ) {}
 *
 * // On property - only this injection point gets a proxy.
 * class Controller {
 *     #[Proxy]
 *     private HeavyService $service;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Proxy
{
}
