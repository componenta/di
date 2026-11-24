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
 * - **Autowired services** (resolved by reflection): supported for targets
 *   eligible for the selected lazy factory.
 *   The constructor is invoked on the lazy instance using arguments resolved
 *   by the configured parameter-resolver pipeline, unless {@see NoConstructor}
 *   suppresses it. Property injection and setup still run during initialization.
 * - **Factory-bound services** (resolved by an application factory):
 *   not supported. Ghost mode requires constructor visibility, which
 *   opaque factories do not provide. Use explicit proxy injection with
 *   {@see Proxy} instead, or
 *   implement {@see \Componenta\DI\LazyServiceFactoryInterface} on the
 *   factory to take direct control of laziness.
 *
 * The default {@see \Componenta\DI\ProxyFactory} requires a non-static
 * backed instance property, including inherited private properties. An
 * otherwise native-eligible class with no properties, only static properties
 * or only virtual hooks is rejected before allocation with
 * {@see \Componenta\DI\Exception\ResolutionException}. Other native failures
 * retain their existing exception route. Remove this attribute for ordinary
 * eager reflection creation, or use an explicit factory / ClassDefinition
 * with its own initialization contract. Custom factory implementations keep
 * their supported mechanisms and the deferred-callback contract.
 *
 * Reference: {@see \ReflectionClass::newLazyGhost()} on PHP 8.4+.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Lazy {}
