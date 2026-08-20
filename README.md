# Componenta DI

[Русская версия](README.ru.md)

Componenta DI is a PSR-11 dependency injection container for PHP 8.4+. It combines conventional container configuration with reflection autowiring, DI-aware callable invocation, PHP attributes, lazy objects, PSR-7 request mapping, and optional ahead-of-time compiled factories.

The container is designed around one rule: **development and compiled production resolution use the same object and parameter pipelines**. Compilation replaces reflection-heavy entry discovery with generated factory shards; it does not introduce a second dependency-injection model.

## Requirements

- PHP 8.4 or newer
- `psr/container` 2.x
- a PSR-7 `ServerRequestInterface` implementation only when request mapping is used

## Installation

```bash
composer require componenta/di
```

## The basic model

There are three common ways to ask the container to do work:

- `get($id)` resolves a **shared container entry** and caches it.
- `make($class, $params)` creates a **fresh object** through the same DI pipeline.
- `call($callable, $params)` resolves callable arguments and invokes the callable.

For most applications, start with a configuration provider and build the container from the resulting `Config` object.

```php
<?php

declare(strict_types=1);

use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigProvider;
use Componenta\Config\ContainerValue;
use Componenta\DI\Container;
use Psr\Log\LoggerInterface;

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

    protected function getInvokables(): array
    {
        return [
            Logger::class,
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

Concrete classes that are not explicitly configured can normally be autowired by reflection. Interfaces and other abstract ids need a factory, alias, service, invokable mapping, external container, or another resolvable binding.

## Configuration

Componenta DI reads the `dependencies` section produced by `componenta/config`. Extending `Componenta\Config\ConfigProvider` is the recommended way to assemble package and application configuration.

### Factories

Factories are used when construction needs explicit application logic.

```php
use Componenta\Config\ConfigProvider;
use Componenta\Config\ContainerValue;

final class AppConfigProvider extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            HttpClientInterface::class => HttpClientFactory::class,
        ];
    }
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

A runtime factory is called with exactly these arguments:

```php
(ContainerValue $container, array $params): mixed
```

The callable may omit one or both parameters if its PHP signature accepts the two runtime arguments, but typed parameters are validated when the container is built or the factory is materialized. A factory that declares incompatible parameter types fails as configuration rather than at first successful-looking resolution.

Factory specifications may be ordinary callables, callable service ids, service-method pairs, or DI definitions.

### Invokables

Invokables are concrete classes registered as direct entries. A keyed item also creates an alias.

```php
protected function getInvokables(): array
{
    return [
        Clock::class,
        ClockInterface::class => Clock::class,
    ];
}
```

### Aliases

Aliases map one id to another id.

```php
protected function getAliases(): array
{
    return [
        CacheInterface::class => RedisCache::class,
    ];
}
```

Alias chains are resolved to a canonical id. Alias cycles and conflicting bindings are rejected during container construction.

### Services

Services are already-created values stored in the container.

```php
protected function getServices(): array
{
    return [
        BuildInfo::class => new BuildInfo('production'),
    ];
}
```

Use services for values that are intentionally created before the container. Prefer a factory for connections, clients, streams, and other resources whose construction belongs to runtime bootstrapping.

### Delegators

A delegator decorates an entry after its base value has been resolved. Multiple delegators run in registration order.

