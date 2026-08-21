# Componenta DI

`componenta/di` is a PSR-11 dependency injection container for PHP 8.4+ with autowiring, declarative configuration, composable attributes, lazy objects and AOT factory compilation.

DI v5 uses `componenta/config` v3 as its configuration runtime. `ContainerBuilder` produces one final runtime `Config` containing normalized DI dependencies while preserving the exact `Environment` object supplied by the application. Runtime and DI-cache paths consume the same normalized dependency schema.

## Installation

```bash
composer require componenta/di
```

Requirements: PHP 8.4+, `componenta/config` 3.x and PSR-11 2.x.

## Quick start

```php
use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider;
use Componenta\Config\Environment;
use Componenta\DI\Container;

final class AppConfigProvider extends ConfigProvider
{
    protected function getServices(): array
    {
        return [
            'app.name' => 'Example',
        ];
    }
}

$environment = Environment::fromGlobals();
$config = ConfigLoader::load($environment, new AppConfigProvider());
$container = Container::create($config);

$name = $container->get('app.name');
```

`Container::create()` delegates to `ContainerBuilder::configure($config)->build()`.

The container exposes the final normalized config and the same runtime environment object:

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;

$runtimeConfig = $container->get(Config::class);

$runtimeConfig instanceof Config; // true
$runtimeConfig->environment === $environment; // true
$container->get(Environment::class) === $environment; // true
```

The final `Config` can be a different object from the input because DI v5 attaches its normalized dependency graph to it.

## Configuration

DI v5 consumes the `dependencies` schema defined by `componenta/config` v3. Extend `Componenta\Config\ConfigProvider` and use these hooks:

```text
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

Config v3 owns deterministic provider composition and structural validation. DI v5 owns semantic validation and canonicalization of values inside those sections.

### Services

```php
protected function getServices(): array
{
    return [
        ClockInterface::class => new SystemClock(),
    ];
}
```

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

Keyed invokables are canonicalized into an invokable class plus an alias.

### Factories

Runtime factory ABI is `(ContainerValue, array)`; user callables may declare any compatible prefix/signature accepted by the validator.

```php
use Componenta\Config\ContainerValue;

protected function getFactories(): array
{
    return [
        Client::class => static function (ContainerValue $container, array $context): Client {
            return new Client($container->config->string('endpoint'));
        },
    ];
}
```

Invalid callable signatures fail during configuration validation instead of at first resolution.

### Delegators

Each service maps to a pipeline list. A callable pair is one nested pipeline item:

```php
protected function getDelegators(): array
{
    return [
        Client::class => [
            [MetricsDelegator::class, 'decorate'],
            TracingDelegator::class,
        ],
    ];
}
```

A direct pair such as `[MetricsDelegator::class, 'decorate']` is rejected because it is not an unambiguous pipeline.

### Parameter resolvers

Integer keys are semantic priorities and are preserved during provider composition:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => CustomParameterResolver::class,
    ];
}
```

`shouldReplaceParameterResolvers()` and `shouldReplaceAttributeDefinitions()` are tri-state: `true`/`false` are explicit values; `null` leaves an earlier composed value unchanged.

## Runtime environment

Environment is runtime state and is never stored in the DI cache. Application configuration can use config-v3 runtime-bound descriptors:

```php
use function Componenta\Config\env;

return [
    'api.token' => env('API_TOKEN'),
];
```

The descriptor is resolved against `Config::$environment` when read. DI `#[Env]` handling uses the same `Environment` object registered in the container.

## Container API

`Container` implements `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface` and `ProxyFactoryInterface`.

```php
$shared = $container->get(Service::class);
$exists = $container->has(Service::class);
$fresh = $container->make(Service::class);
$result = $container->call([$controller, 'show'], ['id' => 42]);
```

`get()` performs shared/cached resolution. `make()` performs fresh resolution. `call()` resolves callable arguments through the same parameter pipeline. Aliases are canonicalized before resolution, and circular/concurrent paths fail explicitly.

## Autowiring and parameter resolution

Concrete classes can be reflection-resolved when no explicit binding exists. Constructor and callable parameters use one priority pipeline:

```text
1200  parameter attributes
1100  array resolver
1000  typed array resolver
 300  autowire by type
 200  default value
 100  nullable fallback
```

Custom resolvers can extend or replace this pipeline.

## Attributes

DI v5 composes attributes by semantic capabilities. Built-in capabilities include:

```text
ValueProvider
AuthoritativeValueProvider
InvocationOnlyValueProvider
ValueTransformer
CreationStrategy
ConstructorPolicy
LifecycleHook
```

