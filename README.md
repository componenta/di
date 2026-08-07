# Componenta DI

PSR-11 dependency injection container for PHP 8.4+. It provides shared-entry caching, reflection autowiring, fresh object creation, DI-aware callable invocation, attribute-based parameter and property injection, PSR-7 request mapping, native lazy objects, virtual proxies, aliases, delegators, external-container bridging, and generated entry resolvers.

**[English](README.md)** | **[Russian](README.ru.md)**

## Package boundary

`componenta/di` owns runtime dependency resolution. It does not scan an application or choose its configuration providers. Class discovery, provider compilation, deployment cache orchestration, and entry-point bootstrapping belong to the application layer, normally `componenta/app`.

Property injection is supported only through attributes and attribute handlers. Generated entry resolvers are the only ahead-of-time resolution path.

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
| `AliasResolverInterface` | `resolve`, `set`, `has` | Low-level alias management. |

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
| `useGeneratedEntryResolver(?string $file, ?string $releaseFingerprint = null)` | Configure a generated resolver artifact. |
| `compileGeneratedEntryResolver(iterable $classes, string $file, ?ParameterResolverCodeGeneratorRegistry $generators = null, string $namespace = 'Componenta\DI\Generated', ?string $releaseFingerprint = null)` | Generate and configure that artifact. |
| `toArray()` | Export the current configuration. |
| `build()` | Build a sealed runtime container. |

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
| `ConfigKey::FACTORIES` | `array<string, callable|string|array|FactoryDefinition|ClassDefinition>` |
| `ConfigKey::INVOKABLES` | `list<class-string>` or `array<string, class-string>` |
| `ConfigKey::ALIASES` | `array<string, string>` |
| `ConfigKey::DELEGATORS` | `array<string, callable|string|array|list<...>>` |
| `ConfigKey::SERVICES` | `array<string, mixed>` |
| `ConfigKey::PARAMETER_RESOLVERS` | `array<int, class-string|callable|ParameterResolverInterface>` |
| `ConfigKey::PARAMETER_RESOLVERS_REPLACE` | `bool` |
| `ConfigKey::ATTRIBUTE_HANDLERS` | `list<class-string|callable|AttributeHandlerInterface>` |
| `ConfigKey::ATTRIBUTE_HANDLERS_REPLACE` | `bool` |
| `ConfigKey::GENERATED_ENTRY_RESOLVER_FILE` | `?string` |
| `ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT` | `?string` |

Unknown keys and malformed shapes are rejected with `InvalidConfigurationException`.

`configureFromCache($config, $cache, $baseDir)` accepts either a versioned cache envelope or a raw dependency array. When `$baseDir` is provided, a relative generated-resolver path is resolved against it.

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
| `#[Proxy]` | Class or injection point: use a virtual proxy. |

PSR-7 scalar attributes are `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]`, and `#[UploadedFile]`.

Request mappers are `#[MapQueryString]`, `#[MapRequestPayload]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]`, and `#[MapUploadedFiles]`. They can transform an array or create a class-typed DTO through `FactoryInterface::make()`.

## Callable invocation

`call()` accepts closures, global function names, `"Class::method"` strings, invokable service ids, `[object, 'method']`, and `[class-string, 'method']`. Explicit parameters win over resolver output by name or position. Exceptions thrown by the target callable propagate unchanged.

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

Factory-bound services are eager unless their factory implements `LazyServiceFactoryInterface`. Class-level `#[Lazy]` and `#[Proxy]` apply to reflection/invokable construction, not arbitrary objects returned by factories.

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

The builder seals both extension registries after assembly. Mutating a resolved registry at runtime is rejected.

## Production and generated resolvers

A generated entry resolver replaces reflection and runtime pipeline traversal for the listed classes while retaining reflection fallback for every other eligible class.

```php
$release = getenv('APP_RELEASE');

$builder = ContainerBuilder::configure($config);

$builder->compileGeneratedEntryResolver(
    classes: [CreateOrder::class, OrderService::class],
    file: __DIR__ . '/var/cache/di.entries.php',
    releaseFingerprint: $release,
);

$container = $builder->build();
```

A later process can load the same artifact:

```php
$container = ContainerBuilder::configureFromCache(
    $config,
    require __DIR__ . '/var/cache/di.config.php',
    __DIR__,
)->build();
```

The loader validates format and generator versions, parameter-resolver order/state, attribute-handler order/state, and source compatibility. An invalid, missing, or unreadable artifact is ignored safely and the runtime falls back to reflection.

Fingerprint modes:

- With `releaseFingerprint: null`, every load recalculates SHA-256 for relevant class, interface, parent, trait, handler, resolver, and generator source files.
- With a non-empty release fingerprint, runtime source hashing is skipped. The deployment identifier must change whenever application code or DI extension configuration changes.

`DiCacheGeneratorInterface::generate(array $config, string $path)` atomically writes the exact supplied array as PHP. It does not discover classes or generate entry resolvers. Runtime entry caches remain inside each `Container` instance; persistent cache files and OPcache are deployment concerns.

## Exceptions

| Exception | Meaning |
|---|---|
| `NotFoundException` | No entry resolver can handle the id. |
| `CircularDependencyException` | A resolution cycle was detected. |
| `ResolutionException` | Object, parameter, property, factory, or constructor resolution failed. |
| `InvalidConfigurationException` | Configuration or a definition is invalid. |
| `InvalidCallableException` | A callable cannot be normalized. |
| `DelegatorException` | A delegator failed. |

All package exceptions implement `Componenta\DI\Exception\ExceptionInterface`.
