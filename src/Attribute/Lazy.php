<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/**
 * Marks a class as resolved through a lazy object - an instance of the
 * marked class itself, with state filled in place on first observable
 * access.
 *
 * The wrapper preserves class identity in full: `get_class()`,
 * `instanceof`, `static::class`, type hints, and identity checks all
 * report the marked class.
 *
 * ## Compatibility
 *
 * - **Autowired services** (resolved by reflection): fully supported.
 *   The constructor is invoked on the lazy instance using arguments
 *   resolved from the DI plan.
 * - **Factory-bound services** (resolved by an application factory):
 *   not supported. Ghost mode requires constructor visibility, which
 *   opaque factories do not provide. Use {@see Proxy} instead, or
 *   implement {@see \Componenta\DI\LazyServiceFactoryInterface} on the
 *   factory to take direct control of laziness.
 *
 * Reference: {@see \ReflectionClass::newLazyGhost()} on PHP 8.4+.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Lazy
{
}
