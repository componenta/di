# Componenta DI

PSR-11 dependency injection container for PHP 8.4+ with reflection autowiring, explicit factories and invokables, aliases, delegators, external-container fallback, attribute-based injection, PSR-7 request mapping, native lazy objects and build-time compiled factories.

**[English](README.md)** | **[Русский](README.ru.md)**

## Package boundary

`componenta/di` owns runtime dependency resolution. Application scanning, configuration-provider discovery, deployment cache orchestration and entry-point bootstrapping belong to the application layer, normally `componenta/app`.

Property injection is attribute-driven. Ahead-of-time compilation creates ordinary factory definitions; reflection remains the runtime fallback for dynamic classes.

## Installation

```bash
composer require componenta/di
```

PHP 8.4 or newer is required.

## Core API

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

- `get(string $id)` first gives registered external containers the original requested id; when none owns it, local shared resolution runs and caches its base/decorated result.
- `make(string $entry, array $params = [])` creates a fresh object and skips runtime entry caches, external containers and delegators.
- `call(mixed $callable, array $params = [])` normalizes a callable, resolves missing arguments and invokes it.
- Constructor and callable arguments may be supplied by name or position.
- Ordinary public properties are never filled from `$params`; property injection is performed only by attribute handlers.

When an id has no explicit binding, the reflection resolver may autowire an eligible class whose dependencies are resolvable.

## Public contracts

Parameter names are part of the public API because PHP named arguments may use them.

| Contract | Purpose |
|---|---|
| `Psr\Container\ContainerInterface` | Shared `get()` / `has()` lookup. |
| `FactoryInterface` | Fresh `make()` object creation. |
| `CallableInvokerInterface` | Callable invocation capability. |
| `CallableResolverInterface` | Callable normalization. |
| `CallableExecutorInterface` | Callable normalization plus DI-aware invocation. |
| `LazyObjectFactoryInterface` | Native lazy ghost creation. |
| `VirtualProxyFactoryInterface` | Native virtual proxy creation. |
| `ProxyFactoryInterface` | Combined lazy/proxy factory contract. |

The concrete `Container` additionally exposes `set()`, `alias()`, `delegator()` and `addContainer()` for bootstrap/runtime configuration.

## Resolution lifecycle

`Container::get($id)` resolves entries in this order:

1. Ask registered external PSR-11 containers for the original requested id.
2. If none owns it, return an already cached local decorated result for that requested id.
3. Resolve local aliases to the canonical id.
4. Return a locally cached base entry when present.
5. Otherwise run the local resolver chain and cache the base result.
6. Apply local delegators and cache the decorated result.

External lookup happens exactly once and always uses the original requested id. Local aliases are not forwarded to external containers, and an external entry is returned directly without local aliasing, local caches or local delegators. This means an external container also takes precedence over an already materialized local value for `get()`/`has()`.

The external-container registry itself is lazy internal state: it remains `null` when no external containers are registered and is allocated by the first `addContainer()` call. Lookup paths use null-safe access and therefore pay no registry allocation cost in the common no-external-container case.

`make()` resolves aliases but deliberately skips shared caches, external containers and delegators. Fresh-resolution dependency cycles are detected by the same cycle guard used by shared resolution.

Cycle tracking is local to each execution context. If `get()` starts resolving a shared canonical id while the same id is still being resolved by another Fiber, it throws `ConcurrentResolutionException`; retry after the owning Fiber has completed. The container never resumes a foreign Fiber.

## ContainerBuilder

`ContainerBuilder` is the supported assembly API. Its main methods are:

- `addFactory()` / `addFactories()`
- `addInvokable()` / `addInvokables()`
- `addAlias()` / `addAliases()`
- `addDelegator()` / `addDelegators()`
- `addService()` / `addServices()`
- `addParameterResolver()` / `replaceParameterResolvers()`
- `addAttributeHandler()` / `replaceAttributeHandlers()`
- `compileFactories()`
- `toArray()`
- `build()`

`addService()` and `ConfigKey::SERVICES` register prebuilt shared values verbatim. An object that implements `DefinitionInterface` is still a stored service value on this builder path. Resolver definitions are configuration: compatible definition objects may be supplied directly in the factory/invokable configuration sections or through `Container::set($id, $definition)`. Definition objects and the corresponding section shorthand configure the same resolver state.

A delegator method reference may use a class, interface or opaque service id, for example `[DecoratorInterface::class, 'decorate']` or `['decorator.service', 'decorate']`. In bulk/config input, an opaque service-method delegator must be nested as `[['decorator.service', 'decorate']]`; the flat `['first', 'second']` form means two string delegators unless it is an actual class/interface method reference.

Parameter resolvers and attribute handlers may be supplied as instances, service ids, callable factories or `[service-id, 'method']` factories. The builder seals both extension registries after assembly.

A normal service factory receives `Componenta\Config\ContainerValue` and the current resolution context:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn (ContainerValue $container, array $context): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

## Definitions

