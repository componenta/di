# Componenta DI

[Русская версия](README.ru.md)

Componenta DI is a PSR-11 dependency-injection container for PHP 8.4+. It provides reflection autowiring, explicit bindings, DI-aware callable invocation, composable PHP attributes, native lazy objects and proxies, PSR-7 request mapping, persistent DI cache generation, and optional AOT factory shards.

A core invariant is that reflection and compiled production resolution use the same parameter and object pipelines. Compilation changes how an eligible entry is prepared, not what dependency-injection semantics it receives.

## Requirements

- PHP 8.4+
- `psr/container` 2.x
- a PSR-7 `ServerRequestInterface` implementation when HTTP request mapping is used

## Installation

```bash
composer require componenta/di
```

## Quick start

Componenta DI consumes the `dependencies` section produced by `componenta/config`. Extending `Componenta\Config\ConfigProvider` is the usual application-facing configuration API.

```php
<?php

declare(strict_types=1);

use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider;
use Componenta\Config\ContainerValue;
use Componenta\DI\Container;

final class AppConfigProvider extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            DatabaseConnection::class => static function (
                ContainerValue $container,
                array $params,
            ): DatabaseConnection {
                return new DatabaseConnection(
                    dsn: $container->config->string('database.dsn'),
                );
            },
        ];
    }

    protected function getAliases(): array
    {
        return [
            LoggerInterface::class => Logger::class,
        ];
    }

    protected function getConfig(): array
    {
        return [
            'database.dsn' => 'sqlite::memory:',
        ];
    }
}

$config = ConfigLoader::load(null, new AppConfigProvider());
$container = Container::create($config);

$service = $container->get(OrderService::class);
```

Concrete classes can normally be autowired without an explicit registration. Interfaces and other abstract ids need an alias, factory, service, invokable mapping, external container, or another resolvable binding.

## Container operations

`Container` implements PSR-11 and also exposes fresh object creation and DI-aware callable invocation:

```php
$shared = $container->get(OrderService::class);

$fresh = $container->make(ReportExporter::class, [
    'format' => 'csv',
]);

$result = $container->call(
    [$controller, 'show'],
    ['id' => 42],
);
```

- `get()` resolves and caches a shared entry.
- `make()` performs a fresh object-resolution attempt.
- `call()` resolves callable arguments through the same parameter pipeline used for constructors.

Explicit parameters may be keyed by parameter name or position. Type-keyed values are also supported for ordinary dependency types when the supplied value satisfies the declared type.

## Dependency configuration

`ConfigProvider` exposes these standard dependency hooks:

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
getDependencyExtensions()
```

### Factories

Use a factory when construction requires explicit application logic.

```php
protected function getFactories(): array
{
    return [
        HttpClientInterface::class => HttpClientFactory::class,
    ];
}

final class HttpClientFactory
{
    public function __invoke(
        ContainerValue $container,
        array $params = [],
    ): HttpClientInterface {
        return new HttpClient(
            baseUri: $container->config->string('http.base_uri'),
        );
    }
}
```

The runtime factory ABI is:

```php
(ContainerValue $container, array $params): mixed
```

Factory specifications may be ordinary callables, callable service ids, `[service id, method]` pairs, or DI definitions. Callable signatures are validated against the runtime arguments so incompatible factories fail as configuration errors.

### Invokables and aliases

```php
protected function getInvokables(): array
{
    return [
        Clock::class,
        ClockInterface::class => Clock::class,
    ];
}

protected function getAliases(): array
{
    return [
        CacheInterface::class => RedisCache::class,
    ];
}
```

A keyed invokable also creates an alias. Alias chains are canonicalized; cycles and conflicting bindings are rejected.

### Services

Services are values created before the container:

```php
protected function getServices(): array
{
    return [
        BuildInfo::class => new BuildInfo('production'),
    ];
}
```

Prefer factories for connections, clients, streams, and other resources whose construction belongs to runtime bootstrapping.

### Delegators

Delegators decorate resolved entries in registration order:

```php
protected function getDelegators(): array
{
    return [
        MailerInterface::class => [TracingMailerDelegator::class],
    ];
}

