<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/**
 * Marks a class, parameter, or property for native virtual-proxy creation.
 *
 * A virtual proxy is an instance of a concrete proxy class whose backing
 * object is produced lazily on first observable access. PHP therefore needs a
 * concrete class even when the injection point itself is typed as an
 * interface or the service is addressed by an arbitrary container id.
 *
 * On a class, omit the argument: the marked class is the proxy class. On a
 * parameter or property, pass the concrete class when it cannot be inferred
 * from the declared type or from #[Make]'s entry.
 *
 * For services that can initialize the same object in place, prefer
 * class-level {@see Lazy}; it avoids a separate backing object.
 *
 * Reference: {@see \ReflectionClass::newLazyProxy()} on PHP 8.4+.
 *
 * ## Examples
 *
 * ```php
 * // Class-level proxy: HeavyService is both the declared and proxy class.
 * #[Proxy]
 * class HeavyService {}
 *
 * // Concrete parameter type can be inferred.
 * public function __construct(
 *     #[Proxy] HeavyService $service,
 * ) {}
 *
 * // Interface-typed injection requires a concrete proxy class.
 * public function __construct(
 *     #[Make(CacheInterface::class), Proxy(RedisCache::class)]
 *     CacheInterface $cache,
 * ) {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Proxy
{
    /** @param class-string|null $class Concrete proxy class for an injection point. */
    public function __construct(
        public ?string $class = null,
    ) {}
}