```php
protected function getDelegators(): array
{
    return [
        MailerInterface::class => [
            TracingMailerDelegator::class,
            MetricsMailerDelegator::class,
        ],
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

The delegator runtime signature is:

```php
(mixed $entry, ContainerInterface $container): mixed
```

The container tracks dependencies of deferred service-based delegators. Changing aliases, entries, or external-container ownership invalidates affected decorated values rather than leaving stale wrappers cached.

### Definitions

Definitions are useful when you need declarative constructor parameters, references, or ordered method calls.

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

Runtime parameters passed to `make()` take precedence over configured constructor parameters.

## Resolving entries

### Shared entries with `get()`

```php
$orderService = $container->get(OrderService::class);
```

`get()` implements PSR-11 semantics. A resolved entry is shared by the container and cached under its requested/canonical ids as appropriate.

### Fresh objects with `make()`

```php
$exporter = $container->make(ReportExporter::class, [
    'format' => 'csv',
]);
```

`make()` always performs a fresh object-resolution attempt. Explicit parameters may be keyed by parameter name or position; type-keyed runtime values are also used where the target accepts that type.

### DI-aware callable invocation

```php
$result = $container->call(
    [$controller, 'show'],
    ['id' => 42],
);
```

The same parameter resolver pipeline used for constructors is used for callable arguments. This is useful for controller actions, setup methods, jobs, and other application callables.

### Runtime changes

The built container exposes a small mutable surface when runtime composition is required:

```php
$container->set(FeatureFlags::class, $flags);
$container->alias(StorageInterface::class, S3Storage::class);
$container->delegator(StorageInterface::class, StorageTracingDelegator::class);
$container->addContainer($externalContainer);
```

Core DI services are protected and cannot be replaced or decorated. Mutating a binding invalidates the relevant cached entries.

External containers are consulted before local resolution. Do not register the Componenta container itself as an external container.

## Autowiring and parameter resolution

For an ordinary concrete class, constructor parameters are resolved by an ordered resolver chain. Higher priorities run first.

The default chain is:

| Priority | Resolver role |
| ---: | --- |
| `ContainerBuilder::PRIORITY_PARAM_ATTRIBUTE` (1200) | composed parameter attributes |
| `PRIORITY_PARAM_ARRAY` (1100) | explicitly supplied array values |
| `PRIORITY_PARAM_ARRAY_TYPED` (1000) | supplied values matched by declared type |
| `PRIORITY_PARAM_REQUEST_CONTEXT` (800) | PSR-7 request context |
| `PRIORITY_PARAM_AUTOWIRE` (300) | class/interface lookup by type |
| `PRIORITY_PARAM_DEFAULT_VALUE` (200) | PHP default value |
| `PRIORITY_PARAM_NULLABLE` (100) | `null` for unresolved nullable parameters |

Variadic and by-reference parameters are not part of the DI parameter-resolution contract.

## Built-in attributes

Attributes are composed before execution. A parameter may have one value source and any compatible transformers; class-level creation and lifecycle attributes follow their own capability rules.

### Value sources

`#[Config]` reads application configuration:

```php
use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config;

final class ApiClient
{
    public function __construct(
        #[Config(new ConfigPath('api.endpoint'))]
        public string $endpoint,
    ) {}
}
```

A plain string passed to `#[Config]` is a literal configuration key. Use `ConfigPath` when dot-separated nested traversal is intended.

`#[Env]` reads a raw environment value:

```php
use Componenta\DI\Attribute\Env;

final class RuntimeOptions
{
    public function __construct(
        #[Env('APP_REGION', 'local')]
        public string $region,
    ) {}
}
```

`#[EntryId]` resolves an explicit container id:

```php
use Componenta\DI\Attribute\EntryId;

public function __construct(
    #[EntryId('mailer.transactional')]
    private MailerInterface $mailer,
) {}
```

`#[CurrentUser]` injects the current user from `CurrentUserProviderInterface`. The default provider isolates Fiber-local user state, so concurrent Fiber contexts do not overwrite each other.

```php
use Componenta\DI\Attribute\CurrentUser;

public function __construct(
    #[CurrentUser(User::class)]
    private User $user,
) {}
```

Applications may replace `CurrentUserProviderInterface` with their own request/session-aware provider.

`#[Make]` creates a fresh entry instead of retrieving a shared one:

```php
use Componenta\DI\Attribute\Make;

public function __construct(
    #[Make(JobContext::class, ['queue' => 'emails'])]
    private JobContext $context,
) {}
```

`#[Init]` computes a value through a DI-aware callable:

```php
use Componenta\DI\Attribute\Init;

public function __construct(
    #[Init([LocaleFactory::class, 'current'])]
    private Locale $locale,
) {}
```

### Value transformation with `#[Cast]`

`#[Cast]` runs after a compatible value provider and delegates conversion to the named caster registered in `componenta/caster`.

```php
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Env;

public function __construct(
    #[Env('PAGE_SIZE')]
    #[Cast('registered-caster-name')]
    private int $pageSize,
) {}
```

The caster name is an application/caster-provider registration name; DI does not hard-code a project-specific caster catalog.

### Property injection

`#[Inject]` injects a property from the container by its declared type.

```php
use Componenta\DI\Attribute\Inject;

final class Handler
{
    #[Inject]
    private LoggerInterface $logger;
}
```

Property handlers claim a property before writing it. Static properties are rejected, and initialized readonly properties are not overwritten.

The value-source attributes such as `#[Config]`, `#[Env]`, `#[EntryId]`, `#[CurrentUser]`, `#[Make]`, and `#[Init]` can also target properties where their attribute declaration permits it.

### Object lifecycle

`#[SetUp]` calls a method after instantiation. It is repeatable and method arguments go through normal DI resolution.

```php
use Componenta\DI\Attribute\SetUp;

#[SetUp('setLogger')]
#[SetUp('boot', ['warmup' => true])]
final class SearchIndex
{
    public function setLogger(LoggerInterface $logger): void {}

    public function boot(bool $warmup = false): void {}
}
```

`#[NoConstructor]` allocates a class without invoking its constructor, then continues through property injection and setup processing. Use it only when constructor bypass is an intentional part of the object model.

### Lazy objects and virtual proxies