`Definition` provides compact immutable factory, reference and invokable definitions. Use `ClassDefinition` to configure class constructor arguments or setup calls:

```php
use Componenta\DI\Definition\ClassDefinition;

$container->set(
    ReportService::class,
    ClassDefinition::create(ReportService::class)
        ->constructor(['format' => 'pdf'])
        ->method('boot'),
);
```

Definitions are resolver configuration, not a separate runtime overlay. `FactoryDefinition`, `ClassDefinition` and `CompiledFactoryDefinition` are accepted directly in `ConfigKey::FACTORIES`; `InvokableDefinition` is accepted in `ConfigKey::INVOKABLES` and is normalized to the same class-string form used by declarative invokable shorthand. The same forms work when dependency sections come from a `ConfigProvider`.

Declarative definitions and runtime definitions share the same definition types but have different lifecycles. Definitions held by `ContainerBuilder`/configuration participate in normalization and persistent-cache compilation. `FactoryDefinition` is reduced to its factory callable, `InvokableDefinition` to its class-string, and `ClassDefinition` is compiled by a `DefinitionCodeGeneratorInterface` into an ordinary factory closure before the cache file is written. A definition supplied later through `Container::set()` mutates only the already-built resolver state; it is not copied back into the builder, compiled or persisted.

`Container::set($id, $definition)` reconfigures the supporting resolver and removes a materialized local base entry for that id so the new definition can be resolved. `Container::set($id, $value)` only replaces the shared local value; it does not remove or roll back resolver configuration, so `make($id)` continues to use the configured resolver binding.

Available `Definition` helpers are `factory()`, `reference()` and `invokable()`. `ReferenceDefinition` represents a container entry reference inside class-definition arguments. During `ClassDefinition` code generation, references are emitted as container lookups inside the generated factory. `InvokableDefinition` enforces the same non-empty class-string shape as invokable shorthand.

Definition code generation is extensible through `DefinitionCodeGeneratorInterface` and `DefinitionCodeGeneratorRegistry`. The generator contract accepts `DefinitionInterface`; the registry selects the concrete generator by definition class/interface, so custom definition types do not require changing the compiler.

## Configuration

`Container::create(Config $config)` and `ContainerBuilder::configure(Config $config)` read `ConfigKey::DEPENDENCIES`.

Supported dependency keys are:

- `ConfigKey::FACTORIES`
- `ConfigKey::INVOKABLES`
- `ConfigKey::ALIASES`
- `ConfigKey::DELEGATORS`
- `ConfigKey::SERVICES`
- `ConfigKey::PARAMETER_RESOLVERS`
- `ConfigKey::PARAMETER_RESOLVERS_REPLACE`
- `ConfigKey::ATTRIBUTE_HANDLERS`
- `ConfigKey::ATTRIBUTE_HANDLERS_REPLACE`

Unknown keys and malformed shapes throw `InvalidConfigurationException`.

`configureFromCache()` accepts only the versioned persistent-cache envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Raw dependency arrays are rejected. The former `validated: true` marker is accepted only as a deprecated, ignored compatibility field for older application-level cache producers; it never skips validation or makes compiled factories trusted. New producers must omit it. When `$baseDir` is supplied, relative compiled-factory paths are confined to that base directory.

Cache envelope versions are strict. Version 10 rejects v9 and earlier artifacts because mapped-request provenance is now enforced against the actual constructor target, including persistent `ClassDefinition` closures. Regenerate the persistent cache and all compiled-factory shards during deployment instead of reusing or editing older artifacts.

## Attributes

Built-in attribute behavior includes:

| Attribute | Behavior |
|---|---|
| `#[Inject]` | Inject a property by declared class/interface type. |
| `#[EntryId('id')]` | Resolve an explicit entry id for a parameter/property. |
| `#[Config('path')]` | Read application configuration. |
| `#[Env('NAME')]` | Read an environment value. |
| `#[Make(Service::class)]` | Create a fresh object. |
| `#[Init(callable, params)]` | Initialize a property from a callable. Mutable promoted properties are supported. |
| `#[Cast(...)]` | Cast a resolved value. |
| `#[CurrentUser]` | Inject the current user when its provider is configured. |
| `#[SetUp('method', params)]` | Run a setup method after construction; repeatable. |
| `#[NoConstructor]` | Allocate a class without calling its constructor. |
| `#[Lazy]` | Use a native lazy ghost. Mutually exclusive with class-level `#[Proxy]`. |
| `#[Proxy(?ConcreteClass::class)]` | Use a virtual proxy. Class-level use is mutually exclusive with `#[Lazy]`. |

Built-in DI property handlers reject static properties instead of silently ignoring their attributes. Initialized readonly promoted properties remain constructor-owned and are not overwritten by property handlers.

Scalar PSR-7 extraction attributes are `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]` and `#[UploadedFile]`.

Request mappers are `#[MapQueryString]`, `#[MapRequestPayload]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]` and `#[MapUploadedFiles]`. They may return arrays or create class-typed DTOs through `FactoryInterface::make()`.