final class TracingMailerDelegator
{
    public function __invoke(
        MailerInterface $mailer,
        \Psr\Container\ContainerInterface $container,
    ): MailerInterface {
        return new TracingMailer($mailer, $container->get(Tracer::class));
    }
}
```

The delegator ABI is:

```php
(mixed $entry, ContainerInterface $container): mixed
```

Changing aliases, entries, or external-container ownership invalidates affected decorated entries.

### Definitions

`ClassDefinition` is useful for declarative constructor arguments and ordered method calls:

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

protected function getFactories(): array
{
    return [
        ApiClient::class => ClassDefinition::create(ApiClient::class)
            ->constructor([
                'timeout' => 10,
                'transport' => Definition::reference(TransportInterface::class),
            ])
            ->method('setLogger', [
                Definition::reference(LoggerInterface::class),
            ]),
    ];
}
```

Runtime parameters supplied to `make()` override configured constructor arguments.

## Parameter resolution

Higher-priority resolvers run first. The built-in chain is:

| Priority | Resolver role |
| ---: | --- |
| `ContainerBuilder::PRIORITY_PARAM_ATTRIBUTE` (1200) | composed parameter attributes |
| `PRIORITY_PARAM_ARRAY` (1100) | explicit values by parameter name/position |
| `PRIORITY_PARAM_ARRAY_TYPED` (1000) | explicit values keyed by declared type |
| `PRIORITY_PARAM_AUTOWIRE` (300) | container lookup by class/interface type |
| `PRIORITY_PARAM_DEFAULT_VALUE` (200) | PHP default value |
| `PRIORITY_PARAM_NULLABLE` (100) | `null` for unresolved nullable parameters |

Variadic and by-reference parameters are outside the DI resolver contract.

## Built-in attributes

Attributes are composed into a deterministic semantic plan before execution. Value-source, transformer, creation-strategy, constructor-policy, and lifecycle capabilities prevent incompatible combinations.

### Configuration and container values

```php
use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;

final class Service
{
    public function __construct(
        #[Config(new ConfigPath('api.endpoint'))]
        public string $endpoint,

        #[Env('APP_REGION', 'local')]
        public string $region,

        #[EntryId('mailer.transactional')]
        private MailerInterface $mailer,
    ) {}
}
```

A string passed to `#[Config]` is a literal config key. Use `ConfigPath` for nested traversal.

### Current HTTP context

Current HTTP context is explicit. `#[CurrentRequest]` and `#[CurrentUri]` are parameter-only authoritative value sources:

```php
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

public function __invoke(
    #[CurrentRequest] ServerRequestInterface $request,
    #[CurrentUri] UriInterface $uri,
): ResponseInterface {
    // $uri === $request->getUri() for this invocation
}
```

A bare `ServerRequestInterface $request` or `UriInterface $uri` does **not** mean "the current HTTP request". Without the attribute, the parameter is resolved like an ordinary dependency or explicit parameter.

Because these attributes are authoritative, generic caller values named `request` or `uri` cannot shadow the actual HTTP context.

### Current user

```php
use Componenta\DI\Attribute\CurrentUser;

public function __construct(
    #[CurrentUser(User::class)]
    private User $user,
) {}
```

`#[CurrentUser]` reads from `CurrentUserProviderInterface`. The default provider isolates user state by the active Fiber. Applications may register their own request/session-aware provider.

### Fresh values and initialization

```php
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Make;

public function __construct(
    #[Make(JobContext::class, ['queue' => 'emails'])]
    private JobContext $context,

    #[Init([LocaleFactory::class, 'current'])]
    private Locale $locale,
) {}
```

`#[Make]` requests a fresh object. `#[Init]` computes a value through a DI-aware callable.

### Casting

`#[Cast]` transforms a value through a named caster from `componenta/caster`:

```php
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Env;

public function __construct(
    #[Env('PAGE_SIZE')]
    #[Cast('int')]
    private int $pageSize,
) {}
```

DI does not hard-code an application-specific caster catalog.

### Property injection

`#[Inject]` injects a property from the container by declared type:

```php
use Componenta\DI\Attribute\Inject;

final class Handler
{
    #[Inject]
    private LoggerInterface $logger;
}
```