`#[Lazy]` uses PHP 8.4 native lazy-ghost support for an autowired class. The resulting object keeps the real class identity and initializes its state on first observable access.

```php
use Componenta\DI\Attribute\Lazy;

#[Lazy]
final class ExpensiveCatalog
{
    public function __construct(DatabaseConnection $db) {}
}
```

An opaque factory-bound service cannot automatically use ghost construction because DI does not own its constructor. For such services, use `#[Proxy]` or implement `LazyServiceFactoryInterface` on the factory.

`#[Proxy]` selects native virtual-proxy creation and can be placed on a class or an injection point.

The container also exposes the proxy API directly:

```php
$lazy = $container->makeLazy(ExpensiveCatalog::class, $initializer);
$proxy = $container->makeProxy(RemoteClient::class, $factory);
```

## PSR-7 request values

Request attributes work when the current `ServerRequestInterface` is supplied in the explicit parameter array under its interface id.

```php
use Psr\Http\Message\ServerRequestInterface;

$container->call([$action, '__invoke'], [
    ServerRequestInterface::class => $request,
]);
```

### Single-value extractors

Use these attributes when one argument comes from one request source:

- `#[QueryParam]` — query string parameter
- `#[PayloadParam]` — parsed request body parameter; `ConfigPath` can address a nested payload path
- `#[Header]` — header value
- `#[Cookie]` — cookie value
- `#[RequestAttribute]` — PSR-7 request attribute
- `#[ServerParam]` — server parameter
- `#[UploadedFile]` — uploaded file

When an extractor supports a `name` and it is omitted, the PHP parameter name is used. Missing required values fail resolution unless the attribute has a default. Extractors that expose a `cast` option use the configured caster provider.

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

### Mapping request data to arrays or DTOs

The `Map*` family maps a complete request source:

- `MapRequestPayload`
- `MapQueryString`
- `MapHeaders`
- `MapCookies`
- `MapRequestAttributes`
- `MapServerParams`
- `MapUploadedFiles`

`MapRequest` can combine several sources explicitly.

```php
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;

final class SearchAction
{
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
}
```

If the target parameter is an array, mapping returns the transformed array. If it has exactly one class type, DI creates that DTO through `FactoryInterface::make()`, so its constructor can still use the ordinary DI resolver pipeline.

`MapRequest` rejects conflicting values from different sources by default. `RequestDataConflictPolicy::FirstWins` can be selected explicitly when first-source precedence is intentional.

The mapping pipeline is deliberately ordered as follows:

1. extract and merge request data;
2. validate the extracted DTO data when a `ValidationProviderInterface` is available;
3. apply mapper transformations (`map`, casts, defaults, sort mapping, exclusions);
4. create the typed DTO.

Validation therefore sees transport/source data before mapping transformations. This keeps input validation distinct from normalization and object construction.

## Custom parameter resolvers

Implement `ParameterResolverInterface` when an application has a parameter source that should participate in ordinary constructor and callable resolution.

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

Register it by priority:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => TenantResolver::class,
    ];
}
```

Higher priorities run first. A resolver returns `null` to continue the chain or `[parameter position, value]` to resolve the parameter. DI validates both the returned position and the value against the declared parameter type.

`supports()` is classification only: it must not mutate the resolver chain or keep per-resolution mutable state.

Resolver specifications may be:

- a `ParameterResolverInterface` instance;
- a service id/class resolved from the container;
- a callable factory receiving the container;
- a `[service id, method]` factory pair.

Override `shouldReplaceParameterResolvers()` and return `true` only when you intentionally want to remove the complete built-in resolver chain.

## Custom attributes

Custom DI attributes are registered as `AttributeDefinition` objects. The definition tells DI **what the attribute means**, **which handler executes it**, and **how it composes with other attributes**.

For a parameter-only attribute, implement `ParameterAttributeHandlerInterface`.

```php
use Attribute;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TenantId {}

final class TenantIdHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(private TenantContext $tenants) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return ParameterAttributeValue::resolved($this->tenants->id());
    }
}
```

Register the semantic definition:

```php
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;

