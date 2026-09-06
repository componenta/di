# Componenta DI

Componenta DI is a PSR-11 dependency injection container for PHP 8.4+. It provides reflection autowiring, explicit factories and services, ordered delegators, callable invocation, lazy objects, request mapping, and composable PHP attributes.

The package owns container construction and dependency-resolution semantics. [`componenta/config`](../config/README.md) owns provider execution and produces two separate values:

- `Config`, which contains application configuration only;
- `DependencyDefinitions`, which is consumed once by `ContainerFactory` and is not published as a container service.

There is one runtime construction path in every environment. Componenta DI does not compile factories or persist a serialized dependency graph.

## Installation

```bash
composer require componenta/di
```

The package requires PHP 8.4+, Componenta Config 3.x, Componenta Caster, Componenta Reflection, Componenta Validation, PSR-11 2.x, and PSR-7 message interfaces.

## Core flow

```text
Environment + ordered providers
    -> ConfigFactory::create()
    -> ConfigComposition { Config, DependencyDefinitions }
    -> ContainerFactory::create()
    -> ContainerValue { container, the same Config }
```

`ContainerFactory` is the only public container-construction API. It preserves the exact `Config` and `Environment` instances supplied by the application.

## Quick start

```php
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigProvider;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;

final readonly class Greeting
{
    public function __construct(public string $message) {}
}

final class AppConfigProvider extends ConfigProvider
{
    protected function getConfig(): array
    {
        return ['greeting.prefix' => 'Hello'];
    }

    protected function getFactories(): array
    {
        return [
            Greeting::class => static fn(
                ContainerValue $context,
                array $params,
            ): Greeting => new Greeting(
                ($params['prefix'] ?? $context->config->string('greeting.prefix')) . '!',
            ),
        ];
    }
}

$environment = new Environment([]);
$composition = (new ConfigFactory())->create(
    $environment,
    new AppConfigProvider(),
);
$containerValue = (new ContainerFactory())->create(
    $composition->config,
    $composition->dependencies,
);
$container = $containerValue->container;

$shared = $container->get(Greeting::class);
$fresh = $container->make(Greeting::class, ['prefix' => 'Welcome']);

assert($shared->message === 'Hello!');
assert($fresh->message === 'Welcome!');
assert($containerValue->config === $composition->config);
assert($container->get(Environment::class) === $environment);
```

## Provider composition

`ConfigFactory` calls providers in argument order and merges their dependency sections before DI sees them. The relevant rules are:

- factories, aliases, services, and parameter resolvers are keyed registries; a later value replaces an earlier value with the same key;
- numeric invokables are appended, while keyed invokables are replaced by key;
- delegator pipelines, attribute definitions, and attribute capabilities are appended in provider order;
- resolver and attribute-definition replacement flags use the last explicitly supplied Boolean value.

DI validates and normalizes the merged `DependencyDefinitions` once during `ContainerFactory::create()`. It does not invoke providers and does not copy definitions into `Config`.

`ConfigProvider` exposes these protected hooks:

```text
getConfig()
getFactories()
getInvokables()
getAliases()
getDelegators()
getServices()
getParameterResolvers()
shouldReplaceParameterResolvers()
getAttributeDefinitions()
shouldReplaceAttributeDefinitions()
getAttributeCapabilities()
```

## Dependency definitions

### Services

Services are runtime values and retain their state and identity. Objects, closures, resources, `null`, and `false` are valid values.

```php
protected function getServices(): array
{
    return [
        ClockInterface::class => new SystemClock(),
        'feature.enabled' => false,
    ];
}
```

### Factories

The complete runtime factory ABI is `(ContainerValue $context, array $params)`. A callable may declare a compatible prefix of these arguments. Invalid signatures are rejected while the container is built.

```php
protected function getFactories(): array
{
    return [
        Client::class => static fn(ContainerValue $context, array $params): Client =>
            new Client(
                $context->config->string('api.endpoint'),
                $params['timeout'] ?? 10,
            ),
    ];
}
```

