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

`get()` performs shared PSR-11 resolution. `make()` creates a fresh local object and accepts ordinary `array $params`. `call()` is DI-aware and resolves callable parameters through the same parameter pipeline used by constructors.

There is no public resolution-context object. Framework state is passed through the ordinary parameter array:

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

`ParametersResolver` owns one ordered resolver chain. Higher numeric priorities run first. Equal priorities preserve insertion order.

The default v5 chain is deliberately small:

```text
provided by name/position     1100
provided by declared type     1000
parameter attributes           900
implicit request context       800
autowire by class/interface    300
PHP default                    200
nullable                       100
```

The `parameter attributes` slot is one `AttributeParameterResolver`. `#[Cast]`, `#[Config]`, `#[Env]`, `#[EntryId]`, `#[CurrentUser]`, `#[Make]`, `#[Proxy]` and all request-source attributes do **not** register independent parameter resolvers.

`#[Cast]` and `#[CurrentUser]` preserve their v4 precedence semantics: generic caller-value resolvers deliberately leave those parameters to the composed attribute handler. Ordinary caller values may still override lower-level sources such as `#[Config]`, `#[Env]` or `#[Make]`.

### Custom convention resolver

Use a custom `ParameterResolverInterface` when the rule does not require an attribute:

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

A resolver specification may be an instance, a container service id, a callable factory, or `[serviceId, method]`. `replaceParameterResolvers()` disables the built-in chain. User priorities must be unique; dependency configuration applied to a preconfigured builder replaces an existing user resolver at the same priority, matching the v4 contract.

## Attribute architecture

Attribute composition and execution are separate responsibilities.

`AttributeDefinition` binds an attribute class to semantic metadata and, when applicable, a handler:

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

`AttributePlanBuilder`:

- instantiates registered attributes;
- validates target masks;
- validates capability cardinality;
- evaluates `requires` / `forbids`;
- evaluates custom composition rules;
- orders attributes through `before` / `after` constraints;
- memoizes the resulting immutable `AttributePlan`.

Capability policies honor inheritance, so a policy registered for a parent capability applies to its sub-capabilities.

### Class, property and method attributes

Object attributes execute through one generic contract:

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

`AttributeProcessor` executes the validated plans for classes, properties and methods. There is no property-resolver subsystem.

### Parameter attributes

Parameters still resolve only through `ParameterResolverInterface`. The bridge from the common attribute model into that pipeline is exactly one built-in `AttributeParameterResolver`.

A handler that can provide a parameter value implements:

```php
interface ParameterAttributeHandlerInterface extends AttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed;
}
```

`AttributeParameterResolver` reads the already validated `AttributePlan`, selects its parameter-aware handler and delegates value production to that handler. Thus there is no second parameter-resolution pipeline.

A custom parameter attribute does not need its own resolver:

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentTenant {}

final readonly class CurrentTenantHandler
    implements ParameterAttributeHandlerInterface
{
    public function __construct(private Tenant $tenant) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed {
        return $this->tenant;
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        throw new LogicException('CurrentTenant is parameter-only.');
    }
}

$builder->addAttributeDefinition(new AttributeDefinition(
    CurrentTenant::class,
    new CurrentTenantHandler($tenant),
    capabilities: [ValueProvider::class],
));
```

A package may still register a custom `ParameterResolverInterface` when it intentionally implements convention-based or non-attribute parameter resolution.

## Built-in attribute handlers

The built-in value semantics are implemented as attribute handlers rather than independent parameter resolvers:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler          // Make + Proxy
RequestAttributeHandler
```

The same handler object can support parameter and property targets. `MakeHandler` also handles class-level `#[Proxy]`, allowing `#[Make] + #[Proxy]` to remain one semantic value handler.

The v4 public attribute surface and named constructor arguments are preserved.

### Configuration and environment

`#[Config]` reads `Componenta\Config\Config`. String keys are literal; `ConfigPath` keeps nested-path semantics.

`#[Env]` preserves target-type conversion for `string`, `int`, `float`, `bool` and `array`, and preserves its attribute default when `Config` has no `Environment` or the variable is missing.

### Casting

`#[Cast]` preserves the v4 `name, default` constructor contract. It reads caller input by parameter/property name or position, falls back to its own/PHP default where applicable, then uses `CasterProviderInterface`.

Request-source attributes perform transport casting through their own `cast:` argument:

```php
public function __construct(
    #[Header('X-Count', cast: 'int')]
    public int $count,
) {}
```

### Object injection and lifecycle

`#[Inject]` and `#[Init]` are property handlers. `#[Init]` may overwrite a mutable promoted property after construction, but does not overwrite an already initialized readonly promoted property.

`#[NoConstructor]` disables constructor invocation. `#[Lazy]` selects native lazy-object creation. `#[Proxy]` selects a virtual proxy and remains supported on classes, parameters and properties.

For interface-typed proxy injection, specify a concrete proxy class when it cannot be inferred:

```php
public function __construct(
    #[Proxy(RedisCache::class)]
    public CacheInterface $cache,
) {}
```

`#[Make('service.id'), Proxy(ConcreteService::class)]` keeps the service id and proxy class independent.

`#[SetUp]` remains repeatable and runs after creation. Setup methods are invoked through DI-aware `call()`, so their method parameters use the same `ParametersResolver` chain. `Config`, `Env`, `EntryId` and `ContainerValue` descriptors in `SetUp::params` preserve their v4 behavior.

## Object pipeline

Reflection and compiled entries share one execution runtime:

