# Componenta DI

PSR-11 dependency injection container for PHP 8.4+ with semantic attribute composition, autowiring, explicit factories, PSR-7 request mapping, native lazy objects and AOT entry shards.

**[English](README.md)** | **[Русский](README.ru.md)**

## Installation

```bash
composer require componenta/di
```

PHP 8.4 or newer is required.

## Core API

```php
use Componenta\DI\ContainerBuilder;
use Componenta\DI\ResolutionContext;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$shared = $container->get('logger');
$fresh = $container->make(
    UserService::class,
    ResolutionContext::explicit(['userId' => 7]),
);
```

`get()` performs shared PSR-11 resolution. `make()` performs fresh local resolution and accepts an explicit `ResolutionContext`; it does not use shared entry caches, external containers or delegators.

DI-aware callable execution is explicit:

```php
$result = $container->execute(
    [$service, 'handle'],
    ResolutionContext::explicit(['id' => 42]),
);
```

`CallableInvokerInterface::call()` remains the low-level direct-array callable API. `CallableExecutorInterface::execute()` is the DI-aware API.

## ResolutionContext

V5 no longer transports framework metadata through magic array keys. Resolution input has three independent channels:

```php
new ResolutionContext(
    explicit: ['id' => 42],
    mapped: ['title' => 'Hello'],
    trusted: [ServerRequestInterface::class => $request],
);
```

- `explicit` — trusted caller-provided overrides.
- `mapped` — values originating from request DTO mapping.
- `trusted` — framework-owned context such as the current PSR-7 request.

Helpers are available for the common cases:

```php
ResolutionContext::explicit(['id' => 42]);
ResolutionContext::mapped($payload, $request);
```

Mapped values never override a target with a declared value provider. A provider may also reject explicit overrides when its semantics are authoritative, as `#[CurrentUser]` does.

## Attribute model

Attributes are passive immutable declarations. Runtime behavior lives in registered `AttributeDefinition` handlers. Composition is planned and validated before any attribute behavior executes.

The built-in semantic capabilities are open contracts rather than a closed attribute-kind enum:

| Capability | Built-in cardinality | Meaning |
|---|---:|---|
| `ValueProvider` | 0..1 | Produces the raw value of a parameter/property. |
| `ValueTransformer` | 0..N | Transforms an already resolved value. |
| `CreationStrategy` | 0..1 | Selects eager/lazy/proxy object creation. |
| `ConstructorPolicy` | 0..1 | Controls constructor invocation. |
| `LifecycleHook` | 0..N | Runs ordered post-population object lifecycle work. |

Third-party packages may define their own capabilities and cardinality rules without changing DI core.

### Provider exclusivity

All value-producing attributes occupy the same `ValueProvider` slot. Therefore combinations such as these are invalid:

```php
#[Header('X-Token'), Config('token')]
string $token;

#[Inject, Init([Factory::class, 'create'])]
private Service $service;
```

The conflict is rejected by the composition engine, not resolved accidentally by handler priority.

Built-in value providers include:

- `#[Config]`
- `#[Env]`
- `#[EntryId]`
- `#[Inject]`
- `#[Init]`
- `#[Make]`
- `#[CurrentUser]`
- `#[Header]`, `#[Cookie]`, `#[QueryParam]`, `#[PayloadParam]`, `#[RequestAttribute]`, `#[ServerParam]`, `#[UploadedFile]`
- `#[MapRequest]`

### Value transformers

`#[Cast]` is repeatable and never competes with value providers:

```php
public function __construct(
    #[Header('X-Count')]
    #[Cast('trim')]
    #[Cast('int')]
    public int $count,
) {}
```

The value pipeline is:

```text
provider/fallback -> transformer #1 -> transformer #2 -> ... -> final type check
```

Provider results are intentionally not validated against the final PHP type before transforms run. A header may therefore return `"42"`, then `#[Cast('int')]` produces `42`, and only the final result is checked against `int`.

`#[Env]` also returns the raw environment value. Conversion is explicit through `#[Cast]`; there is no hidden type conversion based on the target type.

### Parameters and properties share one pipeline

Constructor/callable parameters and non-promoted properties use the same semantic `ValuePipeline`. A property with only transformers may transform its initialized value:

```php
final class Options
{
    #[Cast('trim')]
    public string $mode = '  safe  ';
}
```

Promoted properties are constructor-owned. Their DI value attributes execute through the constructor parameter pipeline exactly once; post-construction property population skips promoted properties.

## Object lifecycle

Object creation is planned semantically rather than by integer handler priorities:

```text
build/validate class AttributePlan
        -> constructor policy
        -> creation strategy
        -> instantiate
        -> populate non-promoted value properties
        -> lifecycle hooks
```

`#[Lazy]` and class-level `#[Proxy]` both occupy `CreationStrategy`, so applying both to one class is invalid.

`#[NoConstructor]` occupies `ConstructorPolicy`.

`#[SetUp]` is a repeatable `LifecycleHook` and runs in declaration order after property value population:

```php
#[SetUp('configure')]
#[SetUp('boot')]
final class Service {}
```

## PSR-7 request values

Scalar request providers remain explicit value providers:

```php
public function __construct(
    #[QueryParam('page')]
    #[Cast('int')]
    public int $page,
) {}
```

For DTO mapping V5 uses one `#[MapRequest]` attribute and an explicit source list:

```php
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;

public function __construct(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Attributes],
        map: ['user_id' => 'userId'],
        exclude: ['internal'],
    )]
    public CreateOrder $command,
) {}
```

Available request sources are `Payload`, `Query`, `Headers`, `Cookies`, `Attributes`, `Server` and `Files`.

