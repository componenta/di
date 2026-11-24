# Definition API

Explicit definitions can be passed to `Container::set()` or configured through a provider's `getFactories()` section. Classes in this section use `Componenta\DI\Definition`.

## Definition factories and descriptors

| Type | Public API | Purpose |
| --- | --- | --- |
| `Definition` | `static factory(callable\|array $factory): FactoryDefinition`<br>`static reference(string $entryId): ReferenceDefinition`<br>`static invokable(string $className): InvokableDefinition` | Convenient descriptor constructors. |
| `DefinitionInterface` | No methods | Marker interface accepted by registered definition strategies. |
| `FactoryDefinition` | `__construct(callable\|array $value)` and readable `$value` | Callable factory descriptor, created on demand. |
| `InvokableDefinition` | `__construct(string $value)`; readable `$value` | Class created with a zero-argument constructor call. |
| `ReferenceDefinition` | `__construct(string $value)`; readable `$value` | Reference to another container ID **inside configured arguments**. Not a standalone definition handled by built-in strategies. |

Factory callables may declare a compatible prefix of `(ContainerValue $context, array $params)` and own the creation of their products. Invokables use no explicit constructor parameters and do not run DI attributes on their target.

Both factory descriptor entry points copy the receiver and method of a callable array before PHP validates it. The descriptor keeps the selected route and the identity of an object receiver. An invalid callable array raises `TypeError`.

## ClassDefinition

`ClassDefinition` provides explicit constructor arguments, ordered configured method calls and optional type-based fallback:

```php
public function __construct(
    string $value,
    array $constructorParams = [],
    array $methodCalls = [],
    bool $autowire = false,
);
public static function create(string $className): self;
public function constructor(array $params): self;
public function call(string $method, array $params = []): self;
public function autowire(bool $enabled = true): self;
```

Readable public state: `$value`, `$constructorParams`, `$methodCalls`, `$autowire`. Mutator-style methods return **new** objects. `call()` preserves repeated calls and replaces the removed `method()` name.

```php
use Componenta\DI\Definition\{ClassDefinition, Definition};

$definition = ClassDefinition::create(Client::class)
    ->constructor(['endpoint' => 'https://api.example.test'])
    ->call('setLogger', [Definition::reference(LoggerInterface::class)])
    ->autowire();

$container->set(Client::class, $definition);
```

Missing arguments use PHP defaults; `autowire()` additionally resolves available class/interface types for missing arguments. Explicit arguments, including `null`, take precedence. Type-name keys in runtime input are **not** constructor arguments for this definition. Configured nested arrays resolve reference descriptors recursively, while runtime overrides are treated literally. Unknown configured names/positions and cyclic configured arrays are rejected.

`ClassDefinition` calls an accessible constructor and configured methods directly; it **never executes DI attribute handlers** on the class, properties, constructor parameters, or configured methods. If construction fails, the unsuccessful result is not shared.

## Other public contracts

`Componenta\DI\FactoryInterface` declares `make(string $entry, array $params = []): object` and is implemented by `Container`. The definition strategy contracts `DefinitionResolverInterface` and `EntryResolverInterface` are documented in [Extension contracts](../guide/extensions.md).

See [Definition guide](../guide/definitions.md) and [Migration](../migrations/di6-definitions.md).
