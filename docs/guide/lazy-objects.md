# Lazy objects and virtual proxies

The container supports two native PHP strategies for deferred initialization:

- `#[Lazy]` initializes the same object in place when its state is needed.
- `#[Proxy]` defers creation of a separate real instance until the proxy needs its state. The proxy and the real instance have distinct identities.

Both attributes can be applied to reflection-created classes. `#[Proxy]` also supports parameters and properties. The corresponding methods for explicit creation are `makeLazy()` and `makeProxy()`; see the [factory API](../api/lazy.md).

## When initialization occurs

```php
use Componenta\DI\Attribute\Lazy;

#[Lazy]
final class Catalog
{
    public function __construct(private array $items = []) {}

    public function name(): string
    {
        return 'catalog';
    }

    public function count(): int
    {
        return count($this->items);
    }
}

$catalog = $container->get(Catalog::class);
$catalog->name();  // Does not initialize the object.
$catalog->count(); // Reading $items triggers initialization.
```

Calling a method does not, by itself, initialize a lazy object. PHP triggers initialization when the object's state is observed or changed, for example when reading a property. For reflection-created services, the container then runs constructor injection, property injection and `#[SetUp]`. The attribute `#[NoConstructor]` suppresses only the constructor.

## Why a stored instance property is required

The default `ProxyFactory` requires at least one declared non-static property with storage, including inherited private properties. PHP's lazy-object mechanism tracks deferred initialization through instance properties. If a class has no such properties (including classes with only static or virtual properties), `ReflectionClass::newLazyGhost()` and `newLazyProxy()` return a non-lazy instance. This does **not** mean that the constructor, initializer or real-instance factory has run.

To prevent a silently uninitialized result, `ProxyFactory` rejects these otherwise eligible classes with `ResolutionException` before allocation. It neither invokes the callback immediately nor silently switches to eager creation. This also excludes a plain `stdClass`, which has no declared stored properties. A custom implementation of `ProxyFactoryInterface` may support other mechanisms.

Do not add a dummy property solely to satisfy this requirement. If deferred initialization is not needed, remove the lazy attribute and let the container create the class normally. An explicit factory or `ClassDefinition` is another option when you want to control construction yourself; neither automatically executes the target's DI attributes.

## Virtual proxy restrictions

The factory passed to `makeProxy()` must return a compatible non-lazy object distinct from the proxy. PHP permits the same class or, subject to its [native inheritance restrictions](https://www.php.net/manual/en/reflectionclass.newlazyproxy.php), a compatible parent instance. For `#[Proxy]` at an injection point, DI additionally requires the real object to be an instance of the selected proxy class.

An injection-point proxy obtains its real object through `make()`, with the entry and arguments supplied by `#[Make]` when present. Do not wrap an already-lazy result in another native proxy. Class-level attributes do not automatically wrap the result of an application factory; a factory can instead implement [LazyServiceFactoryInterface](../api/lazy.md#factory-owned-laziness).

See [PHP's lazy-object lifecycle and initialization triggers](https://www.php.net/manual/en/language.oop5.lazy-objects.php) and the [attribute reference](attribute-reference.md).
