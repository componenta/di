# Lazy objects and virtual proxies

`Container::makeLazy()` creates a native lazy ghost; `makeProxy()` creates a native virtual proxy. Reflection-created services can select them with `#[Lazy]` and `#[Proxy]`.

```php
use Componenta\DI\Attribute\Lazy;

#[Lazy]
final class Catalog
{
    private array $items = [];

    public function count(): int { return count($this->items); }
}

$catalog = $container->get(Catalog::class);
$count = $catalog->count(); // Initializes the lazy instance when required.
```

The reflection pipeline runs constructor injection, property injection and `#[SetUp]` at initialization; `#[NoConstructor]` suppresses only the constructor.

## Restrictions

The default `ProxyFactory` requires at least one **non-static backed instance property** (an inherited private property also qualifies). Propertyless classes, and classes with only static/virtual properties, are rejected before allocation with `ResolutionException`. Other native PHP restrictions still apply.

Virtual proxies require an appropriate eager backing object, not another lazy proxy. With `#[Proxy]` at an injection point, DI also requires the backing object to be an instance of the selected proxy class. Avoid placing an outer proxy around a target already marked `#[Lazy]` or `#[Proxy]`.

An application factory owns its result's creation: class-level lazy attributes do not wrap factory products. Use reflection creation with attributes, an explicit supported proxy-injection path, or a custom lazy factory. For eager creation, remove the lazy marker or use an ordinary factory/`ClassDefinition`.

[Attribute guide](attributes.md) · [PHP native virtual proxy rules](https://www.php.net/manual/en/reflectionclass.newlazyproxy.php)