A factory owns the complete creation of its result. The container does not apply that result's DI attributes after the factory returns it.

`ClassDefinition` is a declarative factory. It invokes a public constructor immediately and then executes explicit `call()` instructions in registration order, including repeated calls to the same method:

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

$definition = ClassDefinition::create(Client::class)
    ->constructor(['endpoint' => 'https://api.example.test', 'timeout' => 10])
    ->call('setLogger', [Definition::reference(LoggerInterface::class)]);
```

By default, arguments come only from the definition, constructor overrides supplied to `make()`, and PHP parameter defaults. `make()` overrides match constructor parameter names or positions; a name takes precedence over a position. Type-name keys are not constructor arguments. Replaced references are not resolved, and runtime values remain literal. `ReferenceDefinition` looks up a dependency through the existing container; that dependency follows its own registration and lifecycle.

Call `autowire()` or `autowire(true)` to enable type-based DI fallback for missing constructor and method arguments. Explicit arguments, including `null`, take precedence. If no service is available for the declared class/interface type, PHP defaults still apply; a required argument without a value fails. `autowire(false)` disables this fallback. The flag does not enable parameter attributes or custom parameter resolvers.

`ClassDefinition` never executes DI attributes on its target class, properties, constructor parameters, or configured methods. This includes `SetUp`, `Inject`, `Lazy`, `Proxy`, and `NoConstructor`, even when autowiring fallback is enabled. A failed constructor or explicit method call aborts creation; the failed result is not shared. A later `get()` or `make()` starts a new creation attempt.

`constructor()`, `call()`, and `autowire()` return new immutable definitions. `call()` replaces the former `method()` API. `DefinitionInterface` is a marker; it imposes no shared `value` property on concrete definitions.

### Invokables and aliases

```php
protected function getInvokables(): array
{
    return [
        Logger::class,
        LoggerInterface::class => Logger::class,
    ];
}

protected function getAliases(): array
{
    return [
        CacheInterface::class => RedisCache::class,
    ];
}
```

A keyed invokable registers both the concrete class and an alias. Invokables use a direct zero-argument constructor call, including PHP defaults, without processing DI attributes.

### Delegators

Delegators receive the current entry and may optionally accept the container. They run in provider and registration order.

```php
protected function getDelegators(): array
{
    return [
        Client::class => [
            static fn(Client $client): Client => $client->withMetrics(),
            ['tracing.decorator', 'decorate'],
        ],
    ];
}
```

A deferred service-method pair such as `['tracing.decorator', 'decorate']` must be nested inside the pipeline list. This distinguishes it from a pipeline containing two string delegators.

## Container API

`Container` implements `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface`, and `ProxyFactoryInterface`.

```php
$shared = $container->get(Service::class);                 // shared result
$exists = $container->has(Service::class);                 // never throws
$fresh = $container->make(Service::class, ['id' => 42]);   // fresh object
$result = $container->call([$controller, 'show'], ['id' => 42]);
```

Reflection autowiring is used for a concrete class when no explicit binding exists. An explicit factory takes precedence.

The runtime mutation methods `set()`, `alias()`, `delegator()`, and `addContainer()` remain available. They invalidate affected shared entries and delegator results without rebuilding the whole container. Core DI services, `Config`, `Environment`, `ContainerValue`, and `DependencyDefinitions` cannot be replaced or shadowed.

If a factory or delegator changes a binding while an earlier `get()` is running (including a suspended Fiber), that call may finish with its earlier value. Later lookups observe the changed binding; the in-flight result cannot restore invalidated cache entries.

## Attributes and request mapping

Built-in object and parameter attributes include:

```text
#[Config] #[Env] #[EntryId] #[Inject] #[Make]
#[Lazy] #[Proxy] #[NoConstructor] #[Init] #[SetUp] #[Cast]
#[CurrentRequest] #[CurrentUri]
#[Header] #[Cookie] #[QueryParam] #[PayloadParam]
#[RequestAttribute] #[ServerParam] #[UploadedFile]
#[MapRequest] #[MapQueryString] #[MapRequestPayload]
#[MapHeaders] #[MapCookies] #[MapRequestAttributes]
#[MapServerParams] #[MapUploadedFiles]
```

`#[CurrentRequest]` and `#[CurrentUri]` are explicit invocation-only sources. A bare PSR-7 request or URI type does not imply current-request semantics, and invocation-only values cannot be stored through constructor injection.

