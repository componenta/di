# Componenta DI

[English version](README.md)

Componenta DI — PSR-11 контейнер внедрения зависимостей для PHP 8.4+. Он объединяет обычную конфигурацию контейнера, reflection-autowiring, DI-aware вызов callable, PHP-атрибуты, lazy-объекты, отображение PSR-7 request в аргументы/DTO и опциональную AOT-компиляцию фабрик.

Основной принцип контейнера: **обычное и скомпилированное production-разрешение используют один и тот же object/parameter pipeline**. Компиляция заменяет дорогую reflection-подготовку сгенерированными factory shards, но не создаёт отдельную семантику DI.

## Требования

- PHP 8.4 или новее;
- `psr/container` 2.x;
- реализация PSR-7 `ServerRequestInterface` нужна только при использовании HTTP request mapping.

## Установка

```bash
composer require componenta/di
```

## Базовая модель

У контейнера есть три основных способа выполнить разрешение:

- `get($id)` получает **shared entry** и кеширует его;
- `make($class, $params)` создаёт **новый объект** через тот же DI pipeline;
- `call($callable, $params)` разрешает аргументы callable и вызывает его.

В обычном приложении удобнее начать с configuration provider, получить `Config` и построить контейнер.

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

Конкретные классы, которые не зарегистрированы явно, обычно могут быть разрешены через reflection autowiring. Для интерфейсов и других абстрактных id нужен factory, alias, service, invokable mapping, внешний контейнер или другая явная привязка.

## Конфигурация

Componenta DI читает секцию `dependencies`, формируемую `componenta/config`. Для application/package-конфигурации рекомендуется наследоваться от `Componenta\Config\ConfigProvider`.

### Factories

Factory нужен, когда создание объекта содержит явную прикладную логику.

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

Runtime ABI фабрики:

```php
(ContainerValue $container, array $params): mixed
```

Callable может не объявлять один или оба параметра, если его PHP-сигнатура допускает передаваемые runtime-аргументы. Типизированные параметры фабрики валидируются при построении/материализации конфигурации, поэтому несовместимая сигнатура считается ошибкой конфигурации, а не случайной runtime-ошибкой при первом обращении.

Factory specification может быть обычным callable, callable service id, парой `[service, method]` или DI definition.

### Invokables

Invokable — конкретный класс, зарегистрированный как entry. Запись со строковым ключом одновременно создаёт alias.

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

Alias перенаправляет один id на другой.

```php
protected function getAliases(): array
{
    return [
        CacheInterface::class => RedisCache::class,
    ];
}
```

Цепочка aliases сводится к canonical id. Циклы и конфликтующие bindings отвергаются при построении контейнера.

### Services

Services — уже созданные значения, которые сразу помещаются в контейнер.

```php
protected function getServices(): array
{
    return [
        BuildInfo::class => new BuildInfo('production'),
    ];
}
```

Используйте services для значений, которые действительно должны существовать до контейнера. Для соединений, клиентов, потоков и других runtime-ресурсов обычно лучше factory.

### Delegators

Delegator декорирует entry после разрешения базового значения. Несколько delegators выполняются в порядке регистрации.

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

Runtime-сигнатура delegator:

```php
(mixed $entry, ContainerInterface $container): mixed
```

Контейнер отслеживает зависимости от deferred service-based delegators. Изменение alias, entry или владельца во внешнем контейнере инвалидирует затронутые декорированные значения, а не оставляет устаревший wrapper в кеше.

### Definitions

Definitions удобны, когда нужно декларативно задать constructor parameters, ссылки на другие entries или последовательность method calls.

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

Runtime-параметры, переданные в `make()`, имеют приоритет над configured constructor parameters.

## Получение объектов

### Shared entries через `get()`

```php
$orderService = $container->get(OrderService::class);
```

`get()` реализует PSR-11 семантику. Разрешённый entry является shared для данного контейнера и кешируется с учётом requested/canonical id.

### Новый объект через `make()`

```php
$exporter = $container->make(ReportExporter::class, [
    'format' => 'csv',
]);
```

`make()` всегда запускает новое object resolution. Явные параметры можно передавать по имени или позиции; runtime-значения по type id также используются, когда тип параметра их принимает.

### DI-aware callable

```php
$result = $container->call(
    [$controller, 'show'],
    ['id' => 42],
);
```

Аргументы callable разрешаются тем же parameter resolver pipeline, что и параметры конструктора. Это подходит для controller actions, setup methods, jobs и других прикладных callable.

### Runtime-изменения

