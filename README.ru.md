# Componenta DI

[English version](README.md)

Componenta DI — PSR-11 контейнер внедрения зависимостей для PHP 8.4+. Он поддерживает reflection autowiring, явные bindings, DI-aware вызов callable, композицию PHP-атрибутов, native lazy objects и proxies, отображение PSR-7 request в параметры/DTO, persistent DI cache и опциональные AOT factory shards.

Ключевой инвариант: reflection-режим и скомпилированный production-режим используют один и тот же parameter/object pipeline. Компиляция меняет подготовку подходящих entries, но не создаёт вторую семантику DI.

## Требования

- PHP 8.4+
- `psr/container` 2.x
- реализация PSR-7 `ServerRequestInterface`, если используется HTTP request mapping

## Установка

```bash
composer require componenta/di
```

## Быстрый старт

Componenta DI читает секцию `dependencies`, формируемую `componenta/config`. Для конфигурации приложения обычно наследуются от `Componenta\Config\ConfigProvider`.

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

Конкретные классы обычно autowire-ятся без явной регистрации. Для интерфейсов и других абстрактных id нужен alias, factory, service, invokable mapping, внешний container или другая разрешимая binding.

## Основные операции контейнера

`Container` реализует PSR-11 и дополнительно поддерживает fresh object creation и DI-aware callable invocation:

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

- `get()` разрешает и кеширует shared entry.
- `make()` всегда выполняет новую object-resolution попытку.
- `call()` разрешает аргументы callable тем же parameter pipeline, который используется для конструкторов.

Explicit parameters можно передавать по имени параметра или позиции. Для обычных dependency types также поддерживаются type-keyed значения, если значение соответствует объявленному типу.

## Конфигурация зависимостей

`ConfigProvider` предоставляет стандартные hooks:

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

Factory используется, когда создание объекта содержит явную прикладную логику.

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

Runtime ABI фабрики:

```php
(ContainerValue $container, array $params): mixed
```

Factory specification может быть обычным callable, callable service id, парой `[service id, method]` или DI definition. Сигнатура callable валидируется относительно runtime-аргументов, поэтому несовместимая фабрика считается ошибкой конфигурации.

### Invokables и aliases

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

Invokable со строковым ключом одновременно создаёт alias. Alias chains приводятся к canonical id; циклы и конфликтующие bindings отклоняются.

### Services

Services — значения, созданные до контейнера:

```php
protected function getServices(): array
{
    return [
        BuildInfo::class => new BuildInfo('production'),
    ];
}
```

Для connections, clients, streams и других runtime resources обычно предпочтительнее factory.

### Delegators

Delegators декорируют разрешённый entry в порядке регистрации:

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

ABI delegator:

```php
(mixed $entry, ContainerInterface $container): mixed
```

Изменение aliases, entries или ownership внешнего container инвалидирует затронутые decorated entries.

### Definitions

`ClassDefinition` позволяет декларативно задать constructor arguments и последовательность method calls:

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

Runtime parameters, переданные в `make()`, имеют приоритет над настроенными constructor arguments.

## Разрешение параметров

Resolver с более высоким priority выполняется раньше. Built-in chain:

| Priority | Назначение |
| ---: | --- |
| `ContainerBuilder::PRIORITY_PARAM_ATTRIBUTE` (1200) | composed parameter attributes |
| `PRIORITY_PARAM_ARRAY` (1100) | explicit values по имени/позиции |
| `PRIORITY_PARAM_ARRAY_TYPED` (1000) | explicit values по объявленному типу |
| `PRIORITY_PARAM_AUTOWIRE` (300) | lookup class/interface через container |
| `PRIORITY_PARAM_DEFAULT_VALUE` (200) | PHP default value |
| `PRIORITY_PARAM_NULLABLE` (100) | `null` для неразрешённого nullable parameter |

Variadic и by-reference параметры не входят в DI resolver contract.

## Встроенные атрибуты

Перед выполнением атрибуты компонуются в детерминированный semantic plan. Capabilities value source, transformer, creation strategy, constructor policy и lifecycle не позволяют собирать несовместимые комбинации.

### Значения из config и container

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

Строка в `#[Config]` означает literal config key. Для nested traversal используйте `ConfigPath`.

### Текущий HTTP-контекст

Текущий HTTP-контекст задаётся явно. `#[CurrentRequest]` и `#[CurrentUri]` работают **только на параметрах** и являются authoritative value sources:

