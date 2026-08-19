# Componenta DI

PSR-11 dependency injection container for PHP 8.4+ with extensible parameter resolution, composable attributes, PSR-7 request mapping, native lazy objects and AOT entry shards.

**[English](README.md)** | **[Русский](README.ru.md)**

## Installation

```bash
composer require componenta/di
```

PHP 8.4 or newer is required.

## Core API

```php
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$shared = $container->get('logger');
$fresh = $container->make(UserService::class, ['userId' => 7]);
$result = $container->call([$service, 'handle'], ['id' => 42]);
```

`get()` performs shared PSR-11 resolution. `make()` creates a fresh local object and accepts ordinary `array $params`. `call()` is DI-aware and resolves callable parameters through the same parameter-resolver pipeline used by constructors.

There is no public resolution-context object. Framework-specific state such as a PSR-7 request is passed like any other typed parameter:

```php
$controller = $container->make(Controller::class, [
    ServerRequestInterface::class => $request,
]);
```

## Parameter resolution

Every constructor and callable parameter is resolved exclusively through `ParameterResolverInterface`:

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;

    /** @return array{0:int,1:mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

`ParametersResolver` owns an ordered resolver chain. Higher numeric priorities run first; equal priorities preserve registration order. The built-in v5 order preserves the v4 behavior:

```text
Cast              1200
provided by name  1100
provided by type  1000
CurrentUser        900
request            800
Make               700
Env                600
EntryId            500
Config              400
autowire            300
PHP default         200
nullable            100
```

Some resolvers intentionally decline values that belong to a stronger declared source. For example, caller input cannot replace `#[CurrentUser]`, while an ordinary named value may override `#[Config]` before the Config resolver runs.

### Custom parameter resolvers

```php
final readonly class TenantParameterResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'tenant';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, $this->tenant];
    }
}

$builder->addParameterResolver(new TenantParameterResolver($tenant), 750);
```

A resolver specification may be an instance, a container service id, a callable factory, or `[serviceId, method]`. `replaceParameterResolvers()` disables the built-in chain. User-defined priorities must be unique; configuration applied to a preconfigured builder replaces an existing user resolver at the same priority, matching v4 semantics.

## Attribute model

Attribute composition and attribute execution are separate responsibilities.

`AttributeDefinition` describes semantic composition:

```php
new AttributeDefinition(
    attribute: Transactional::class,
    handler: new TransactionalHandler(),
    capabilities: [TransactionBoundary::class],
    requires: [],
    forbids: [],
    before: [],
    after: [],
    rules: [],
    version: 1,
);
```

`AttributePlanBuilder` validates cardinality, dependencies, custom rules and `before`/`after` ordering. Capabilities support inheritance: a cardinality rule defined for a parent capability also covers its sub-capabilities.

Class, property and method attributes are executed through one generic contract:

```php
interface AttributeHandlerInterface
{
    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void;
}
```

There is no property-resolver subsystem. Parameter attributes are present in the same composition model, but they never bypass `ParameterResolverInterface`: a parameter-only `AttributeDefinition` may have `handler: null`, while the matching parameter resolver supplies its value.

### Custom parameter attribute

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentTenant {}

$builder
    ->addAttributeDefinition(new AttributeDefinition(
        CurrentTenant::class,
        handler: null,
        capabilities: [ValueProvider::class],
    ))
    ->addParameterResolver(new CurrentTenantResolver($tenant), 750);
```

### Custom class/property/method attribute

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class InjectClock {}

$builder->addAttributeDefinition(new AttributeDefinition(
    InjectClock::class,
    new InjectClockHandler($clock),
    capabilities: [ValueProvider::class],
));
```

## Built-in value attributes

The v4 public attribute surface is preserved, including named constructor arguments.

`#[Config]` reads Componenta configuration. `#[Env]` preserves v4 target-type conversion for `string`, `int`, `float`, `bool` and `array`, and uses its attribute default even when `Config` has no `Environment`. `#[EntryId]`, `#[Make]`, `#[CurrentUser]`, request attributes and ordinary autowiring are resolved by parameter resolvers.

`#[Cast]` preserves its v4 `name, default` constructor and resolver semantics. It owns the target's value-resolution slot, reads caller input or its own/default PHP value and applies the selected caster. Request attributes use their own `cast:` argument when request extraction itself needs casting:

```php
public function __construct(
    #[Header('X-Count', cast: 'int')]
    public int $count,
) {}
```

`#[Inject]` and `#[Init]` are property handlers. `#[Init]` may overwrite a mutable promoted property after construction, but does not overwrite an already initialized readonly promoted property.

## Object lifecycle

Object creation uses one runtime pipeline for reflection and compiled entries:

```text
build/validate AttributePlan
        -> before-instantiation attribute handlers
        -> constructor parameters via ParameterResolverInterface[]
        -> instantiate / lazy / proxy
        -> after-instantiation class/property/method handlers
        -> return object
```

`#[NoConstructor]` disables constructor invocation. `#[Lazy]` selects native lazy-object creation. `#[Proxy]` selects a virtual proxy and is supported on classes, parameters and properties as in v4.

For interface-typed proxy injection, specify the concrete proxy class when it cannot be inferred:

```php
public function __construct(
    #[Proxy(RedisCache::class)]
    public CacheInterface $cache,
) {}
```

`#[Make('service.id'), Proxy(ConcreteService::class)]` keeps the service id and proxy class independent.

`#[SetUp]` remains repeatable and runs after object creation. Its method is invoked through the same DI-aware `call()` path, so setup-method parameters use the normal parameter-resolver chain. `Config`, `Env`, `EntryId` and `ContainerValue` descriptors inside `SetUp::params` retain their v4 behavior.

## PSR-7 request resolution

HTTP behavior is isolated under `Resolver/Parameter/Request`; generic DI core does not know about `ServerRequestInterface`.

A request is supplied as an ordinary typed parameter:

```php
$result = $container->call(
    static fn(
        #[Header('X-Token')] string $token,
        UriInterface $uri,
        ServerRequestInterface $request,
    ) => [$token, $uri, $request],
    [ServerRequestInterface::class => $request],
);
```

The scalar request attributes are:

```text
#[QueryParam]
#[PayloadParam]
#[Header]
#[Cookie]
#[RequestAttribute]
#[ServerParam]
#[UploadedFile]
```

The specialized v4 mapper attributes are also preserved:

```text
#[MapQueryString]
#[MapRequestPayload]
#[MapHeaders]
#[MapCookies]
#[MapRequestAttributes]
#[MapServerParams]
#[MapUploadedFiles]
```

They share the same `RequestMapper` transformation pipeline:

```text
map -> cast -> defaults -> sortMap -> exclude
```

V5 additionally provides generic `#[MapRequest]` for explicit multi-source mapping:

```php
public function __construct(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Query],
        map: ['user_id' => 'userId'],
    )]
    public CreateOrder $command,
) {}
```

Different values for one key from multiple sources raise `RequestDataConflictException` by default. `RequestDataConflictPolicy::FirstWins` is available when ordered precedence is intentional.

Nested DTO construction carries request-mapping provenance through an internal request-only marker. That marker is stripped from normal resolver input. Before any resolver priority is evaluated, `MappedRequestParameterSourceGuard` rejects mapped keys that would shadow an explicitly declared parameter source such as `#[Header]`, a request object or URI. A high-priority custom resolver therefore cannot bypass the guard.

If a `ValidationProviderInterface` service is available, class-typed request mapping retains request-validation support.

## Factories and definitions

User factories use the array-based runtime ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn(ContainerValue $container, array $params): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

Factories may accept fewer compatible arguments; `FactorySpecificationValidator` rejects incompatible required signatures before first resolution.

`ClassDefinition` remains immutable declarative data. Runtime and persistent-cache resolution route through the same `ObjectPipeline`; attributed constructor/property semantics are not reimplemented in a generated special case.

`Container::set($id, $definition)` changes the supporting resolver definition for an already built container. `Container::set($id, $value)` replaces the local shared base value.

## ContainerBuilder

Main builder extensions include:

```text
addFactory() / addFactories()
addDefinition()
addInvokable() / addInvokables()
addAlias() / addAliases()
addDelegator() / addDelegators()
addService() / addServices()
addParameterResolver()
replaceParameterResolvers()
addAttributeDefinition()
replaceAttributeDefinitions()
defineAttributeCapability()
compileFactories()
toArray()
build()
```

The v5 dependency keys are:

```text
factories
invokables
aliases
delegators
services
parameter_resolvers
parameter_resolvers_replace
attribute_definitions
attribute_definitions_replace
attribute_capabilities
```

Unknown dependency keys fail configuration. Integer `parameter_resolvers` keys are priorities and are preserved rather than reindexed.

The package still exposes `Componenta\DI\ConfigProvider` through `extra.componenta.config-providers` for Componenta composer-plugin/app discovery. Built-in v5 resolvers and attribute definitions are assembled by `ContainerBuilder`, so the package provider intentionally does not duplicate those runtime registrations.

## Shared resolution, aliases and delegators

`get()` preserves shared container semantics: external PSR-11 ownership, decorated cache, aliases, local base entries, resolver lookup and delegators.

`make()` performs fresh local resolution and skips shared entry caches, external containers and delegators. Fresh-resolution cycles are still detected, including cycles introduced through `#[Make]`.

Alias changes, replacement of deferred delegator services and changes in external-container ownership invalidate affected decorated entries. Concurrent shared resolution in a different Fiber raises `ConcurrentResolutionException` rather than being misreported as an ordinary dependency cycle.

## AOT compiled entries

`compileFactories()` accepts known autowiring roots and emits content-addressed entry shards:

```php
$compiled = $builder->compileFactories(
    entries: [CreateOrder::class],
    directory: __DIR__ . '/var/cache/di',
);
```

Generated factory methods are intentionally thin:

```text
reflection resolution -> ObjectPipeline
compiled AOT shard    -> ObjectPipeline
```

A custom `ParameterResolverInterface` or `AttributeHandlerInterface` therefore does not need a second production code generator. Build-time metadata may remove reflection/classification work, but runtime semantics are shared.

The semantic fingerprint includes the attribute-plan format, registered attribute definitions/capability policies and the actual ordered parameter-resolver chain. A mismatch rejects a stale shard. Content-addressed shard files are integrity checked before use.

`AutowireClassGraph` retains v4 eligibility behavior and excludes DI bootstrap extensions such as parameter resolvers and attribute handlers from compiled entry roots.

## Persistent cache

Persistent cache uses a strict versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

V5 currently uses cache format version `14`. Older envelopes are rejected.

`DiCacheGenerator` exports supported immutable definitions/configuration. Cached `ClassDefinition` objects are interpreted through the same runtime resolver and parameter pipeline as normal reflection resolution.

## Exceptions

All package exceptions implement `Componenta\DI\Exception\ExceptionInterface`. The main failures are:

```text
AttributeCompositionException
CircularDependencyException
ConcurrentResolutionException
InvalidConfigurationException
NotFoundException
RequestDataConflictException
RequestParameterSourceConflictException
ResolutionException
```

## Development and production parity

The package's CI runs `composer validate`, PHPStan at max level, coding-style checks and Pest on PHP 8.4 and 8.5. The v5 parity suite covers public named-argument signatures, custom resolver/attribute extensions, reflection vs AOT execution, persistent cache, request provenance, proxy/make behavior, promoted/private property handling, Fiber ownership and alias/delegator invalidation.