Static properties are rejected. Initialized readonly properties are not overwritten. Other built-in value-source attributes can target properties only when their PHP attribute declaration explicitly allows that target. `CurrentRequest` and `CurrentUri` are parameter-only.

### Object lifecycle and creation

- `#[SetUp('method', params: [...])]` calls repeatable setup methods after instantiation; method arguments use normal DI resolution.
- `#[NoConstructor]` allocates an object without invoking its constructor, then continues property/setup processing.
- `#[Lazy]` uses PHP 8.4 native lazy-ghost support for reflection-autowired classes.
- `#[Proxy]` selects native virtual-proxy creation and may be used on a class or injection point.

Opaque factory-bound services cannot automatically use ghost construction because DI does not own their constructor. Use `#[Proxy]` or implement `LazyServiceFactoryInterface` when a factory owns lazy creation.

The container also exposes native lazy/proxy creation directly:

```php
$lazy = $container->makeLazy(ExpensiveCatalog::class, $initializer);
$proxy = $container->makeProxy(RemoteClient::class, $factory);
```

## PSR-7 request mapping

Request-aware attributes use this parameter key as the **HTTP-context transport** for the current invocation:

```php
use Psr\Http\Message\ServerRequestInterface;

$container->call([$action, '__invoke'], [
    ServerRequestInterface::class => $request,
]);
```

Framework integrations such as `router-app` normally supply this value automatically.

`ServerRequestInterface::class` is reserved for request-context transport. It is deliberately ignored by ordinary type-key injection for a bare `ServerRequestInterface` parameter. If an unannotated parameter should receive a specific request object, pass it by parameter name/position or configure a normal DI binding.

The same transport is consumed by:

- `#[CurrentRequest]`
- `#[CurrentUri]`
- `#[QueryParam]`
- `#[PayloadParam]`
- `#[Header]`
- `#[Cookie]`
- `#[RequestAttribute]`
- `#[ServerParam]`
- `#[UploadedFile]`
- the `Map*` request attributes

No global current-request service is required.

### Single-value request extractors

```php
use Componenta\DI\Attribute\QueryParam;

final class ListProductsAction
{
    public function __invoke(
        #[QueryParam(default: 1)] int $page,
    ): array {
        // ...
    }
}
```

Extractor names default to the PHP parameter name when supported. Missing required values fail resolution unless a default is configured. Extractors with a `cast` option use the configured caster provider.

`PayloadParam` also accepts `ConfigPath` for nested payload lookup.

### Mapping arrays and DTOs

The source-specific mappers are:

```text
MapRequestPayload
MapQueryString
MapHeaders
MapCookies
MapRequestAttributes
MapServerParams
MapUploadedFiles
```

`MapRequest` can combine sources explicitly:

```php
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;

public function __invoke(
    #[MapRequest(
        sources: [RequestDataSource::Query, RequestDataSource::Attributes],
        map: ['q' => 'query'],
        defaults: ['page' => 1],
        exclude: ['internal'],
    )]
    SearchRequest $input,
): array {
    // ...
}
```

For an array target, mapping returns the transformed array. For a parameter with exactly one class type, DI creates the DTO through `FactoryInterface::make()`, so constructor dependencies still use the ordinary DI pipeline.

`MapRequest` rejects conflicting values from different sources by default. `RequestDataConflictPolicy::FirstWins` may be selected explicitly.

The DTO mapping order is:

1. extract and merge request data;
2. validate source data when a `ValidationProviderInterface` is available;
3. apply mapping/casts/defaults/sort mapping/exclusions;
4. create the typed DTO.

Explicit parameter-source attributes, including `CurrentRequest` and `CurrentUri`, are protected from mapped-data spoofing.

## Custom parameter resolvers

Implement `ParameterResolverInterface` for an application-specific parameter source:

```php
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final class TenantResolver implements ParameterResolverInterface
{
    public function __construct(private TenantContext $tenants) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->className === Tenant::class;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, $this->tenants->current()];
    }
}
```