Если композицию действительно нужно менять после build, контейнер предоставляет ограниченный mutable API:

```php
$container->set(FeatureFlags::class, $flags);
$container->alias(StorageInterface::class, S3Storage::class);
$container->delegator(StorageInterface::class, StorageTracingDelegator::class);
$container->addContainer($externalContainer);
```

Внутренние DI services защищены: их нельзя заменить или декорировать. При изменении binding соответствующие cached entries инвалидируются.

Внешние контейнеры проверяются до локального разрешения. Нельзя добавлять сам Componenta container как внешний контейнер.

## Autowiring и разрешение параметров

Параметры конструктора обычного конкретного класса проходят через упорядоченную цепочку resolvers. Чем выше priority, тем раньше resolver получает возможность разрешить параметр.

Стандартная цепочка:

| Priority | Назначение |
| ---: | --- |
| `ContainerBuilder::PRIORITY_PARAM_ATTRIBUTE` (1200) | composed parameter attributes |
| `PRIORITY_PARAM_ARRAY` (1100) | явно переданные значения |
| `PRIORITY_PARAM_ARRAY_TYPED` (1000) | переданные значения по объявленному типу |
| `PRIORITY_PARAM_REQUEST_CONTEXT` (800) | PSR-7 request context |
| `PRIORITY_PARAM_AUTOWIRE` (300) | разрешение class/interface по типу |
| `PRIORITY_PARAM_DEFAULT_VALUE` (200) | PHP default value |
| `PRIORITY_PARAM_NULLABLE` (100) | `null` для неразрешённого nullable-параметра |

Variadic и by-reference параметры не входят в контракт DI parameter resolution.

## Встроенные атрибуты

Сначала атрибуты компонуются в детерминированный plan, затем выполняются. На одном параметре допускается один совместимый value provider и подходящие transformers; class-level creation/lifecycle attributes подчиняются своим capability rules.

### Источники значений

`#[Config]` получает значение из application configuration:

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

Обычная строка в `#[Config]` — literal config key. Для обхода вложенной структуры по точкам используйте `ConfigPath`.

`#[Env]` получает исходное значение environment variable:

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

`#[EntryId]` получает значение по явному container id:

```php
use Componenta\DI\Attribute\EntryId;

public function __construct(
    #[EntryId('mailer.transactional')]
    private MailerInterface $mailer,
) {}
```

`#[CurrentUser]` получает текущего пользователя из `CurrentUserProviderInterface`. Default provider изолирует user state по Fiber, поэтому конкурентные Fiber-контексты не перезаписывают друг друга.

```php
use Componenta\DI\Attribute\CurrentUser;

public function __construct(
    #[CurrentUser(User::class)]
    private User $user,
) {}
```

Приложение может зарегистрировать собственную реализацию `CurrentUserProviderInterface`, связанную с его request/session lifecycle.

`#[Make]` создаёт новый объект вместо получения shared entry:

```php
use Componenta\DI\Attribute\Make;

public function __construct(
    #[Make(JobContext::class, ['queue' => 'emails'])]
    private JobContext $context,
) {}
```

`#[Init]` вычисляет значение через DI-aware callable:

```php
use Componenta\DI\Attribute\Init;

public function __construct(
    #[Init([LocaleFactory::class, 'current'])]
    private Locale $locale,
) {}
```

### Преобразование через `#[Cast]`

`#[Cast]` выполняется после совместимого value provider и передаёт значение named caster, зарегистрированному в `componenta/caster`.

```php
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Env;

public function __construct(
    #[Env('PAGE_SIZE')]
    #[Cast('registered-caster-name')]
    private int $pageSize,
) {}
```

Имя caster — имя регистрации в используемом caster provider. DI не содержит project-specific каталога имён caster-ов.

### Property injection

`#[Inject]` внедряет property из контейнера по объявленному типу.

```php
use Componenta\DI\Attribute\Inject;

final class Handler
{
    #[Inject]
    private LoggerInterface $logger;
}
```

Property handler сначала резервирует property для записи. Static properties отвергаются, уже инициализированные readonly properties не перезаписываются.

`#[Config]`, `#[Env]`, `#[EntryId]`, `#[CurrentUser]`, `#[Make]` и `#[Init]` также могут применяться к properties, если это разрешено объявлением соответствующего атрибута.

### Lifecycle объекта

`#[SetUp]` вызывает метод после создания объекта. Атрибут repeatable, а параметры setup method проходят стандартный DI resolver pipeline.

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