Different values for the same key from multiple sources raise `RequestDataConflictException` by default. `RequestDataConflictPolicy::FirstWins` must be selected explicitly when ordered source precedence is intentional.

Class-typed mapping accepts only named string keys. The mapped DTO is constructed through `FactoryInterface::make()` with `ResolutionContext::mapped()`, so nested provider trust boundaries are enforced by the same ordinary value pipeline. There is no separate request-provenance resolver path.

If a `ValidationProviderInterface` service is configured, `#[MapRequest]` validates the final mapped/excluded data before constructing a class-typed DTO.

## Extending attribute semantics

Register a new passive attribute by associating it with a handler and capabilities:

```php
$builder->addAttributeDefinition(new AttributeDefinition(
    attribute: CurrentTenant::class,
    handler: new CurrentTenantProvider($tenantContext),
    capabilities: [ValueProvider::class],
));
```

Because `ValueProvider` already has `maxPerTarget: 1`, `#[CurrentTenant]` automatically conflicts with every other value provider without adding pairwise rules.

Definitions may additionally declare `requires`, `forbids`, `before` and `after` selectors. Selectors can reference another attribute class or a capability. Ordering constraints are compiled into a stable DAG; cycles fail composition explicitly. Declaration order is the stable tie-breaker.

Custom capabilities are open-ended:

```php
$builder->defineAttributeCapability(
    new CapabilityPolicy(TransactionBoundary::class, maxPerTarget: 1),
);
```

## Value fallbacks

When a target has no `ValueProvider`, V5 evaluates the ordered fallback registry. Built-in order is:

```text
explicit
-> mapped
-> trusted
-> initialized property value
-> autowire
-> PHP parameter default
-> nullable
```

Fallbacks are registered with named `before`/`after` relations, not numeric priorities:

```php
$builder->addValueFallback(new ValueFallbackDefinition(
    id: 'tenant-default',
    fallback: new TenantFallback(),
    after: ['trusted'],
    before: ['property_initial'],
));
```

Unknown ordering references and ordering cycles fail while the container is composed.

## Factories and definitions

Normal user factories use the V5 ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn (
        ContainerValue $container,
        ResolutionContext $context,
    ): MailerInterface => new SmtpMailer(
        $container->get(SmtpConfig::class),
    ),
);
```

`ClassDefinition` remains immutable declarative data. It is not compiled into a special constructor closure. Runtime and persistent-cache `ClassDefinition` resolution both route through the same `ObjectPipeline`, so attributed constructor/property semantics cannot diverge from normal reflection resolution.

`Container::set($id, $definition)` changes the supporting resolver definition for the already built container. `Container::set($id, $value)` replaces the local shared base value.

## ContainerBuilder extensions

The main assembly methods are:

- `addFactory()` / `addFactories()`
- `addDefinition()`
- `addInvokable()` / `addInvokables()`
- `addAlias()` / `addAliases()`
- `addDelegator()` / `addDelegators()`
- `addService()` / `addServices()`
- `addAttributeDefinition()`
- `defineAttributeCapability()`
- `addValueFallback()`
- `compileFactories()`
- `toArray()`
- `build()`

V4 parameter-resolver priorities, attribute-handler registries and replacement flags do not exist in V5.

The declarative dependency keys owned by V5 are:

- `ConfigKey::FACTORIES`
- `ConfigKey::INVOKABLES`
- `ConfigKey::ALIASES`
- `ConfigKey::DELEGATORS`
- `ConfigKey::SERVICES`
- `ConfigKey::ATTRIBUTE_DEFINITIONS`
- `ConfigKey::ATTRIBUTE_CAPABILITIES`
- `ConfigKey::VALUE_FALLBACKS`

Unknown dependency keys fail configuration.

## Shared resolution, aliases and delegators

`get()` preserves normal container semantics: external PSR-11 ownership is checked first, then local decorated cache, alias canonicalization, local base cache, entry resolution and delegators.

`make()` resolves local aliases but deliberately skips shared caches, external containers and delegators. Fresh-resolution cycles are still detected.

## AOT compiled entries

`compileFactories()` accepts known autowiring roots and emits content-addressed entry shards:

```php
$compiled = $builder->compileFactories(
    entries: [CreateOrder::class],
    directory: __DIR__ . '/var/cache/di',
);
```

V5 generated methods are intentionally thin. They do not reproduce provider, transformer or lifecycle logic. Every generated entry delegates semantic work to the same runtime `ObjectPipeline` used by reflection resolution.

This is the production-parity invariant:

```text
reflection -> ObjectPipeline
AOT shard   -> ObjectPipeline
```

A shard embeds a semantic fingerprint derived from registered attribute definitions, capability policies and ordered value fallbacks. A runtime mismatch rejects the shard and requires recompilation.

Content-addressed shard files are hash-verified before use. Deploy generated artifacts in a directory that request processes cannot modify.

## Persistent cache

Persistent cache uses a strict versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

V5 currently uses cache format version `12`. Older cache envelopes are rejected.

`DiCacheGenerator` exports supported immutable definition/configuration objects. `ClassDefinition` remains data in the cache and is interpreted by the runtime `FactoryResolver` through `ObjectPipeline` rather than through generated special-case code.

## Main exceptions

All package exceptions implement `Componenta\DI\Exception\ExceptionInterface`. Important V5 failures include:

- `AttributeCompositionException` — invalid attribute cardinality/dependency/order composition.
- `ValueProviderConflictException` — mapped or forbidden explicit input attempts to occupy a provider-owned target.
- `RequestDataConflictException` — request sources supply different values for one mapped key.
- `ResolutionException` — target/object resolution failure.
- `InvalidConfigurationException` — invalid container, extension, fallback or cache configuration.
