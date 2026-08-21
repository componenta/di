# Componenta DI

`componenta/di` — PSR-11 DI-контейнер для PHP 8.4+ с autowiring, декларативной конфигурацией, композицией атрибутов, lazy objects и AOT-компиляцией factories.

DI v5 использует `componenta/config` v3 как configuration runtime. `ContainerBuilder` создаёт финальный runtime `Config` с нормализованными DI dependencies, сохраняя **тот же объект `Environment`**, который передало приложение. Runtime и DI-cache пути используют одну dependency schema.

## Установка

```bash
composer require componenta/di
```

Требования: PHP 8.4+, `componenta/config` 3.x и PSR-11 2.x.

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

Контейнер содержит финальный normalized config и тот же runtime environment:

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;

$runtimeConfig = $container->get(Config::class);

$runtimeConfig instanceof Config; // true
$runtimeConfig->environment === $environment; // true
$container->get(Environment::class) === $environment; // true
```

Финальный `Config` может быть другим объектом относительно входного: DI v5 добавляет в него нормализованный dependency graph.

## Конфигурация

DI v5 потребляет `dependencies` schema из `componenta/config` v3. Необходимо наследовать `Componenta\Config\ConfigProvider` и использовать актуальные hooks:

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

Config v3 отвечает за детерминированную композицию providers и structural validation. DI v5 отвечает за semantic validation и canonicalization значений внутри этих sections.

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

Keyed invokable canonicalize-ится в invokable class плюс alias.

### Factories

Runtime factory ABI — `(ContainerValue, array)`. Пользовательский callable может объявлять любую совместимую prefix/signature, которую принимает validator.

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

Невалидная factory signature отклоняется при проверке конфигурации, а не при первом resolve.

### Delegators

Каждый service id соответствует pipeline-list. Callable pair — один вложенный элемент pipeline:

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

Прямая pair `[MetricsDelegator::class, 'decorate']` не является однозначным pipeline и отклоняется config v3.

### Parameter resolvers

Integer keys являются semantic priorities и сохраняются при provider composition:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => CustomParameterResolver::class,
    ];
}
```

`shouldReplaceParameterResolvers()` и `shouldReplaceAttributeDefinitions()` имеют tri-state семантику: `true`/`false` — explicit value, `null` не изменяет ранее составленное значение.

## Runtime environment

Environment — runtime state и не хранится в DI cache. Application config может использовать runtime-bound descriptor из config v3:

```php
use function Componenta\Config\env;

return [
    'api.token' => env('API_TOKEN'),
];
```

Descriptor разрешается через `Config::$environment` при чтении. DI `#[Env]` использует тот же объект `Environment`, зарегистрированный в контейнере.

## Container API

`Container` реализует `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface` и `ProxyFactoryInterface`.

```php
$shared = $container->get(Service::class);
$exists = $container->has(Service::class);
$fresh = $container->make(Service::class);
```

`get()` выполняет shared/cached resolution. `make()` выполняет fresh resolution. Aliases canonicalize-ятся до resolution, circular/concurrent paths завершаются явными исключениями.

## Autowiring и parameter resolution

Concrete class может быть reflection-resolved при отсутствии explicit binding. Constructor parameters проходят через единый priority pipeline:

```text
1200  parameter attributes
1100  array resolver
1000  typed array resolver
 300  autowire by type
 200  default value
 100  nullable fallback
```

Custom resolvers могут расширить или заменить этот pipeline.

## Attributes

DI v5 композирует attributes через capabilities: value providers, authoritative value providers, value transformers, creation strategies, constructor policies и lifecycle hooks.

Built-in attributes:

```text
#[Config] #[Env] #[EntryId] #[Inject] #[Make]
#[Lazy] #[Proxy] #[NoConstructor] #[Init] #[SetUp] #[Cast]
#[CurrentRequest] #[CurrentUri] #[CurrentUser]
#[Header] #[Cookie] #[QueryParam] #[PayloadParam]
#[RequestAttribute] #[ServerParam] #[UploadedFile]
#[MapRequest] #[MapQueryString] #[MapRequestPayload]
#[MapHeaders] #[MapCookies] #[MapRequestAttributes]
#[MapServerParams] #[MapUploadedFiles]
```

Parameter attributes выполняются через parameter resolver pipeline. Object lifecycle поведение выполняется через attribute composition pipeline.

## `#[SetUp]`

`#[SetUp]` выполняет lifecycle methods после создания объекта. Его аргументы могут разрешаться через текущие container, config, environment и entry id встроенными setup unwrappers.

## Request mapping

Request attributes поддерживают scalar extraction и object mapping из PSR-7 request. Источники: query parameters, payload, headers, cookies, request attributes, server parameters и uploaded files. Source conflicts выявляются явно; mapped values могут использовать lazy casting и validation providers.

## Persistent caches

Application config и DI graph имеют раздельную ответственность:

- `ConfigLoader` кэширует application/package config data и runtime-bound config descriptors;
- `DiCacheGenerator` кэширует только normalized DI dependencies;
- ни один cache не сериализует runtime объект `Environment`.

Создание DI cache:

```php
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;

$dependencies = $config->get(ConfigKey::DEPENDENCIES, []);
(new DiCacheGenerator())->generate($dependencies, 'var/cache/di.php');
```

Runtime-загрузка обоих cache с текущим environment:

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

Provider mode и cache mode создают финальный normalized runtime `Config`, сохраняя переданный объект `Environment`. DI cache имеет независимую версию `ContainerBuilder::CACHE_VERSION`; stale/malformed cache отклоняется fail-fast.

## AOT factories

`ContainerBuilder::compileFactories()` генерирует factory shards для выбранных autowire entries. Compiled artifacts используют те же object/attribute/parameter semantics, что и runtime reflection, и покрыты parity tests.

## External containers

Built container может разрешать entries из внешних PSR-11 containers. External lookup участвует в cycle protection.

## Exceptions

Основные ошибки представлены `InvalidConfigurationException`, `ResolutionException`, `NotFoundException`, `CircularDependencyException`, `ConcurrentResolutionException`, `DelegatorException` и `CompilationException`.

`has()` намеренно non-throwing и возвращает `false`, если entry нельзя безопасно разрешить.

## Проверки разработки

```bash
composer check
```

Test suite содержит runtime/AOT/cache parity coverage для object creation, attributes, request mapping, parameter resolution, callables и compiled factories.