`#[NoConstructor]` создаёт объект без вызова конструктора, после чего обычный pipeline продолжает property injection и setup processing. Используйте его только когда обход конструктора является сознательной частью модели объекта.

### Lazy objects и virtual proxies

`#[Lazy]` использует native lazy ghost PHP 8.4 для autowired-класса. Объект сохраняет настоящую class identity и инициализирует состояние при первом наблюдаемом обращении.

```php
use Componenta\DI\Attribute\Lazy;

#[Lazy]
final class ExpensiveCatalog
{
    public function __construct(DatabaseConnection $db) {}
}
```

Opaque factory-bound service нельзя автоматически превратить в ghost: DI не владеет его constructor path. Для такого entry используйте `#[Proxy]` или реализуйте `LazyServiceFactoryInterface` в factory.

`#[Proxy]` выбирает native virtual-proxy creation и может использоваться на классе либо injection point.

Тот же API доступен напрямую:

```php
$lazy = $container->makeLazy(ExpensiveCatalog::class, $initializer);
$proxy = $container->makeProxy(RemoteClient::class, $factory);
```

## Значения из PSR-7 request

Request attributes работают, когда текущий `ServerRequestInterface` передан в explicit parameter array по id самого интерфейса.

```php
use Psr\Http\Message\ServerRequestInterface;

$container->call([$action, '__invoke'], [
    ServerRequestInterface::class => $request,
]);
```

### Получение одного значения

Для одного аргумента из одного request source используются:

- `#[QueryParam]` — query string;
- `#[PayloadParam]` — parsed body; `ConfigPath` позволяет выбрать вложенный путь;
- `#[Header]` — header;
- `#[Cookie]` — cookie;
- `#[RequestAttribute]` — PSR-7 request attribute;
- `#[ServerParam]` — server parameter;
- `#[UploadedFile]` — uploaded file.

Если extractor поддерживает `name` и оно не указано, используется имя PHP-параметра. Отсутствие обязательного значения завершает resolution ошибкой, если у атрибута нет default. Extractor с параметром `cast` использует configured caster provider.

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

### Mapping в array или DTO

Семейство `Map*` отображает целый источник request data:

- `MapRequestPayload`;
- `MapQueryString`;
- `MapHeaders`;
- `MapCookies`;
- `MapRequestAttributes`;
- `MapServerParams`;
- `MapUploadedFiles`.

`MapRequest` позволяет явно объединить несколько источников.

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

Если parameter имеет тип `array`, mapper возвращает transformed array. Если parameter содержит ровно один class type, DI создаёт DTO через `FactoryInterface::make()`, поэтому его constructor продолжает использовать обычный resolver pipeline.

По умолчанию `MapRequest` запрещает конфликтующие значения из разных sources. Если ожидаема модель «первый источник побеждает», её нужно явно выбрать через `RequestDataConflictPolicy::FirstWins`.

Порядок request mapping намеренно фиксирован:

1. извлечение и объединение request data;
2. валидация извлечённых данных DTO, если доступен `ValidationProviderInterface`;
3. mapper transformations (`map`, casts, defaults, sort mapping, exclusions);
4. создание typed DTO.

Таким образом validation работает с исходными transport/source data до mapper transformations. Нормализация и object construction остаются отдельными этапами.

## Собственный parameter resolver

Реализуйте `ParameterResolverInterface`, если у приложения есть источник параметров, который должен участвовать в обычном разрешении constructors и callable.

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

Больший priority выполняется раньше. Resolver возвращает `null`, чтобы продолжить цепочку, либо `[позиция параметра, значение]`. DI проверяет и позицию, и соответствие значения объявленному type.

`supports()` выполняет только классификацию target: он не должен мутировать resolver chain или сохранять per-resolution mutable state.

Resolver specification может быть:

- экземпляром `ParameterResolverInterface`;
- service id/class, разрешаемым контейнером;
- callable factory, получающим container;
- парой `[service id, method]`.

Возвращайте `true` из `shouldReplaceParameterResolvers()` только если намеренно заменяете **всю** стандартную resolver chain собственной.

## Собственный DI-атрибут

Пользовательский атрибут регистрируется как `AttributeDefinition`. Definition описывает **семантическую роль атрибута**, **handler**, который его выполняет, и **правила композиции** с другими атрибутами.

Для parameter-only атрибута реализуйте `ParameterAttributeHandlerInterface`.

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

Регистрация semantic definition:

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

Для class/property/method атрибута используется `AttributeHandlerInterface`. Handler, который осознанно поддерживает обе поверхности, реализует оба интерфейса.

### Attribute capabilities