```php
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

public function __invoke(
    #[CurrentRequest] ServerRequestInterface $request,
    #[CurrentUri] UriInterface $uri,
): ResponseInterface {
    // $uri === $request->getUri() для этого вызова
}
```

Обычный `ServerRequestInterface $request` или `UriInterface $uri` **не** означает «текущий HTTP request». Без атрибута такой параметр разрешается как обычная dependency или explicit parameter.

Поскольку `CurrentRequest` и `CurrentUri` authoritative, generic caller values с именами `request` или `uri` не могут подменить фактический HTTP-контекст.

### Текущий пользователь

```php
use Componenta\DI\Attribute\CurrentUser;

public function __construct(
    #[CurrentUser(User::class)]
    private User $user,
) {}
```

`#[CurrentUser]` получает пользователя через `CurrentUserProviderInterface`. Default provider изолирует user state по активному Fiber. Приложение может зарегистрировать собственный request/session-aware provider.

### Fresh values и инициализация

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

`#[Make]` создаёт fresh object. `#[Init]` вычисляет значение через DI-aware callable.

### Casting

`#[Cast]` преобразует значение через named caster из `componenta/caster`:

```php
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Env;

public function __construct(
    #[Env('PAGE_SIZE')]
    #[Cast('int')]
    private int $pageSize,
) {}
```

DI не задаёт project-specific caster catalog.

### Property injection

`#[Inject]` внедряет property из container по объявленному типу:

```php
use Componenta\DI\Attribute\Inject;

final class Handler
{
    #[Inject]
    private LoggerInterface $logger;
}
```

Static properties отклоняются. Инициализированные readonly properties не перезаписываются. Другие value-source attributes могут использоваться на properties только тогда, когда их PHP attribute declaration явно разрешает такой target. `CurrentRequest` и `CurrentUri` разрешены только на параметрах.

### Lifecycle и создание объектов

- `#[SetUp('method', params: [...])]` вызывает repeatable setup methods после создания объекта; аргументы метода разрешаются обычным DI pipeline.
- `#[NoConstructor]` создаёт объект без вызова constructor, затем продолжает property/setup processing.
- `#[Lazy]` использует native lazy ghost PHP 8.4 для reflection-autowired classes.
- `#[Proxy]` включает native virtual proxy и может применяться к class или injection point.

Opaque factory-bound service не может автоматически использовать ghost construction, потому что DI не управляет его constructor. Для таких случаев используйте `#[Proxy]` или `LazyServiceFactoryInterface`.

Прямой API:

```php
$lazy = $container->makeLazy(ExpensiveCatalog::class, $initializer);
$proxy = $container->makeProxy(RemoteClient::class, $factory);
```

## PSR-7 request mapping

Request-aware attributes используют следующий key как **transport HTTP-контекста** текущего вызова:

```php
use Psr\Http\Message\ServerRequestInterface;

$container->call([$action, '__invoke'], [
    ServerRequestInterface::class => $request,
]);
```

Framework-интеграции, например `router-app`, обычно передают его автоматически.

`ServerRequestInterface::class` зарезервирован именно для request-context transport. Он намеренно игнорируется обычным type-key injection для голого параметра `ServerRequestInterface`. Если неаннотированному параметру нужен конкретный request object, передайте его по имени/позиции либо настройте обычный DI binding.

Один и тот же transport используют:

- `#[CurrentRequest]`
- `#[CurrentUri]`
- `#[QueryParam]`
- `#[PayloadParam]`
- `#[Header]`
- `#[Cookie]`
- `#[RequestAttribute]`
- `#[ServerParam]`
- `#[UploadedFile]`
- request attributes семейства `Map*`

Глобальный current-request service не требуется.

### Получение одного значения

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

Если extractor поддерживает `name` и оно не задано, используется имя PHP-параметра. Отсутствующее обязательное значение приводит к resolution error, если default не задан. Extractors с параметром `cast` используют настроенный caster provider.

`PayloadParam` дополнительно принимает `ConfigPath` для nested payload path.

### Mapping в array и DTO

Source-specific mappers:

```text
MapRequestPayload
MapQueryString
MapHeaders
MapCookies
MapRequestAttributes
MapServerParams
MapUploadedFiles
```

`MapRequest` объединяет несколько источников явно:

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

Для `array` mapping возвращает transformed array. Если параметр имеет ровно один class type, DI создаёт DTO через `FactoryInterface::make()`, поэтому constructor dependencies по-прежнему проходят обычный DI pipeline.

