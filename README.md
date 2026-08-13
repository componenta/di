# Componenta DI

PSR-11 dependency injection container for PHP 8.4+. It provides shared-entry caching, reflection autowiring, fresh object creation, DI-aware callable invocation, attribute-based parameter and property injection, PSR-7 request mapping, native lazy objects, virtual proxies, aliases, delegators, external-container bridging, and build-time compiled factory shards.

**[English](README.md)** | **[Russian](README.ru.md)**

## Package boundary

`componenta/di` owns runtime dependency resolution. It does not scan an application or choose its configuration providers. Class discovery, provider compilation, deployment cache orchestration, and entry-point bootstrapping belong to the application layer, normally `componenta/app`.

Property injection is supported only through attributes and attribute handlers. Ahead-of-time compilation produces ordinary factory definitions; runtime reflection remains the fallback for dynamic classes.

## Installation

```bash
composer require componenta/di
```

The package requires PHP 8.4 or newer. The main runtime dependencies are:

| Package | Purpose |
|---|---|
| `psr/container` | PSR-11 contracts. |
| `psr/http-message` | PSR-7 request attributes and DTO mapping. |
| `componenta/config` | Configuration, environment values, and factory `ContainerValue`. |
| `componenta/caster` | `#[Cast]` and request-value casting. |
| `componenta/validation` | Optional request DTO validation. |
| `componenta/reflection` | Cached reflection helpers and PHP 8.4 lazy-object access. |
| `componenta/priority-list` | Priority-ordered parameter resolver registration. |
| `componenta/var-export` | PHP configuration cache generation. |

## Core behavior

- `get(string $id)` returns the shared, cached entry for an id.
- `make(string $entry, array $params = [])` creates a fresh object and does not read or populate the entry cache.
- `call(mixed $callable, array $params = [])` resolves missing callable arguments and invokes it.
- Constructor and callable arguments can be supplied by name or position in `$params`.
- `make(Target::class, ['value' => 'provided'])` passes `value` to a constructor or setup method parameter. It does not write an ordinary public property.
- Attributed properties are processed by the attribute-handler pipeline.

## Quick start

```php
use App\Logging\FileLogger;
use App\Logging\LoggerInterface;
use App\Service\UserService;
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger('/var/log/app.log'))
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$logger = $container->get('logger');
$first = $container->make(UserService::class, ['userId' => 7]);
$second = $container->make(UserService::class, ['userId' => 7]);

assert($first !== $second);
```

When an id has no explicit binding, the reflection resolver can autowire any eligible class whose constructor parameters can be resolved.

## Public contracts

Parameter names are part of the public API because PHP named arguments may use them.

| Contract | Signature | Purpose |
|---|---|---|
| `Psr\Container\ContainerInterface` | `get(string $id)`, `has(string $id)` | Shared service lookup. |
| `FactoryInterface` | `make(string $entry, array $params = [])` | Fresh object creation. |
| `CallableInvokerInterface` | `call(mixed $callable, array $params = [])` | DI-aware invocation. |
| `CallableResolverInterface` | `resolve(mixed $callable)` | Callable normalization. |
| `CallableExecutorInterface` | `resolve(...)` and `call(...)` | Both callable capabilities. |
| `LazyObjectFactoryInterface` | `makeLazy(string $class, callable $initializer)` | Native lazy ghost creation. |
| `VirtualProxyFactoryInterface` | `makeProxy(string $class, callable $factory)` | Native virtual proxy creation. |
| `ProxyFactoryInterface` | both lazy methods | A combined lazy-object contract. |

The concrete `Container` additionally exposes `set()`, `alias()`, `delegator()`, and `addContainer()` for bootstrap code. Ordinary services should depend on the narrow contract they use.

## Resolution lifecycle

`Container::get($id)` uses this order:

1. Return a decorated result already cached for the requested id.
2. Resolve the requested id to its canonical alias target.
3. Enter circular-dependency protection for the canonical id.
4. Return a locally cached base entry when present.
5. If no local base exists, ask registered external PSR-11 containers.
6. If no external container owns the id, run the local entry-resolver chain and cache its base result.
7. Apply delegators registered for the requested id and cache the decorated result.

Local entries therefore take precedence over external containers. `has()` converts only container-level resolution failures to `false`; programming errors inside resolver code remain visible.

`make()` resolves aliases but deliberately skips runtime entry caches, external containers, and delegators. It always requires an object result.

## ContainerBuilder

`ContainerBuilder` is the supported assembly API.