Class-typed HTTP DTO mapping accepts only named top-level string keys, both before validation and after mapper transformation. Integer keys, including numeric JSON object keys decoded as integers by PHP, are rejected instead of being interpreted as constructor positions. This restriction is limited to HTTP DTO mapping; trusted programmatic `Container::make()` calls continue to accept arguments by name or position.

Parameter attributes that implement `ParameterSourceAttributeInterface` declare an explicit value source during HTTP DTO mapping. After mapper transformation, mapped data may not provide the source-bound parameter name or any of its declared class/interface type keys; such input throws `RequestParameterSourceConflictException` instead of becoming an explicit DI override. Mapped-key provenance follows the nested `make()` operation through aliases to the actual constructor target and is checked before any built-in or custom parameter resolver runs, so resolver priority cannot bypass the source boundary. Runtime and persistent `ClassDefinition` construction apply the same mapped-input guard while ordinary programmatic constructor parameters keep their existing override semantics. The exact `ServerRequestInterface` and `UriInterface` types are also treated as implicit trusted sources. Their subtypes are not reserved implicitly unless a source attribute marks the parameter, because the runtime request resolvers do not source arbitrary PSR-7 subtypes.

When validation is available for a DTO, extracted raw transport data is validated before mapper transformation. This is intentional: mapping, defaults, casts and exclusions must not hide malformed request input.

When multiple request sources provide different values for one key, the default policy throws `RequestDataConflictException`. `RequestDataConflictPolicy::FirstWins` must be selected explicitly when source precedence is part of the endpoint contract.

## Callable invocation

`call()` accepts closures, global functions, `"Class::method"`, callable service ids, `[object, 'method']` and `[class/interface/service-id, 'method']` references. Explicit parameters win over resolver output by name or position.

Failures while resolving/normalizing a callable use DI exceptions. Once target invocation begins, PHP engine errors and throwables raised by the target callable propagate unchanged so the original error type and stack trace are preserved.

## Lazy objects, proxies and invokables

`makeLazy()` creates a native lazy ghost whose initializer mutates the uninitialized instance. `makeProxy()` creates a native virtual proxy whose factory returns the real backing object.

Factory-bound services are eager unless their factory implements `LazyServiceFactoryInterface`. Class-level `#[Lazy]` and `#[Proxy]` participate in reflection autowiring and cannot be combined on the same class. Explicit invokable entries intentionally use a plain zero-argument `new` path and do not run the attribute lifecycle or consume `make()` context.

For an interface-typed or opaque service-id injection point, `#[Proxy(ConcreteClass::class)]` must provide a concrete proxy class.

## Production compiled factories

Known autowiring roots can be compiled into ordinary `ConfigKey::FACTORIES` entries:

```php
use Componenta\DI\Compile\Autowire\AutowireEntry;

$compiled = $builder->compileFactories(
    entries: [new AutowireEntry(CreateOrder::class)],
    directory: __DIR__ . '/var/cache/build',
);
```

The compiler follows statically knowable concrete constructor, `#[Inject]` and `#[SetUp]` dependencies. Existing services, explicit factories and invokables retain ownership and are never replaced by this autowiring compiler. Declarative `ClassDefinition` factories are handled separately by the definition compiler when persistent cache is generated; they become ordinary closure factories and therefore do not enter the autowiring compilation graph.

Each `CompiledFactoryDefinition` stores a relative shard file, generated class and method. Shards use content-addressed names and are loaded on first use. Untrusted relative paths are resolved inside the configured cache base directory; traversal and out-of-root symlinks are rejected. Dynamic classes continue through reflection autowiring.

Before loading an untrusted shard, the runtime verifies that its bytes match the digest encoded in its filename. Every generated shard also embeds the parameter-resolver/attribute-handler pipeline fingerprint. Generated parameter code enforces mapped-source provenance before resolver fragments, and the fingerprint format changes when this compiler invariant changes; a runtime mismatch is rejected and requires recompilation. Deploy generated artifacts in a directory that is immutable to the request process: integrity checks complement, but do not replace, filesystem permissions.

Application-level root discovery normally belongs to `componenta/app`; this package only compiles the roots it is given.

`DiCacheGeneratorInterface::generate()` normalizes the supplied dependency configuration, runs declarative definition compilation and atomically writes the resulting PHP cache. It does not discover application classes or run `compileFactories()` for autowiring roots.

Persistent-cache export preserves repeated identity for supported readonly objects and closures, including closures nested in arrays. When an existing cache file was present in OPcache, replacement must also invalidate that cached script or generation fails explicitly.

## Exceptions

Package exceptions implement `Componenta\DI\Exception\ExceptionInterface`. Main exceptions are `NotFoundException`, `CircularDependencyException`, `ConcurrentResolutionException`, `ResolutionException`, `InvalidConfigurationException`, `InvalidCallableException`, `DelegatorException`, `RequestDataConflictException` and `RequestParameterSourceConflictException`.