`MapRequest` по умолчанию отклоняет конфликтующие значения разных sources. При необходимости можно явно выбрать `RequestDataConflictPolicy::FirstWins`.

Порядок DTO mapping:

1. извлечение и merge request data;
2. validation source data, если доступен `ValidationProviderInterface`;
3. mapping/casts/defaults/sort mapping/exclusions;
4. создание typed DTO.

Explicit parameter-source attributes, включая `CurrentRequest` и `CurrentUri`, защищены от spoofing через mapped data.

## Собственные parameter resolvers

Для application-specific источника реализуйте `ParameterResolverInterface`:

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

Регистрация по priority:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => TenantResolver::class,
    ];
}
```

Resolver возвращает `null`, чтобы продолжить chain, либо `[position, value]`. DI проверяет и позицию, и соответствие значения типу. `supports()` предназначен только для classification и не должен изменять resolver chain.

Resolver specification может быть instance, service id/class из container, factory, принимающей container, или `[service id, method]`.

`shouldReplaceParameterResolvers()` должен возвращать `true` только при намеренной полной замене built-in chain.

## Собственные атрибуты

Собственный атрибут описывается `AttributeDefinition`. Parameter handlers реализуют `ParameterAttributeHandlerInterface`, а class/property/method handlers — `AttributeHandlerInterface`.

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

Built-in semantic capabilities:

- `ValueProvider`
- `AuthoritativeValueProvider`
- `ValueTransformer`
- `CreationStrategy`
- `ConstructorPolicy`
- `LifecycleHook`

`AttributeDefinition` также может задавать `requires`, `forbids`, `before`, `after`, custom composition `rules`, semantic `version` и execution `phase`.

`CapabilityPolicy` позволяет задавать/переопределять cardinality capability. `shouldReplaceAttributeDefinitions()` следует использовать только для намеренной полной замены встроенной attribute model.

## Programmatic builder API

Обычно используется конфигурация через providers, но builder можно собирать напрямую:

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

Готовый container поддерживает контролируемые runtime-изменения:

```php
$container->set(FeatureFlags::class, $flags);
$container->alias(StorageInterface::class, S3Storage::class);
$container->delegator(StorageInterface::class, StorageTracingDelegator::class);
$container->addContainer($externalContainer);
```

Protected DI services нельзя заменять и декорировать. Изменения инвалидируют связанные cached entries. Внешние containers проверяются перед локальным shared `get()`, тогда как `make()` остаётся fresh local-resolution API.

## Persistent DI cache

`DiCacheGenerator` валидирует dependency configuration и записывает syntax-checked PHP cache через temporary artifact с последующей atomic activation:

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;

$builder = ContainerBuilder::configure($config);
$dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
$cacheFile = __DIR__ . '/var/cache/di.php';

(new DiCacheGenerator())->generate($dependencies, $cacheFile);
```

Загрузка:

```php
$cache = require $cacheFile;

$container = ContainerBuilder::configureFromCache(
    $config,
    $cache,
    baseDir: dirname($cacheFile),
)->build();
```

Cache envelope версионируется и валидируется до использования.

## AOT compiled factory shards

`compileFactories()` создаёт content-addressed PHP shards для подходящих reflection-autowired roots:

```php
$builder = ContainerBuilder::configure($config);
$directory = __DIR__ . '/var/cache/di-factories';

$compiled = $builder->compileFactories(
    [CheckoutHandler::class],
    $directory,
);
```

Generated methods — тонкие входные точки в тот же `ObjectPipeline` и parameter pipeline, который используется reflection resolution. Поэтому attribute composition, request mapping, lazy/proxy behavior, lifecycle hooks и contextual semantics `CurrentRequest`/`CurrentUri` не расходятся между dev и compiled paths.

Entries, которыми уже владеют explicit factories, services, invokables или protected container services, исключаются из AOT autowiring. Если cached compiled definitions содержат относительные shard filenames, передайте их directory как `baseDir` при построении container из cache.

## Исключения

Ошибки контейнера реализуют `Componenta\DI\Exception\ExceptionInterface`. Основные категории: некорректная dependency configuration, unresolved/cyclic entries, parameter-resolution errors, несовместимая attribute composition, request-data conflicts и некорректные cache/compiled artifacts.

Ошибки конфигурации по возможности обнаруживаются при построении, а не во время случайного production request.

## Лицензия

MIT.