Capabilities описывают роль, а не конкретное имя атрибута:

- `ValueProvider` — предоставляет parameter/property value; по умолчанию не более одного на target;
- `AuthoritativeValueProvider` — value provider, который generic caller parameters не могут перекрыть;
- `ValueTransformer` — преобразует уже полученное значение; несколько transformers могут композироваться;
- `CreationStrategy` — выбирает способ создания объекта, например lazy/proxy; по умолчанию не более одного;
- `ConstructorPolicy` — меняет поведение конструктора; по умолчанию не более одного;
- `LifecycleHook` — lifecycle hook; несколько hooks могут композироваться.

`AttributeDefinition` дополнительно позволяет задавать:

- `requires` — обязательный соседний attribute/capability;
- `forbids` — несовместимый attribute/capability;
- `before` / `after` — детерминированный порядок;
- `rules` — пользовательские `AttributeCompositionRuleInterface` проверки;
- `phase` — `BeforeInstantiation`, `AfterInstantiation` или `Both`.

Selectors в `requires`, `forbids`, `before` и `after` могут ссылаться либо на class атрибута, либо на class, реализующий `AttributeCapabilityInterface`.

Cardinality пользовательской capability можно задать через `CapabilityPolicy`:

```php
use Componenta\DI\Attribute\Composition\CapabilityPolicy;

protected function getAttributeCapabilities(): array
{
    return [
        new CapabilityPolicy(MyCapability::class, maxPerTarget: 1),
    ];
}
```

`shouldReplaceAttributeDefinitions() === true` следует использовать только когда приложение намеренно удаляет все встроенные attribute definitions и полностью определяет модель атрибутов самостоятельно.

## Программная настройка через ContainerBuilder

Configuration providers — основной application-facing API, но контейнер можно собрать и программно:

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

Если конфигурация собирается пакетами через `componenta/config`, предпочтительнее `ContainerBuilder::configure($config)`.

## Persistent DI cache

`DiCacheGenerator` валидирует dependency configuration и сериализует её в PHP cache artifact. Запись выполняется во временный файл, файл проходит PHP syntax check и только после этого атомарно активируется.

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

$builder = ContainerBuilder::configure($config);
$dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
$cacheFile = __DIR__ . '/var/cache/di.php';

(new DiCacheGenerator())->generate($dependencies, $cacheFile);
```

Production загрузка cache выполняется явно:

```php
$cacheFile = __DIR__ . '/var/cache/di.php';
$cache = require $cacheFile;

$container = ContainerBuilder::configureFromCache(
    $config,
    $cache,
    baseDir: dirname($cacheFile),
)->build();
```

Cache envelope содержит format version и валидируется до использования. Generated DI cache — build artifact, а не конфигурационный файл для ручного редактирования.

## AOT compiled factory shards

Для reflection-autowired application roots `compileFactories()` генерирует content-addressed PHP shards. Сгенерированные методы являются тонкими entry points в тот же `ObjectPipeline`, который используется reflection resolution, поэтому attribute/lifecycle/request/lazy/parameter semantics не расходятся.

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

Compiler расширяет граф eligible autowired dependencies от заданных roots и исключает entries, которыми уже владеют explicit factories, services, invokables или protected container services. Имена shard-файлов content-addressed и повторно проверяются на runtime boundary.

Если cached compiled definitions содержат относительные имена shard-файлов, при `configureFromCache()` передайте каталог shards как `baseDir`.

## Исключения

Ошибки, принадлежащие контейнеру, реализуют `Componenta\DI\Exception\ExceptionInterface`. PSR-11 lookup failures нормализуются в package `NotFoundException`/resolution exceptions, а configuration и compilation failures имеют отдельные типы.

Основные категории ошибок:

- неверная или конфликтующая dependency configuration;
- отсутствующие/cyclic entries;
- ошибки parameter resolution;
- несовместимая attribute composition;
- request-data conflicts и ошибки mapping;
- неверные cache/compiled-factory artifacts.

Container намеренно завершает build как можно раньше при ошибке конфигурации, чтобы неверный binding не доживал до случайного production request.

## Рекомендуемая структура приложения

Для небольшого приложения достаточно:

```text
src/
  ConfigProvider.php
  ... application classes ...
var/
  cache/
```

Bindings конкретного пакета держите в provider этого пакета, затем компонуйте providers на уровне приложения. DI/cache artifacts генерируйте на build/deploy этапе. Прикладные классы обычно должны зависеть от интерфейсов и constructor injection, а не обращаться к контейнеру напрямую.

## Лицензия

MIT.
