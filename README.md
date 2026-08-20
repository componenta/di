# Componenta DI

PSR-11 dependency injection container for PHP 8.4+ with autowiring, composable attributes, PSR-7 request mapping, native lazy objects and AOT factory shards.

**[English](README.md)** | **[Русский](README.ru.md)**

## Installation

```bash
composer require componenta/di
```

## Core API

```php
$container = (new Componenta\DI\ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->build();

$shared = $container->get(LoggerInterface::class);
$fresh = $container->make(UserService::class, ['userId' => 7]);
$result = $container->call([$service, 'handle'], ['id' => 42]);
```

`get()` is shared PSR-11 resolution. `make()` creates a fresh local object. `call()` is DI-aware. Public factory/callable boundaries use ordinary `array $params`; there is no public resolution-context object.

Framework values are passed through the same array:

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

The default chain is intentionally small:

```text
1200  AttributeParameterResolver
1100  ArrayResolver
1000  ArrayTypedResolver
 800  RequestContextResolver
 300  AutowireByTypeResolver
 200  DefaultValueResolver
 100  NullableResolver
```

`ArrayResolver` and `ArrayTypedResolver` are attribute-agnostic. Attribute precedence and composition belong entirely to `AttributeParameterResolver` and the attribute plan.

Resolver classification is prepared once for warmed constructor/named-callable paths. A prepared plan stores immutable parameter metadata and resolver slots only; request values, current users, resolved objects, default objects and resolver instances are never cached in the plan. `has()` / `canCreate()` do not trigger parameter-resolver `supports()` classification.

Use a custom `ParameterResolverInterface` for convention-based rules that do not need an attribute:

```php
final readonly class TenantResolver implements ParameterResolverInterface
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

$builder->addParameterResolver(new TenantResolver($tenant), 750);
```

Resolver specifications may be instances, container service ids, callable factories, or `[serviceId, method]`. `replaceParameterResolvers()` disables the built-in chain.

## Attribute composition

`AttributeDefinition` binds an attribute to its handler and semantic metadata:

```php
new AttributeDefinition(
    attribute: MyAttribute::class,
    handler: new MyHandler(),
    capabilities: [MyCapability::class],
    requires: [],
    forbids: [],
    before: [],
    after: [],
    rules: [],
    version: 1,
);
```

`AttributePlanBuilder` validates:

- target masks;
- capability cardinality;
- `requires` / `forbids` constraints;
- custom composition rules;
- `before` / `after` ordering;
- parameter source compatibility.

Capabilities support inheritance consistently in `AttributePlanBuilder`, `AttributeSet` and `AttributePlan`.

Composition plans cache semantic metadata, not mutable runtime handler state. Each handler invocation receives a fresh attribute instance, so attribute mutation cannot carry request-local state into a later resolution or Fiber.

### Multiple parameter attributes

A parameter may contain one value source plus transformers. For example:

```php
public function __construct(
    #[QueryParam('count'), Cast('int')]
    public int $count,
) {}
```

The plan orders the request source before `#[Cast]`, so a string query value becomes an `int`. Declaration order does not matter for this built-in composition:

```php
#[Cast('int'), QueryParam('count')]
```

is resolved with the same source → transformer order.

Two incompatible value sources are rejected during composition:

```php
#[QueryParam('value'), Header('X-Value')]
string $value
```

throws `AttributeCompositionException` before parameter resolution. The same validation is executed by AOT preparation before a shard is written.

`ValueProvider` is singular per target. `ValueTransformer` is a separate capability, so source + transformer is valid while source + source is not.

## Attribute execution contracts

Object attributes and parameter attributes intentionally use separate contracts.

Class/property/method handlers implement:

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

Parameter-only handlers implement the independent contract:

```php
interface ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue;
}
```

`ParameterAttributeHandlerInterface` does **not** extend `AttributeHandlerInterface`. A parameter-only handler therefore does not need a meaningless `handle()` method that throws. A handler supporting both parameter and property/class/method targets explicitly implements both interfaces.

`AttributeParameterResolver` is the only built-in bridge from parameter resolution to parameter attribute handlers. It:

1. reads the validated `AttributePlan`;
2. seeds ordinary caller input when allowed;
3. executes parameter handlers in composed order;
4. threads immutable `ParameterAttributeValue` state through source/transformer handlers;
5. returns the final value to the normal parameter resolver pipeline.

Authoritative sources such as `#[CurrentUser]` are expressed through the `AuthoritativeValueProvider` capability. `AttributeParameterResolver` does not hard-code attribute classes.

### Custom parameter attribute

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
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return $value->resolved
            ? $value
            : ParameterAttributeValue::resolved($this->tenant);
    }
}

$builder->addAttributeDefinition(new AttributeDefinition(
    CurrentTenant::class,
    new CurrentTenantHandler($tenant),
    capabilities: [ValueProvider::class],
));
```

No custom parameter resolver is needed for an attribute-based extension.

## Built-in attribute handlers

Built-in parameter/property semantics live in attribute handlers rather than per-attribute parameter resolvers:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler
RequestAttributeHandler
```

`ConfigHandler`, `EnvHandler`, `EntryIdHandler`, `CurrentUserHandler`, `MakeHandler` and `CastHandler` implement both execution contracts because those attributes also support properties. `RequestAttributeHandler` is parameter-only.

`#[Cast]` is a transformer. It can transform caller input or a value produced by another source handler. Mutable properties also support source → `Cast` composition.