```text
ObjectPipeline
    -> build/validate AttributePlan metadata
    -> before-instantiation AttributeProcessor
    -> constructor arguments through ParametersResolver
    -> instantiate / lazy / proxy
    -> after-instantiation AttributeProcessor
    -> object
```

AOT never substitutes a generated implementation of a parameter resolver or an attribute handler.

## PSR-7 request resolution

HTTP behavior is isolated from generic DI semantics.

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

Request-source parameter attributes are all handled by one `RequestAttributeHandler`:

```text
#[QueryParam]
#[PayloadParam]
#[Header]
#[Cookie]
#[RequestAttribute]
#[ServerParam]
#[UploadedFile]
#[MapQueryString]
#[MapRequestPayload]
#[MapHeaders]
#[MapCookies]
#[MapRequestAttributes]
#[MapServerParams]
#[MapUploadedFiles]
#[MapRequest]
```

The specialized v4 mapping attributes and generic v5 `#[MapRequest]` share the same mapping semantics:

```text
extract sources
-> validate raw transport data
-> map
-> cast
-> defaults
-> sortMap
-> exclude
-> construct DTO
```

Example multi-source mapping:

```php
public function __construct(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Query],
        map: ['user_id' => 'userId'],
    )]
    public CreateOrder $command,
) {}
```

Conflicting values from multiple sources raise `RequestDataConflictException` by default. `RequestDataConflictPolicy::FirstWins` is available when ordered precedence is intentional.

Nested DTO mapping carries provenance through an internal request-only marker. `MappedRequestParameterSourceGuard` executes before resolver priority and before lazy/proxy creation, preventing mapped input from shadowing source-bound parameters such as `#[Header]`, `ServerRequestInterface` or `UriInterface` even through aliases, `ClassDefinition`, lazy objects and compiled entries.

The internal marker is stripped from ordinary object/property parameters and never becomes a public DI context.

`RequestContextResolver` is intentionally separate from the attribute handler: it only supplies implicit non-attribute request context such as `UriInterface`.

If `ValidationProviderInterface` is registered, class-typed request mapping performs validation before transformations, preserving the v4 request contract.

## Factories and definitions

User factories use the array-based runtime ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn(ContainerValue $container, array $params): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

Factories may accept fewer compatible arguments. `FactorySpecificationValidator` rejects incompatible required signatures before first resolution.

`ClassDefinition` remains immutable declarative data. Runtime overrides are normalized against the constructor signature by name, position and declared object type before entering the same parameter resolver chain. Persistent-cache `ClassDefinition` objects use the same runtime path.

`Container::set($id, $definition)` changes the supporting resolver definition for an already built container. `Container::set($id, $value)` replaces the local shared base value.

## ContainerBuilder

Main builder extensions:

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

Dependency keys:

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

Unknown dependency keys fail validation. Integer `parameter_resolvers` keys are priorities and are preserved rather than reindexed.

The package exposes `Componenta\DI\ConfigProvider` through `extra.componenta.config-providers` for Componenta composer-plugin/app discovery. Built-in v5 resolvers and attribute definitions are assembled by `ContainerBuilder`, so the package provider intentionally does not duplicate runtime registrations.

## Shared resolution, aliases and delegators

`get()` preserves shared container semantics: external PSR-11 ownership, decorated cache, aliases, local base entries, resolver lookup and delegators.

`make()` performs fresh local resolution and skips shared entry caches, external containers and delegators. Fresh-resolution cycles are still detected, including cycles introduced through `#[Make]`.

Alias changes, replacement of deferred delegator services and changes in external-container ownership invalidate affected decorated entries. Concurrent shared resolution in a different Fiber raises `ConcurrentResolutionException` rather than being misreported as an ordinary dependency cycle.

## AOT compiled entries

`compileFactories()` emits content-addressed entry shards:

```php
$compiled = $builder->compileFactories(
    entries: [CreateOrder::class],
    directory: __DIR__ . '/var/cache/di',
);
```

Generated methods are deliberately thin:

```text
reflection entry -> ObjectPipeline
compiled shard   -> ObjectPipeline
```

Both modes invoke the same `ParameterResolverInterface` objects, the same `AttributeParameterResolver` and the same attribute handlers. Custom convention resolvers and custom parameter/object attribute handlers therefore require no production-specific code generator.

The semantic fingerprint includes:

- attribute-plan format;
- attribute definitions and their versions;
- handler classes and phases;
- capabilities, dependency constraints and custom rule versions;
- capability policies;
- the actual ordered parameter-resolver chain.

A stale semantic fingerprint rejects the shard. Content-addressed shard files are also checked against their content hash.

`AutowireClassGraph` excludes ineligible roots and DI bootstrap extensions such as parameter resolvers and attribute handlers.

## Persistent cache

Persistent cache uses a strict versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

V5 currently uses cache format version `15`. Older envelopes are rejected.

## Exceptions

All package exceptions implement `Componenta\DI\Exception\ExceptionInterface`. Important failures include:

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

CI runs Composer validation, PHP-CS-Fixer checks, PHPStan at max level and Pest on PHP 8.4 and 8.5.

The v5 parity suite covers:

- public v4 named-argument signatures;
- custom convention parameter resolvers;
- custom parameter attributes through `ParameterAttributeHandlerInterface`;
- custom class/property/method handlers;
- reflection vs AOT execution;
- semantic fingerprint invalidation;
- persistent cache and `ClassDefinition` override normalization;
- request provenance through aliases, lazy objects and cache;
- proxy/make behavior;
- promoted/private/static property behavior;
- Fiber ownership;
- alias/delegator/external-container invalidation.
