# Lazy objects and virtual proxies

The container supports PHP's native lazy objects. `makeLazy(string $class, callable $initializer)` creates a lazy ghost; `makeProxy(string $class, callable $factory)` creates a virtual proxy.

Reflection-created services may opt in through class-level `#[Lazy]` and `#[Proxy]`; `#[Proxy]` is also supported at injection points.

```php
use Componenta\DI\Attribute\Lazy;

#[Lazy]
final class Catalog
{
    private array $items = [];

    public function count(): int
    {
        return count($this->items);
    }
}

$catalog = $container->get(Catalog::class);
$count = $catalog->count(); // Initialization occurs when PHP requires the object state.
```

Construction is deferred, including constructor injection, property injection and `#[SetUp]`. `#[NoConstructor]` suppresses only the constructor.

## Native adapter restrictions

The default `ProxyFactory` needs at least one non-static backed instance property, including inherited private properties. Classes with no backed instance properties cannot use this adapter: it throws `ResolutionException` before target allocation. PHP's other eligibility restrictions remain in force. A custom `ProxyFactoryInterface` implementation may support other mechanisms.

For a virtual proxy, its backing factory must return an appropriate, non-lazy object distinct from the proxy. An injection-point `#[Proxy]` additionally requires the backing value to be an instance of the selected proxy class. Avoid layering a proxy over a service that is already created lazily through a class attribute.

Class-level `#[Lazy]` or `#[Proxy]` does not wrap the result of an opaque application factory. Use a compatible reflection creation path, an explicit proxy injection point, or a custom lazy-service factory. Removing the lazy attribute or using an ordinary application factory/`ClassDefinition` provides an eager construction path, with the initialization rules of that path.

See [Attributes](attributes.md) and [PHP's native proxy API](https://www.php.net/manual/en/reflectionclass.newlazyproxy.php).