| Method | Effect |
|---|---|
| `addFactory(string $id, callable $factory)` | Register a factory. |
| `addFactories(array $factories)` | Register factories in bulk. |
| `addInvokable(string $classOrAlias, ?string $class = null)` | Register an invokable class; the two-argument form also creates an alias. |
| `addInvokables(array $invokables)` | Register invokables in bulk. |
| `addAlias(string $alias, string $target)` | Register an alias. |
| `addAliases(array $aliases)` | Register aliases in bulk. |
| `addDelegator(string $id, callable|string|array $delegator)` | Register a decorator. |
| `addDelegators(array $delegators)` | Register decorators in bulk. |
| `addService(string $id, mixed $service)` | Register a prebuilt shared value. |
| `addServices(array $services)` | Register shared values in bulk. |
| `addParameterResolver(mixed $resolver, int $priority = 0)` | Extend the parameter pipeline. |
| `replaceParameterResolvers(bool $replace = true)` | Omit built-in parameter resolvers. |
| `addAttributeHandler(mixed $handler)` | Extend the attribute pipeline. |
| `replaceAttributeHandlers(bool $replace = true)` | Omit built-in attribute handlers. |
| `compileFactories(iterable $entries, string $directory, ?ParameterResolverCodeGeneratorRegistry $generators = null, int $maxShardBytes = 131072, string $namespace = 'Componenta\DI\Generated')` | Compile known autowiring roots and their concrete dependency graph into factory shards. |
| `toArray()` | Export the current configuration. |
| `build()` | Build a sealed runtime container. |

A delegator method reference may use a concrete class, an interface, or an opaque service id, for example `[DecoratorInterface::class, 'decorate']` or `['decorator.service', 'decorate']`. In bulk/configuration input, wrap an opaque service-id method reference as `[['decorator.service', 'decorate']]`; the flat `['first', 'second']` form remains a list of two string delegators.

Parameter resolvers and attribute handlers may be registered as an instance, a service id, a callable factory, or a `[service-id, 'method']` factory. The method receives the container and returns the extension. Unlike bulk delegators, an extension specification is already one value, so `['extension.factory', 'create']` needs no additional nesting.

A normal factory receives `Componenta\Config\ContainerValue` and the per-resolution context:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn (ContainerValue $container, array $context): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

`ContainerValue` implements `ContainerInterface` and also exposes typed/config-aware lookup helpers.

## Definitions

`Definition` creates immutable entry descriptions:

```php
use Componenta\DI\Definition\Definition;

$container->set(
    ReportService::class,
    Definition::autowire(ReportService::class)
        ->constructor(['format' => 'pdf'])
        ->method('boot'),
);
```

Available definitions are `factory()`, `autowire()`, `reference()`, and `invokable()`. A `ReferenceDefinition` is intended for constructor or setup arguments inside a class definition.

## Configuration

`Container::create(Config $config)` and `ContainerBuilder::configure(Config $config)` read `ConfigKey::DEPENDENCIES`.

| Key | Shape |
|---|---|
| `ConfigKey::FACTORIES` | `array<string, callable|string|array|FactoryDefinition|ClassDefinition|CompiledFactoryDefinition>` |
| `ConfigKey::INVOKABLES` | `list<class-string>` or `array<string, class-string>` |
| `ConfigKey::ALIASES` | `array<string, string>` |
| `ConfigKey::DELEGATORS` | `array<string, callable|string|array|list<...>>` |
| `ConfigKey::SERVICES` | `array<string, mixed>` |
| `ConfigKey::PARAMETER_RESOLVERS` | `array<int, class-string|callable|array{0:string,1:string}|ParameterResolverInterface>` |
| `ConfigKey::PARAMETER_RESOLVERS_REPLACE` | `bool` |
| `ConfigKey::ATTRIBUTE_HANDLERS` | `list<class-string|callable|array{0:string,1:string}|AttributeHandlerInterface>` |
| `ConfigKey::ATTRIBUTE_HANDLERS_REPLACE` | `bool` |

Unknown keys and malformed shapes are rejected with `InvalidConfigurationException`.

`configureFromCache($config, $cache, $baseDir)` accepts only the versioned persistent-cache envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Raw dependency arrays and the former `validated` cache marker are not accepted. When `$baseDir` is provided, relative paths in compiled factory definitions are resolved against it.

`ConfigProvider` registers optional casting, current-user, and PSR-7 request resolvers. Componenta application bootstrap can discover it through package metadata.

## Attributes

Property values are written only by registered attribute handlers. Constructor/callable parameters use parameter resolvers; attributes that target both parameters and properties participate in both pipelines.

| Attribute | Target and behavior |
|---|---|
| `#[Inject]` | Property: resolve by declared class/interface type. |
| `#[EntryId('id')]` | Parameter/property: resolve an explicit entry id. |
| `#[Config('path')]` | Parameter/property: read application config. |
| `#[Env('NAME')]` | Parameter/property: read the environment, with optional default. |
| `#[Make(Service::class)]` | Parameter/property: create a fresh object. |
| `#[Init(callable, params)]` | Property: initialize from a callable. |
| `#[Cast(...)]` | Parameter/property: cast a resolved value. |
| `#[CurrentUser]` | Parameter/property: inject the request user when its provider is configured. |
| `#[SetUp('method', params)]` | Class: call a setup method after construction; repeatable. |
| `#[NoConstructor]` | Class: allocate without running the constructor. |
| `#[Lazy]` | Class: construct as a native lazy ghost. |
| `#[Proxy(?ConcreteClass::class)]` | Class or injection point: use a virtual proxy; interface-typed or service-id injection points require a concrete proxy class. |

