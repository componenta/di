# Componenta DI

`componenta/di` is a PSR-11 dependency injection container for PHP 8.4+ with autowiring, declarative configuration, composable attributes, lazy objects and AOT factory compilation.

DI v5 uses `componenta/config` v3 as its configuration runtime. The same `Config` and `Environment` snapshots are registered in the container, and the runtime and compiled-cache paths consume the same normalized dependency schema.

## Installation

```bash
composer require componenta/di
```

Requirements:

- PHP 8.4+
- `componenta/config` 3.x
- PSR-11 2.x

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

The container exposes the same runtime snapshots:

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;

$container->get(Config::class) === $config;
$container->get(Environment::class) === $config->environment;
```

## Configuration

DI v5 consumes the `dependencies` section defined by `componenta/config` v3. Extend `Componenta\Config\ConfigProvider` and use these hooks:

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

Config v3 owns deterministic provider composition and structural validation. DI v5 owns semantic validation and canonicalization of factories, aliases, invokables, delegators, resolver specifications and attribute definitions.

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

Keyed invokables are canonicalized into an invokable class plus an alias by DI v5.

### Factories

Factories are validated when configuration is normalized. Supported factory specifications must satisfy the v5 factory contract; a callable factory receives the DI runtime context defined by the package.

```php
protected function getFactories(): array
{
    return [
        Client::class => static function ($containerValue, array $context): Client {
            return new Client($containerValue->config->string('endpoint'));
        },
    ];
}
```

### Delegators

Each service maps to a pipeline list. A callable pair is one item inside that list:

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

A direct pair such as `[MetricsDelegator::class, 'decorate']` is not a pipeline and is rejected by config v3 composition.

### Parameter resolvers

Resolver keys are integer priorities and remain stable across provider composition:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => CustomParameterResolver::class,
    ];
}
```

`shouldReplaceParameterResolvers()` is tri-state: `true` or `false` is explicit; `null` means the provider does not change an earlier value.

The same rule applies to `shouldReplaceAttributeDefinitions()`.

## Runtime environment

Environment is runtime state, not DI cache data. Application configuration can use config-v3 runtime-bound environment descriptors:

```php
use function Componenta\Config\env;

return [
    'api.token' => env('API_TOKEN'),
];
```

The value is resolved from `Config::$environment` when the config key is read. Build-time secrets are not copied into the persistent application-config cache by `env()`.

DI attributes that read environment values also use the same `Environment` snapshot registered in the container.

## Container API

`Container` implements:

- `Psr\Container\ContainerInterface`
- `Componenta\DI\FactoryInterface`
- `Componenta\DI\CallableExecutorInterface`
- `Componenta\DI\ProxyFactoryInterface`

Basic resolution:

```php
$service = $container->get(Service::class);
$exists = $container->has(Service::class);
$fresh = $container->make(Service::class);
```

`get()` is shared/cached resolution. `make()` is the fresh-resolution API used when a new object graph is required.

Aliases are canonicalized before resolution. Circular and concurrent resolution paths fail with Componenta DI exceptions rather than being silently ignored.

## Autowiring

Concrete classes can be resolved by reflection when no explicit binding exists. Constructor parameters are resolved through the parameter resolver pipeline.

Built-in resolver priorities:

```text
1200  parameter attributes
1100  array resolver
1000  typed array resolver
 300  autowire by type
 200  default value
 100  nullable fallback
```

Custom resolvers can replace or extend the default pipeline through configuration or `ContainerBuilder`.

## Attributes

DI v5 composes attributes by capabilities rather than hard-coding arbitrary ordering rules.

Built-in capabilities include:

- value providers;
- authoritative value providers;
- value transformers;
- creation strategies;
- constructor policies;
- lifecycle hooks.

Common built-in attributes include:

```text
#[Config]
#[Env]
#[EntryId]
#[Inject]
#[Make]
#[Lazy]
#[Proxy]
#[NoConstructor]
#[Init]
#[SetUp]
#[Cast]
#[CurrentRequest]
#[CurrentUri]
#[CurrentUser]
#[Header]
#[Cookie]
#[QueryParam]
#[PayloadParam]
#[RequestAttribute]
#[ServerParam]
#[UploadedFile]
#[MapRequest]
#[MapQueryString]
#[MapRequestPayload]
#[MapHeaders]
#[MapCookies]
#[MapRequestAttributes]
#[MapServerParams]
#[MapUploadedFiles]
```

Parameter attributes run through the parameter resolver pipeline. Class/property/method lifecycle behavior runs through the attribute composition pipeline.

## `#[SetUp]`

`#[SetUp]` runs lifecycle methods after object creation and resolves its value descriptors against the current container/config/runtime state.

Built-in setup value support includes DI entry references, config references, environment descriptors, entry ids and explicit lazy/config value wrappers used by `componenta/config`.

## Request mapping

The request attributes support scalar extraction and object mapping from PSR-7 requests. Mapping sources include query parameters, payload, headers, cookies, request attributes, server parameters and uploaded files.

Request-source conflicts are detected explicitly. Mapped values can pass through lazy casting and validation providers while keeping runtime and compiled behavior aligned.

## DI cache

`DiCacheGenerator` owns only the normalized DI dependency graph. It does not persist the application `Config` or its `Environment` snapshot.

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

$dependencies = $config->get(ConfigKey::DEPENDENCIES, []);
(new DiCacheGenerator())->generate($dependencies, 'var/cache/di.php');
```

At runtime, load application config with the current environment and attach the DI cache:

```php
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;

$environment = Environment::fromGlobals();
$config = ConfigLoader::loadFromFile('var/cache/config.php', $environment);
$diCache = require 'var/cache/di.php';

$container = ContainerBuilder::configureFromCache(
    $config,
    $diCache,
    baseDir: 'var/cache',
)->build();
```

`ContainerBuilder` reattaches normalized dependencies to a new runtime `Config` while preserving the exact same `Environment` object. Provider mode and cache mode therefore converge on the same runtime configuration model.

The DI cache envelope is independently versioned by `ContainerBuilder::CACHE_VERSION`. Unknown keys and stale versions fail fast.

## AOT factories

`ContainerBuilder::compileFactories()` can generate factory shards for discovered autowire entries. Runtime loading validates compiled artifacts and falls back/fails according to the compiled factory contract rather than silently changing object semantics.

## External containers

A built container may resolve entries from registered external PSR-11 containers. External lookup participates in cycle protection so cross-container recursion cannot loop indefinitely.

## Error model

Configuration and runtime failures use Componenta DI exceptions such as:

- `InvalidConfigurationException`
- `ResolutionException`
- `NotFoundException`
- `CircularDependencyException`
- `ConcurrentResolutionException`
- `DelegatorException`
- `CompilationException`

`has()` is intentionally non-throwing and returns `false` when an entry cannot be resolved safely.

## Development checks

```bash
composer check
```

The package test suite contains explicit runtime/AOT/cache parity tests for object creation, attributes, request mapping, parameter resolution, callable handling and compiled factories.
