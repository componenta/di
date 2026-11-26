# Lazy-object factory API

All interfaces below belong to `Componenta\DI`. The package requires PHP 8.5.13+ and uses PHP's native lazy-object reflection facilities by default.

| Type | Public API | Contract |
| --- | --- | --- |
| `LazyObjectFactoryInterface` | `makeLazy(string $class, callable $initializer): object` | Create a lazy ghost whose initializer receives the instance and runs later. |
| `VirtualProxyFactoryInterface` | `makeProxy(string $class, callable $factory): object` | Create a virtual proxy whose factory receives the proxy and returns its backing object when needed. |
| `ProxyFactoryInterface` | Extends both interfaces | A factory supporting both lazy creation strategies. |
| `ProxyFactory` | `makeLazy()`, `makeProxy()` | Default implementation using `ReflectionClass::newLazyGhost()` and `newLazyProxy()`. |
| `LazyServiceFactoryInterface` | `lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object` | Allows a custom factory entry to construct and own its lazy wrapper. |

The following examples use `Catalog` from the [guide](../guide/lazy-objects.md).

```php
$ghost = $container->makeLazy(
    Catalog::class,
    static function (Catalog $instance): void {
        $instance->__construct();
    },
);

$proxy = $container->makeProxy(
    Catalog::class,
    static fn(Catalog $proxy): Catalog => new Catalog(),
);
```

Neither callback runs during allocation. The initializer receives the object to initialize and must return no value; the proxy factory receives the proxy and must return a compatible non-lazy real instance distinct from it.

The default `ProxyFactory` rejects otherwise eligible classes with no stored instance properties before allocation (`ResolutionException`), rather than returning an object whose initializer or factory never runs. For the reason, initialization triggers, and restrictions on proxy backing types, see the [lazy objects guide](../guide/lazy-objects.md). Custom implementations may use a different mechanism while preserving deferred initialization.

## Factory-owned laziness

A registered factory object implementing `LazyServiceFactoryInterface` may be non-invokable. Register it directly in `getFactories()`; the resolver calls `lazy()` with the container's `ContainerValue` (a PSR-11 container), proxy factory and runtime arguments instead of invoking it as an ordinary factory. The class in this example must satisfy the native adapter's restrictions.

```php
use Componenta\Config\ConfigProvider;
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

final class CatalogProvider extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [Catalog::class => new CatalogFactory()];
    }
}
```

See [Lazy objects guide](../guide/lazy-objects.md) and [Attribute reference](../guide/attribute-reference.md).