`InvocationOnlyValueProvider` marks execution-context values that are valid only while invoking a callable. A registered attribute with this capability is rejected on constructor parameters, so request-local state cannot accidentally become object state. Integration packages can reuse the capability for their own contextual attributes.

Built-in attributes include:

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

Authentication-specific context does not belong to DI core. Packages such as `componenta/auth-app` can register their own invocation-only attributes through `AttributeDefinition`.

### Current HTTP context

`#[CurrentRequest]` and `#[CurrentUri]` are authoritative invocation-only value sources:

```php
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

public function __invoke(
    #[CurrentRequest] ServerRequestInterface $request,
    #[CurrentUri] UriInterface $uri,
): ResponseInterface {
    // $uri === $request->getUri()
}
```

A bare `ServerRequestInterface` or `UriInterface` parameter has no hidden current-request meaning. Framework integrations pass the current request through the resolution context under `ServerRequestInterface::class`; request-aware attributes consume that transport explicitly.

The following is rejected during attribute-plan composition:

```php
final class Service
{
    public function __construct(
        #[CurrentRequest] ServerRequestInterface $request,
    ) {}
}
```

The same rule applies to custom attributes registered with `InvocationOnlyValueProvider` and is shared by runtime reflection and AOT preparation.

### Property injection

`#[Inject]` explicitly resolves a class-typed property from the container:

```php
final class Handler
{
    #[Inject]
    private LoggerInterface $logger;
}
```

Injected object state naturally lives as long as the receiving object. DI does not infer application-specific lifetimes for ordinary dependencies; execution-context values use `InvocationOnlyValueProvider` when retaining them would contradict their semantics.

## `#[SetUp]`

`#[SetUp]` executes lifecycle methods after object creation. Its arguments can be resolved from the current container, config, environment and entry id through the built-in setup unwrappers.

## Request mapping

Request attributes support scalar extraction and object mapping from PSR-7 requests. Sources include query parameters, payload, headers, cookies, request attributes, server parameters and uploaded files. Source conflicts are detected explicitly; mapped values can use lazy casting and validation providers.

For typed DTO mapping, DI validates request data, applies mapper transformations and creates the DTO through `FactoryInterface::make()`. Parameters declared with `ParameterSourceAttributeInterface` remain protected from mapped-data spoofing.

## Custom attributes

Register extension attributes through `AttributeDefinition`:

```php
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;

protected function getAttributeDefinitions(): array
{
    return [
        static fn(Psr\Container\ContainerInterface $container) => new AttributeDefinition(
            CurrentTenant::class,
            $container->get(CurrentTenantHandler::class),
            [
                AuthoritativeValueProvider::class,
                InvocationOnlyValueProvider::class,
            ],
        ),
    ];
}
```

Capabilities participate in composition, ordering and validation before handlers execute.

## Persistent caches

Application config and DI graph have separate ownership:

- `ConfigLoader` caches application/package config data and runtime-bound config descriptors;
- `DiCacheGenerator` caches only normalized DI dependencies;
- neither cache serializes the runtime `Environment` object.

Build the DI cache:

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;

$dependencies = $config->get(ConfigKey::DEPENDENCIES, []);
(new DiCacheGenerator())->generate($dependencies, 'var/cache/di.php');
```

Load both caches with the current runtime environment:

```php
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\DI\ContainerBuilder;

$environment = Environment::fromGlobals();
$config = ConfigLoader::loadFromFile('var/cache/config.php', $environment);
$diCache = require 'var/cache/di.php';

$container = ContainerBuilder::configureFromCache(
    $config,
    $diCache,
    baseDir: 'var/cache',
)->build();
```

Provider mode and cache mode both create a final normalized runtime `Config` while preserving the same supplied `Environment` object. The DI cache envelope is independently versioned by `ContainerBuilder::CACHE_VERSION`; stale or malformed cache shapes fail fast.

## AOT factories

`ContainerBuilder::compileFactories()` generates factory shards for selected autowire entries. Compiled artifacts use the same object/attribute/parameter semantics as runtime reflection and are covered by parity tests.

## External containers

A built container can resolve entries from external PSR-11 containers. External lookup participates in cycle protection.

## Exceptions

Core failures use Componenta DI exceptions including `InvalidConfigurationException`, `AttributeCompositionException`, `ResolutionException`, `NotFoundException`, `CircularDependencyException`, `ConcurrentResolutionException`, `DelegatorException` and `CompilationException`.

`has()` is intentionally non-throwing and returns `false` when an entry cannot be resolved safely.

## Development checks

```bash
composer check
```

The test suite contains runtime/AOT/cache parity coverage for object creation, attributes, request mapping, parameter resolution, callables and compiled factories.
