# Container and configuration API

Public types use the `Componenta\DI` namespace unless qualified otherwise. Use [Getting started](../guide/getting-started.md) for a complete application example.

## ContainerFactory

```php
public function create(Config $config, DependencyDefinitions $dependencies): ContainerValue;
public function createWithTargetedAttributeDiscovery(Config $config, DependencyDefinitions $dependencies): ContainerValue;
```

Both methods assemble the container and return `Componenta\Config\ContainerValue`, containing the container and the supplied configuration. The second restricts autoload checks to attributes declared on the current target; it is an alternative to the normal discovery policy.

## Container

`Container` implements `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface`, and `ProxyFactoryInterface`.

| Public method | Behavior |
| --- | --- |
| `get(string $id): mixed` | Retrieve the shared local result, or delegate to an owning external container. |
| `has(string $id): bool` | Determine whether an identifier is known; it does not guarantee successful construction. |
| `make(string $entry, array $params = []): object` | Create an object through the local definition/fallback path; bypass sharing and delegators. |
| `call(mixed $callable, array $params = []): mixed` | Resolve callable parameters and invoke the target. |
| `resolve(mixed $callable): callable` | Resolve a callable specification without invoking it. |
| `makeLazy(string $class, callable $initializer): object` | Construct a deferred native lazy ghost through the selected proxy factory. |
| `makeProxy(string $class, callable $factory): object` | Construct a deferred virtual proxy through the selected proxy factory. |
| `set(string $id, mixed $entry): void` | Replace a local value or register a definition, invalidating affected caches. |
| `alias(string $alias, string $target): void` | Add or retarget an alias, with cycle/protected-ID checks. |
| `delegator(string $id, callable\|string\|array $delegator): void` | Append a delegator to a local entry's result pipeline. |
| `addContainer(ContainerInterface $container): void` | Add an external PSR-11 provider. |
| `notifyExternalEntriesChanged(ContainerInterface $provider, string ...$ids): void` | Notify DI that an already registered provider changed specified identifiers. |

Direct construction is also exposed:

```php
public function __construct(
    EntryResolverInterface $resolver,
    CallableExecutorInterface $callableExecutor,
    array $aliases = [],
    array $services = [],
    ?ProxyFactoryInterface $proxyFactory = null,
);
```

Direct use requires explicitly assembled collaborators. `ContainerFactory` is the normal application entry point and registers the built-in services and collaborators.

`set()`, `alias()`, `delegator()` and `addContainer()` cannot replace protected core services. Mutations invalidate affected cached values. `MutationRetirementException` denotes an already committed mutation whose replaced values failed to release. See [Runtime behavior](../guide/runtime.md).

## Configuration helpers

| Type | Public API | Purpose |
| --- | --- | --- |
| `Componenta\DI\ConfigKey` | Inherits constants from `Componenta\Config\ConfigKey` | Key names for the configuration/dependency schema. |
| `Componenta\DI\Configuration\DependencyConfiguration` | `static normalize(array $dependencies, array $defaultAliases = []): array` | Normalize the dependency schema without constructing services. |
| `DependencyConfiguration` | `static assertShape(array &$dependencies): void` | Validate and replace the supplied array with canonical values on success. |
| `FactoryInterface` | `make(string $entry, array $params = []): object` | Narrow contract for creating a service object, optionally with arguments. |

`normalize()` alone does not verify whether a factory can execute. `Config`, `DependencyDefinitions`, `Environment`, and `ConfigProvider` are public types provided by the separate `componenta/config` package.

## Lower-level object creation

`Componenta\DI\Object\ObjectPipeline` exposes:

```php
public function __construct(
    AttributeProcessor $attributes,
    ParametersResolver $parameters,
    ProxyFactoryInterface $proxies,
);
public function prepare(string|ReflectionClass $class): void;
public function canCreate(string|ReflectionClass $class): bool;
public function create(
    string|ReflectionClass $class,
    array|EntryParameters $params = [],
    ?Closure $configure = null,
    ?Closure $resolveParameters = null,
): object;
```

It builds objects through constructor resolution and attribute handling. `prepare()` prepares metadata; `canCreate()` tests eligibility; `create()` accepts immediate arguments or a deferred supplier, with an optional post-initialization configuration callback.

`Componenta\DI\Object\CreationStrategy` has cases `Eager`, `Lazy`, `Proxy`. The selection is performed through the object-creation context by registered handlers.

## See also

[Callable API](callables.md) · [Definitions](definitions.md) · [Lazy factories](lazy.md) · [Extension contracts](extensions.md).
