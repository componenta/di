# Componenta DI

`componenta/di` — PSR-11 DI-контейнер для PHP 8.4+ с autowiring, декларативной конфигурацией, композицией атрибутов, lazy objects и AOT-компиляцией factories.

DI v5 использует `componenta/config` v3 как configuration runtime. В контейнер регистрируются те же экземпляры `Config` и `Environment`, а runtime и compiled-cache пути используют одну нормализованную dependency schema.

## Установка

```bash
composer require componenta/di
```

Требования:

- PHP 8.4+
- `componenta/config` 3.x
- PSR-11 2.x

## Быстрый старт

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

`Container::create()` делегирует создание в `ContainerBuilder::configure($config)->build()`.

Контейнер содержит те же runtime snapshots:

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;

$container->get(Config::class) === $config;
$container->get(Environment::class) === $config->environment;
```

## Конфигурация

DI v5 потребляет секцию `dependencies`, определённую в `componenta/config` v3. Необходимо наследовать `Componenta\Config\ConfigProvider` и использовать актуальные hooks:

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

Config v3 отвечает за детерминированную композицию providers и structural validation. DI v5 отвечает за semantic validation и canonicalization factories, aliases, invokables, delegators, resolver specifications и attribute definitions.

### Services

```php
protected function getServices(): array
{
    return [
        ClockInterface::class => new SystemClock(),
    ];
}
```

### Invokables и aliases

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

Keyed invokable в DI v5 canonicalize-ится в invokable class плюс alias.

### Factories

Factories валидируются при нормализации конфигурации. Callable factory должен соответствовать v5 factory contract и получает runtime context, определённый пакетом.

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

Каждый service id соответствует pipeline-list. Callable pair является одним вложенным элементом pipeline:

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

Прямая pair `[MetricsDelegator::class, 'decorate']` не является pipeline и отклоняется config v3 при композиции.

### Parameter resolvers

Integer keys resolver map являются semantic priorities и не переиндексируются при композиции providers:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => CustomParameterResolver::class,
    ];
}
```

`shouldReplaceParameterResolvers()` имеет tri-state семантику: `true`/`false` — explicit value, `null` — provider не изменяет уже составленное значение.

То же правило действует для `shouldReplaceAttributeDefinitions()`.

## Runtime environment

Environment является runtime state и не входит в DI cache. В application config можно использовать runtime-bound descriptor из config v3:

```php
use function Componenta\Config\env;

return [
    'api.token' => env('API_TOKEN'),
];
```

Значение разрешается через `Config::$environment` при чтении config key. `env()` не переносит build-time secret в persistent application-config cache.

DI-атрибуты, читающие environment, используют тот же экземпляр `Environment`, зарегистрированный в контейнере.

## Container API

`Container` реализует:

- `Psr\Container\ContainerInterface`
- `Componenta\DI\FactoryInterface`
- `Componenta\DI\CallableExecutorInterface`
- `Componenta\DI\ProxyFactoryInterface`

Базовое разрешение:

```php
$service = $container->get(Service::class);
$exists = $container->has(Service::class);
$fresh = $container->make(Service::class);
```

`get()` — shared/cached resolution. `make()` — fresh-resolution API для создания нового object graph.

Aliases canonicalize-ятся до resolution. Circular и concurrent resolution paths завершаются DI-исключениями, а не игнорируются.

## Autowiring

Concrete class может быть разрешён reflection-механизмом, если explicit binding отсутствует. Constructor parameters проходят через единый parameter resolver pipeline.

Built-in priorities:

```text
1200  parameter attributes
1100  array resolver
1000  typed array resolver
 300  autowire by type
 200  default value
 100  nullable fallback
```

Custom resolvers можно добавить или полностью заменить через config/`ContainerBuilder`.

## Attributes

DI v5 композирует атрибуты через capabilities, а не через набор специальных pairwise правил.

Built-in capabilities:

- value providers;
- authoritative value providers;
- value transformers;
- creation strategies;
- constructor policies;
- lifecycle hooks.

Основные built-in attributes:

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

Parameter attributes выполняются через parameter resolver pipeline. Class/property/method lifecycle поведение идёт через attribute composition pipeline.

## `#[SetUp]`

`#[SetUp]` выполняет lifecycle methods после создания объекта и разрешает value descriptors через текущие container/config/runtime состояния.

Built-in setup values поддерживают DI entry references, config references, environment descriptors, entry ids и explicit lazy/config wrappers из `componenta/config`.

## Request mapping

Request attributes поддерживают scalar extraction и object mapping из PSR-7 request. Источники включают query parameters, payload, headers, cookies, request attributes, server params и uploaded files.

Конфликты request sources выявляются явно. Mapped values могут проходить через lazy casting и validation providers при сохранении одинаковой семантики runtime и compiled modes.

## DI cache

`DiCacheGenerator` хранит только нормализованный DI dependency graph. Application `Config` и его `Environment` snapshot в DI cache не сериализуются.

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

$dependencies = $config->get(ConfigKey::DEPENDENCIES, []);
(new DiCacheGenerator())->generate($dependencies, 'var/cache/di.php');
```

В runtime application config загружается с текущим environment, после чего подключается DI cache:

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

`ContainerBuilder` добавляет normalized dependencies в новый runtime `Config`, сохраняя **тот же** объект `Environment`. Поэтому provider и cache modes сходятся к одной runtime config-модели.

DI cache имеет независимую версию `ContainerBuilder::CACHE_VERSION`. Unknown keys и stale versions отклоняются fail-fast.

## AOT factories

`ContainerBuilder::compileFactories()` может генерировать factory shards для discovered autowire entries. Runtime loading проверяет compiled artifacts и не должен молча менять object semantics при stale/invalid artifact.

## External containers

Built container может разрешать entries из внешних PSR-11 containers. External lookup участвует в cycle protection, поэтому cross-container recursion не может уйти в бесконечный цикл.

## Exceptions

Основные исключения:

- `InvalidConfigurationException`
- `ResolutionException`
- `NotFoundException`
- `CircularDependencyException`
- `ConcurrentResolutionException`
- `DelegatorException`
- `CompilationException`

`has()` намеренно является non-throwing API и возвращает `false`, если entry нельзя безопасно разрешить.

## Проверки разработки

```bash
composer check
```

В test suite есть explicit runtime/AOT/cache parity tests для object creation, attributes, request mapping, parameter resolution, callable handling и compiled factories.