Register priorities through configuration:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => TenantResolver::class,
    ];
}
```

A resolver returns `null` to continue the chain or `[position, value]` to resolve the parameter. DI validates the returned position and value. `supports()` is classification-only and must not mutate the resolver chain.

Resolver specifications may be an instance, a container service id/class, a factory receiving the container, or `[service id, method]`.

Return `true` from `shouldReplaceParameterResolvers()` only when intentionally replacing the complete built-in chain.

## Custom attributes

A custom attribute is described by an `AttributeDefinition`. Parameter handlers implement `ParameterAttributeHandlerInterface`; object/property/method handlers implement `AttributeHandlerInterface`.

```php
use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TenantId {}

protected function getAttributeDefinitions(): array
{
    return [
        static fn(\Psr\Container\ContainerInterface $container) =>
            new AttributeDefinition(
                attribute: TenantId::class,
                handler: $container->get(TenantIdHandler::class),
                capabilities: [ValueProvider::class],
            ),
    ];
}
```

Built-in semantic capabilities include:

- `ValueProvider`
- `AuthoritativeValueProvider`
- `ValueTransformer`
- `CreationStrategy`
- `ConstructorPolicy`
- `LifecycleHook`

`AttributeDefinition` may additionally declare `requires`, `forbids`, `before`, `after`, custom composition `rules`, a semantic `version`, and an execution `phase`.

Use `CapabilityPolicy` to add or override capability cardinality. Return `true` from `shouldReplaceAttributeDefinitions()` only when intentionally replacing the complete built-in attribute model.

## Programmatic builder API

Configuration providers are the normal application-facing API, but the builder can also be composed directly:

```php
$container = (new ContainerBuilder())
    ->addService(BuildInfo::class, $buildInfo)
    ->addInvokable(LoggerInterface::class, Logger::class)
    ->addFactory(HttpClientInterface::class, $httpClientFactory)
    ->addAlias(CacheInterface::class, RedisCache::class)
    ->addParameterResolver(TenantResolver::class, priority: 900)
    ->addAttributeDefinition($tenantAttributeDefinition)
    ->build();
```

## Runtime composition

A built container supports controlled runtime mutation:

```php
$container->set(FeatureFlags::class, $flags);
$container->alias(StorageInterface::class, S3Storage::class);
$container->delegator(StorageInterface::class, StorageTracingDelegator::class);
$container->addContainer($externalContainer);
```

Protected DI services cannot be replaced or decorated. Mutations invalidate affected cached entries. External containers are consulted before local shared `get()` resolution; `make()` remains a fresh local-resolution API.

## Persistent DI cache

`DiCacheGenerator` validates dependency configuration and writes a syntax-checked PHP cache through a temporary artifact followed by atomic activation:

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;

$builder = ContainerBuilder::configure($config);
$dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
$cacheFile = __DIR__ . '/var/cache/di.php';

(new DiCacheGenerator())->generate($dependencies, $cacheFile);
```

Load it explicitly:

```php
$cache = require $cacheFile;

$container = ContainerBuilder::configureFromCache(
    $config,
    $cache,
    baseDir: dirname($cacheFile),
)->build();
```

The cache envelope is versioned and validated before use.

## AOT compiled factory shards

`compileFactories()` generates content-addressed PHP shards for eligible reflection-autowired roots:

```php
$builder = ContainerBuilder::configure($config);
$directory = __DIR__ . '/var/cache/di-factories';

$compiled = $builder->compileFactories(
    [CheckoutHandler::class],
    $directory,
);
```

Generated methods are thin entry points into the same `ObjectPipeline` and parameter pipeline used by reflection resolution. Attribute composition, request mapping, lazy/proxy behavior, lifecycle hooks, and contextual `CurrentRequest`/`CurrentUri` semantics therefore remain part of the same runtime model.

Entries already owned by explicit factories, services, invokables, or protected container services are excluded from AOT autowiring. When cached compiled definitions contain relative shard filenames, pass their directory as `baseDir` when building from cache.

## Exceptions

Container-owned failures implement `Componenta\DI\Exception\ExceptionInterface`. Common failure categories include invalid dependency configuration, unresolved or cyclic entries, parameter-resolution failures, incompatible attribute composition, request-data conflicts, and invalid cache/compiled artifacts.

Configuration errors are rejected as early as possible rather than surviving until an unrelated production request reaches the invalid binding.

## License

MIT.