protected function getAttributeDefinitions(): array
{
    return [
        static fn (\Psr\Container\ContainerInterface $container) =>
            new AttributeDefinition(
                attribute: TenantId::class,
                handler: $container->get(TenantIdHandler::class),
                capabilities: [ValueProvider::class],
            ),
    ];
}
```

For class, property, or method attributes, implement `AttributeHandlerInterface` instead. A handler that intentionally supports both surfaces implements both contracts.

### Attribute capabilities

Capabilities describe semantic roles rather than concrete attribute names:

- `ValueProvider` — supplies a parameter/property value; at most one per target by default.
- `AuthoritativeValueProvider` — a value provider that generic caller parameters must not shadow.
- `ValueTransformer` — transforms an already-provided value; multiple transformers may compose.
- `CreationStrategy` — selects object creation behavior such as lazy/proxy; at most one by default.
- `ConstructorPolicy` — changes constructor execution; at most one by default.
- `LifecycleHook` — runs around the object lifecycle; multiple hooks may compose.

`AttributeDefinition` can additionally declare:

- `requires` — another attribute/capability must be present;
- `forbids` — another attribute/capability must not be present;
- `before` / `after` — deterministic composition ordering;
- `rules` — custom `AttributeCompositionRuleInterface` checks;
- `phase` — `BeforeInstantiation`, `AfterInstantiation`, or `Both`.

Selectors in `requires`, `forbids`, `before`, and `after` may reference either an attribute class or an `AttributeCapabilityInterface` class.

Applications can add or override capability cardinality with `CapabilityPolicy`:

```php
use Componenta\DI\Attribute\Composition\CapabilityPolicy;

protected function getAttributeCapabilities(): array
{
    return [
        new CapabilityPolicy(MyCapability::class, maxPerTarget: 1),
    ];
}
```

Return `true` from `shouldReplaceAttributeDefinitions()` only when you want to remove every built-in attribute definition and supply the complete attribute model yourself.

## Builder API

Configuration providers are the usual application-facing API, but `ContainerBuilder` can also be composed programmatically:

```php
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addService(BuildInfo::class, $buildInfo)
    ->addInvokable(LoggerInterface::class, Logger::class)
    ->addFactory(HttpClientInterface::class, $httpClientFactory)
    ->addAlias(CacheInterface::class, RedisCache::class)
    ->addParameterResolver(TenantResolver::class, priority: 900)
    ->addAttributeDefinition($tenantAttributeDefinition)
    ->build();
```

`ContainerBuilder::configure($config)` is preferred when configuration should come from `componenta/config` providers.

## Persistent DI cache

`DiCacheGenerator` validates and serializes dependency configuration to a PHP cache artifact. Writes are performed through a temporary file, syntax-checked, and atomically activated.

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

$builder = ContainerBuilder::configure($config);
$dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
$cacheFile = __DIR__ . '/var/cache/di.php';

(new DiCacheGenerator())->generate($dependencies, $cacheFile);
```

Load the cache explicitly in production:

```php
$cacheFile = __DIR__ . '/var/cache/di.php';
$cache = require $cacheFile;

$container = ContainerBuilder::configureFromCache(
    $config,
    $cache,
    baseDir: dirname($cacheFile),
)->build();
```

The cache envelope has a format version and is validated before use. Treat generated DI cache files as build artifacts, not as user-editable configuration.

## AOT compiled factory shards

For reflection-autowired application roots, `compileFactories()` can generate content-addressed PHP shards. The generated methods are thin entry points into the same `ObjectPipeline` used by reflection resolution, preserving attribute, lifecycle, request, lazy-object, and parameter semantics.

```php
use Componenta\DI\Attribute\Autowire;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

#[Autowire]
final class CheckoutHandler
{
    public function __construct(
        private PaymentGateway $payments,
        private OrderRepository $orders,
    ) {}
}

$builder = ContainerBuilder::configure($config);
$directory = __DIR__ . '/var/cache/di-factories';

$compiled = $builder->compileFactories(
    [CheckoutHandler::class],
    $directory,
);

$dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
$dependencies[ConfigKey::FACTORIES] = [
    ...$dependencies[ConfigKey::FACTORIES],
    ...$compiled,
];
```

The compiler expands eligible autowired dependencies from the requested roots and excludes entries already owned by explicit factories, services, invokables, or protected container services. Generated shard filenames are content-addressed and validated at the runtime boundary.

When cached compiled definitions contain relative shard filenames, pass the shard directory as the `baseDir` used to build the cached container.

## Exceptions

Container-owned failures implement `Componenta\DI\Exception\ExceptionInterface`. PSR-11 lookup failures are normalized to the package's `NotFoundException`/resolution exceptions where appropriate, while configuration and compilation errors are reported separately.

Common failure categories are:

- invalid or conflicting dependency configuration;
- unresolved/cyclic entries;
- parameter-resolution errors;
- incompatible attribute composition;
- request-data conflicts or invalid request mapping;
- invalid cache or compiled-factory artifacts.

The container intentionally fails early for configuration errors so an invalid binding does not survive until an unrelated request reaches it.

## Recommended application structure

A small application usually needs only:

```text
src/
  ConfigProvider.php
  ... application classes ...
var/
  cache/
```

Keep package bindings in package providers, compose those providers with the application provider, and generate DI/cache artifacts during the build or deployment step. Application code should normally depend on interfaces and constructor injection rather than call the container directly.

## License

MIT.
