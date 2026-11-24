# Lazy-object factory API

All interfaces below belong to `Componenta\DI`. The package requires PHP 8.5.13+ and uses PHP's native lazy-object reflection facilities by default.

| Type | Public API | Contract |
| --- | --- | --- |
| `LazyObjectFactoryInterface` | `makeLazy(string $class, callable $initializer): object` | Create a lazy ghost whose initializer receives the instance and runs later. |
| `VirtualProxyFactoryInterface` | `makeProxy(string $class, callable $factory): object` | Create a virtual proxy whose factory receives the proxy and returns its backing object when needed. |
| `ProxyFactoryInterface` | Extends both interfaces | A factory supporting both lazy creation strategies. |
| `ProxyFactory` | `makeLazy()`, `makeProxy()` | Default implementation using `ReflectionClass::newLazyGhost()` and `newLazyProxy()`. |
| `LazyServiceFactoryInterface` | `lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object` | Allows a custom factory entry to construct and own its lazy wrapper. |

```php
$ghost = $container->makeLazy(
    CachedData::class,
    static function (CachedData $instance): void {
        $instance->__construct();
    },
);

$proxy = $container->makeProxy(
    CachedData::class,
    static fn(CachedData $proxy): CachedData => new CachedData(),
);
```

Callbacks are **deferred**: object allocation does not call the initializer or backing factory. For the default adapter, eligible classes need a non-static backed instance property; an inherited private property qualifies. Propertyless classes fail before allocation with `ResolutionException`. The backing object must be a suitable non-lazy class instance, not the proxy itself, and follow PHP's native class/parent restrictions.

At a `#[Proxy]` injection point, DI imposes the additional requirement that the backing object be an instance of the explicitly selected proxy class. Custom adapters may use other mechanisms but must preserve deferred initialization. A class-level `#[Lazy]` or `#[Proxy]` affects reflection creation, not arbitrary application factory products.

## Factory-owned laziness

A registered factory object implementing `LazyServiceFactoryInterface` may be non-invokable. Register it directly in `getFactories()`; the resolver calls `lazy()` with the container's `ContainerValue` (a PSR-11 container), proxy factory and runtime arguments instead of invoking it as an ordinary factory. The class in this example must satisfy the native adapter's restrictions.

```php
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerInterface;

final class CatalogFactory implements LazyServiceFactoryInterface
{
    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        return $proxyFactory->makeProxy(
            Catalog::class,
            static fn(Catalog $proxy): Catalog => new Catalog(),
        );
    }
}

// In ConfigProvider:
protected function getFactories(): array
{
    return [Catalog::class => new CatalogFactory()];
}
```

See [Lazy objects guide](../guide/lazy-objects.md) and [Attribute reference](../guide/attribute-reference.md).