PSR-7 scalar attributes are `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]`, and `#[UploadedFile]`.

Request mappers are `#[MapQueryString]`, `#[MapRequestPayload]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]`, and `#[MapUploadedFiles]`. They can transform an array or create a class-typed DTO through `FactoryInterface::make()`.

When a validator exists for the DTO class, mapping validates the extracted raw request data before `transform()`. This order is intentional: aliases, casts, defaults, sort mapping, and exclusions must not hide malformed transport input.

Mappers may combine their primary source with selected request attributes and uploaded files. Different values for the same key are rejected by default with `RequestDataConflictException`; identical duplicates are accepted. Pass `conflictPolicy: RequestDataConflictPolicy::FirstWins` explicitly only when the mapper's source order is part of the endpoint trust contract.

## Callable invocation

`call()` accepts closures, global function names, `"Class::method"` strings, invokable service ids, `[object, 'method']`, and `[class/interface/service-id, 'method']` references. Explicit parameters win over resolver output by name or position. Exceptions thrown by the target callable propagate unchanged.

## Lazy objects and proxies

A lazy initializer mutates the uninitialized object it receives. A virtual-proxy factory returns the real backing object:

```php
$lazy = $container->makeLazy(
    Service::class,
    static function (Service $instance): void {
        $instance->__construct();
    },
);

$proxy = $container->makeProxy(
    Service::class,
    static fn (object $proxy): Service => new Service(),
);
```

Factory-bound services are eager unless their factory implements `LazyServiceFactoryInterface`. Class-level `#[Lazy]` and `#[Proxy]` apply to reflection/invokable construction, not arbitrary objects returned by factories. For an interface-typed or service-id injection point, use `#[Proxy(ConcreteClass::class)]` so PHP's native proxy API has a concrete class to instantiate.

## Extension points

A parameter resolver implements:

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

A successful result is `[position, value]`; `null` lets the next resolver try. Higher priorities run first.

An attribute handler implements `AttributeHandlerInterface`, exposes immutable `phase` and `priority` properties, and defines `supportsAttribute()` plus `handle()`. Handlers that can emit generated PHP may additionally implement `CompilableAttributeHandlerInterface`.

Both extension types can be supplied directly or materialized from a service/callable factory, including `['service.id', 'method']`. The builder seals both extension registries after assembly. Mutating a resolved registry at runtime is rejected.

## Production compiled factories

Known autowiring roots can be compiled into ordinary entries in `ConfigKey::FACTORIES`. The compiler follows concrete constructor, `#[Inject]`, and `#[SetUp]` dependencies. Existing services, invokables, and explicitly configured factories keep ownership and are never replaced.

```php
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

$builder = ContainerBuilder::configure($config);
$compiled = $builder->compileFactories(
    entries: [new AutowireEntry(CreateOrder::class)],
    directory: __DIR__ . '/var/cache/build',
);

$dependencies = $config->get(ConfigKey::DEPENDENCIES, []);
$dependencies[ConfigKey::FACTORIES] = array_replace(
    $compiled,
    $dependencies[ConfigKey::FACTORIES] ?? [], // explicit factories win
);
```

Each `CompiledFactoryDefinition` contains a relative shard file, generated class, and factory method. Shards have content-addressed names, are loaded only when one of their entries is first resolved, and are then reused by that container. A normal cold load does not hash the shard before `require`; if the same generated class is already loaded from another physical cache root, the loader accepts it only when both shard files have the same SHA-256. Dynamic classes continue through reflection autowiring.

Application integration normally owns root discovery. `componenta/app` provides the build-only `AutowireEntryContributorInterface` flow and recognizes `#[Autowire]`; Router, CQRS, and boot discovery contribute their known runtime entry classes automatically.

`DiCacheGeneratorInterface::generate(array $config, string $path)` atomically writes the exact supplied array as PHP. It does not discover classes or compile factories. To load the result with `configureFromCache()`, generate the versioned cache envelope shown above. Runtime entry caches remain inside each `Container` instance; persistent cache files and OPcache are deployment concerns.
## Exceptions

| Exception | Meaning |
|---|---|
| `NotFoundException` | No entry resolver can handle the id. |
| `CircularDependencyException` | A resolution cycle was detected. |
| `ResolutionException` | Object, parameter, property, factory, or constructor resolution failed. |
| `InvalidConfigurationException` | Configuration or a definition is invalid. |
| `InvalidCallableException` | A callable cannot be normalized. |
| `DelegatorException` | A delegator failed. |
| `RequestDataConflictException` | Request mapping received different values for one key from multiple sources. |

All package exceptions implement `Componenta\DI\Exception\ExceptionInterface`.