`#[CurrentUser]` is authoritative and ignores caller-provided replacement values. The default `CurrentUserProvider` isolates explicitly assigned users by active Fiber; long-running integrations must still clear sequential request state in a `finally` boundary.

`#[Make]` and `#[Proxy]` share `MakeHandler`; `#[Make('service.id'), Proxy(ConcreteService::class)]` keeps service id and proxy class independent.

## Object attributes

`AttributeProcessor` executes class/property/method plans. There is no property-resolver subsystem.

Built-in lifecycle behavior includes:

- `#[Inject]` property injection;
- `#[Init]` property initialization;
- `#[NoConstructor]` constructor suppression;
- `#[Lazy]` native lazy-object strategy;
- `#[Proxy]` virtual proxy strategy;
- repeatable `#[SetUp]` lifecycle hooks.

Private inherited properties are included in processing. Static DI properties fail explicitly. Mutable promoted `#[Init]` properties may be updated after construction; initialized readonly promoted properties are preserved.

## PSR-7 request attributes

Request extraction is isolated from generic DI internals. Supply the request as a normal typed parameter:

```php
$params = [ServerRequestInterface::class => $request];
```

The request itself remains ordinary caller input. Internal mapped-request provenance is transported only through built-in resolution boundaries and is removed before custom parameter resolvers, parameter handlers, object handlers, factories and entry resolvers are invoked. Custom `ExtractorInterface` implementations receive the original PSR-7 request; fallback DI parameter names are exposed only through the opt-in `ParameterNameAwareExtractorInterface` contract.

All request-source attributes use one `RequestAttributeHandler`:

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

Request mapper transformations use one pipeline:

```text
extract
-> validate raw data
-> map
-> cast
-> defaults
-> sortMap
-> exclude
-> construct DTO
```

Nested request DTOs carry internal mapping provenance. `MappedRequestParameterSourceGuard` runs before resolver priority and before lazy/proxy creation, preventing mapped payload keys from spoofing explicitly declared sources. The same provenance is preserved internally for `#[SetUp]` parameter checks without exposing raw metadata through `ObjectCreationContext`.

`RequestContextResolver` is separate because implicit `UriInterface` resolution has no parameter attribute.

## Factories, definitions and cache

User factories use the array ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn(ContainerValue $container, array $params): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

`ClassDefinition` remains declarative and uses the same runtime parameter pipeline. Runtime overrides are projected against the constructor signature by name, position and declared object type.

Persistent cache uses a strict versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Current v5 cache format: **17**.

Cache artifacts are written to an exclusive temporary file, syntax-checked with the active PHP binary, and atomically renamed only after validation. A broken custom definition code generator therefore cannot replace an existing valid cache with syntactically invalid PHP.

## AOT compiled entries

`compileFactories()` emits content-addressed factory shards. Generated methods are intentionally thin:

```text
reflection entry -> ObjectPipeline
compiled shard   -> ObjectPipeline
```

Both modes execute the same `ParametersResolver`, prepared parameter plans, `AttributeParameterResolver` and handlers. Custom resolvers and handlers therefore require no production-specific code generator.

A shard contains only its ABI `FORMAT_VERSION`, a `method => entry class` `ENTRIES` map and thin methods delegating to `ObjectPipeline`. The loader validates the shard format and target entry eligibility and rejects stale/malformed entry metadata. Resolver and attribute semantics are **not** embedded into or frozen by a shard: a previously generated thin shard executes the current runtime pipeline, exactly like development mode.

## ContainerBuilder extensions

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

Integer `parameter_resolvers` keys are priorities and are preserved. Unknown dependency keys fail validation.

The package exports `Componenta\DI\ConfigProvider` through `extra.componenta.config-providers` for Componenta package discovery.

## Exception contract

`Componenta\DI\Exception\ExceptionInterface` is the single package-level boundary for failures owned or normalized by DI. It also extends PSR-11 `ContainerExceptionInterface`.

- `get()` and `make()` expose Componenta DI exceptions only; missing entries use `NotFoundException`, which also implements PSR-11 `NotFoundExceptionInterface`.
- parameter resolver, attribute handler, constructor, factory, delegator, external-container, validation and caster failures are normalized by their owning DI pipeline; foreign throwables are retained as `getPrevious()`.
- `call()` has two phases: callable/argument preparation is a DI boundary, while throwables raised after control enters the explicitly invoked user callable body propagate unchanged.
- `AttributeCompositionException` is a specialized `InvalidConfigurationException`.
- invalid builder/AOT inputs use `InvalidConfigurationException`; generated-artifact serialization, filesystem, lint and activation failures use `CompilationException`.
- validation/caster exception types are not part of the DI public exception ABI. Request mapping preserves them as the cause of `ResolutionException`.

Resolution diagnostics are detached from live request, service, callable and reflection objects. Retaining a `ResolutionException` for logging/telemetry therefore does not itself retain a completed request graph; the original foreign cause remains available through `getPrevious()`.

The exception contract is shared by reflection and compiled/AOT execution, including deferred built-in lazy initialization.

## CI and parity

CI runs Composer validation, PHP-CS-Fixer, PHPStan at max level and Pest on PHP 8.4 and 8.5. Runtime, AOT, build-phase and prepared-parameter benchmark harnesses are smoke-tested on both versions.

The v5 parity suite covers public v4 signatures, prepared parameter plans, custom convention resolvers, custom parameter/object handlers, multi-attribute parameter composition, source conflicts, reflection/AOT parity, request provenance isolation, persistent cache safety, proxy/make semantics, promoted/private/static properties, Fibers, aliases, delegators, external containers and the strict exception boundary.