Request mapping reads explicitly selected PSR-7 sources, detects source conflicts, applies configured casting and validation, and creates DTOs through the same `FactoryInterface::make()` pipeline.

## Extension points

Custom parameter resolvers implement `ParameterResolverInterface` and are registered with an integer priority. Custom attributes use `AttributeDefinition`; `CapabilityPolicy` can define composition cardinality and ordering rules. These extensions must be supplied in `DependencyDefinitions` before the container is built because the parameter and attribute pipelines are sealed after construction.

Extension factories may resolve ordinary dependencies from the existing container. Attribute definitions are materialized in registration order, followed by parameter resolvers. Register any required attribute extension before the factory that consumes it. If bootstrap used a dependency before its attribute semantics were complete, or a later resolver could intercept an already resolved parameter ahead of the selected resolver, `ContainerFactory::create()` throws `InvalidConfigurationException`. The check uses attribute metadata and resolver `supports()` classification; it does not replay constructors, handlers, or value resolution. Unrelated extensions and lower-priority resolvers remain allowed. A dependency that needs a custom parameter resolver can be resolved by a later resolver factory, or deferred until container creation has completed.

`AttributePlan::all()` and `attributes()` preserve the order of the composed plan, including inherited capabilities and interleaved repeated attribute classes.

Composition rules receive read-only `AttributeUsage` metadata: the declared attribute class and arguments, its semantic definition, target, and declaration order. Reading `arguments` evaluates the declared expressions on each access; object arguments are not stored in the shared plan. A `new` expression can therefore run a constructor when a rule explicitly reads the arguments. Rules validate declaration constraints; checks that depend on changing runtime state belong in handlers. A fresh attribute instance is created when execution reaches its handler; shared plans never retain that runtime instance.

If construction makes another declared attribute available, the execution order is recomputed without reconstructing the pending instance. Newly discovered handlers must preserve the already executed order across the current object phase. A late change to parameter input policy or to a completed property composition throws `AttributeCompositionException`; constructors and handlers are not replayed. Completed parameter compositions remain checked while subsequent parameters resolve, so an incompatible late source cannot reach the callable body. Object creation also checks the completed before-instantiation phase before invoking the constructor and before returning the object. If the constructor or a lifecycle hook itself reveals an incompatible policy, its already completed side effects are not rolled back and the object is not returned as a successfully resolved service. Loading unrelated attributes is allowed.

Lazy objects and virtual proxies retain the policies selected during their initial before-instantiation phase. If those policies later become incompatible, initialization fails without replaying that phase. A fresh `make()` uses the now-available attributes; an existing deferred object is not reconfigured.

External PSR-11 containers may be registered with `Container::addContainer()`. External entries participate in cycle protection and cannot shadow protected core services.

## Runtime behavior

`get()` caches a shared result, including the result of its delegator pipeline. `make()` creates a fresh object and accepts runtime parameters. Reflection metadata, prepared parameter plans, alias paths, and resolver ownership are cached only in memory inside one container.

Development and production use the same `ContainerFactory`, normalization, validation, and resolver chain. Runtime dependency values are never exported, so non-serializable values have the same behavior in both environments.

## Exceptions

Package-owned failures implement `Componenta\DI\Exception\ExceptionInterface`, which also extends the PSR-11 container exception contract. Common concrete exceptions include `InvalidConfigurationException`, `NotFoundException`, `ResolutionException`, `CircularDependencyException`, `ConcurrentResolutionException`, `DelegatorException`, and `AttributeCompositionException`.

After DI resolves an explicitly invoked callable and enters its body, exceptions thrown by that callable propagate unchanged.
