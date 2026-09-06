# Componenta DI

Componenta DI — контейнер внедрения зависимостей для PHP 8.4+, совместимый с PSR-11. Пакет поддерживает автосвязывание через reflection, явные фабрики и сервисы, упорядоченные делегаторы, вызов функций с разрешением аргументов, ленивые объекты, отображение HTTP-запросов и композицию PHP-атрибутов.

Пакет отвечает за создание контейнера и семантику разрешения зависимостей. [`componenta/config`](../config/README.ru.md) вызывает провайдеры и создаёт два раздельных значения:

- `Config` содержит только конфигурацию приложения;
- `DependencyDefinitions` передаётся один раз в `ContainerFactory` и не публикуется как сервис контейнера.

Во всех окружениях действует один путь создания контейнера. Componenta DI не компилирует фабрики и не сохраняет сериализованный граф зависимостей.

## Установка

```bash
composer require componenta/di
```

Требуются PHP 8.4+, Componenta Config 3.x, Componenta Caster, Componenta Reflection, Componenta Validation, интерфейсы PSR-11 2.x и PSR-7.

## Основной поток

```text
Environment + упорядоченные провайдеры
    -> ConfigFactory::create()
    -> ConfigComposition { Config, DependencyDefinitions }
    -> ContainerFactory::create()
    -> ContainerValue { container, тот же Config }
```

`ContainerFactory` — единственная публичная фабрика контейнера. Она сохраняет переданные экземпляры `Config` и `Environment` без копирования.

## Быстрый старт

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
        return ['greeting.prefix' => 'Привет'];
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
$fresh = $container->make(Greeting::class, ['prefix' => 'Добро пожаловать']);

assert($shared->message === 'Привет!');
assert($fresh->message === 'Добро пожаловать!');
assert($containerValue->config === $composition->config);
assert($container->get(Environment::class) === $environment);
```

## Слияние провайдеров

`ConfigFactory` вызывает провайдеры в порядке аргументов и объединяет секции зависимостей до передачи результата в DI:

- factories, aliases, services и parameter resolvers являются реестрами по ключу; позднее значение заменяет раннее с тем же ключом;
- numeric invokables дописываются, а keyed invokables заменяются по ключу;
- цепочки delegators, attribute definitions и attribute capabilities дописываются в порядке провайдеров;
- флаги замены resolver-ов и определений атрибутов используют последнее явно переданное логическое значение.

DI один раз проверяет и нормализует объединённый `DependencyDefinitions` внутри `ContainerFactory::create()`. Пакет не вызывает провайдеры и не копирует определения зависимостей в `Config`.

`ConfigProvider` предоставляет защищённые методы:

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

## Определения зависимостей

### Сервисы

Готовые сервисы являются значениями времени выполнения и сохраняют состояние и идентичность. Допустимы объекты, замыкания, ресурсы, `null` и `false`.

```php
protected function getServices(): array
{
    return [
        ClockInterface::class => new SystemClock(),
        'feature.enabled' => false,
    ];
}
```

### Фабрики

Полная сигнатура фабрики времени выполнения — `(ContainerValue $context, array $params)`. Функция может объявить совместимый префикс этих аргументов. Ошибочная сигнатура отклоняется во время построения контейнера.

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

`ClassDefinition` описывает аргументы конструктора, значения свойств и вызовы методов настройки, когда обычной функции недостаточно.

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

Invokable с ключом регистрирует конкретный класс и alias.

### Delegators

Делегатор получает текущее значение сервиса и при необходимости контейнер. Делегаторы выполняются в порядке провайдеров и регистрации.

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

Отложенная пара «сервис–метод», например `['tracing.decorator', 'decorate']`, должна быть вложена в список цепочки. Это отличает её от цепочки из двух строковых делегаторов.

## API контейнера

`Container` реализует `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface` и `ProxyFactoryInterface`.

```php
$shared = $container->get(Service::class);                 // общий результат
$exists = $container->has(Service::class);                 // не выбрасывает исключение
$fresh = $container->make(Service::class, ['id' => 42]);   // новый объект
$result = $container->call([$controller, 'show'], ['id' => 42]);
```

Если явная привязка отсутствует, конкретный класс создаётся через reflection. Явная фабрика имеет приоритет.

Методы изменения контейнера во время выполнения `set()`, `alias()`, `delegator()` и `addContainer()` сохраняются. Они инвалидируют затронутые общие значения и результаты делегаторов без полного перестроения контейнера. Нельзя заменить или затенить основные сервисы DI, `Config`, `Environment`, `ContainerValue` и `DependencyDefinitions`.

Если фабрика или делегатор меняет привязку во время уже начатого `get()` (в том числе при приостановленном Fiber), этот вызов может завершиться с прежним значением. Последующие обращения используют изменённую привязку: старый вызов не восстанавливает инвалидированные записи кеша.

## Атрибуты и отображение запросов

Встроенные атрибуты объектов и параметров:

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

`#[CurrentRequest]` и `#[CurrentUri]` — явные источники, допустимые только во время вызова. Тип PSR-7 request или URI сам по себе не означает «текущий запрос», а такое значение нельзя сохранить через аргумент конструктора.

Отображение запроса читает явно выбранные источники PSR-7, обнаруживает конфликты, применяет настроенные преобразования и проверку данных, затем создаёт DTO через тот же `FactoryInterface::make()`.

## Точки расширения

Пользовательские resolver-ы параметров реализуют `ParameterResolverInterface` и регистрируются с целочисленным приоритетом. Пользовательские атрибуты описываются через `AttributeDefinition`; `CapabilityPolicy` задаёт ограничения количества и порядок композиции. Расширения передаются в `DependencyDefinitions` до построения контейнера: после этого цепочки параметров и атрибутов фиксируются.

Фабрики расширений могут получать обычные зависимости из существующего контейнера. Сначала в порядке регистрации создаются определения атрибутов, затем резолверы параметров. Необходимое расширение атрибута должно быть зарегистрировано раньше фабрики, которая его использует. Если зависимость уже создана с неполной семантикой атрибутов или поздний резолвер мог бы перехватить разрешённый параметр раньше выбранного резолвера, `ContainerFactory::create()` выбрасывает `InvalidConfigurationException`. Проверка использует метаданные атрибутов и классификацию `supports()`, не повторяя конструкторы, обработчики и получение значений. Несвязанные расширения и резолверы с более низким приоритетом допустимы. Зависимость, которой нужен пользовательский резолвер параметров, можно получать в фабрике следующего резолвера либо отложить её получение до завершения создания контейнера.

Выборки `AttributePlan::all()` и `attributes()` сохраняют порядок общего плана, включая наследуемые capabilities и чередование повторяющихся классов атрибутов.

Правила композиции получают метаданные `AttributeUsage`, доступные только для чтения: объявленный класс и аргументы атрибута, его семантическое определение, место применения и порядок объявления. При каждом чтении `arguments` объявленные выражения вычисляются заново; объектные аргументы не сохраняются в общем плане. Поэтому выражение `new` может вызвать конструктор, когда правило явно читает аргументы. Правила проверяют ограничения объявления; проверки, зависящие от изменяющегося состояния приложения, выполняются в обработчиках. Фреймворк создаёт новый экземпляр атрибута только перед фактическим вызовом обработчика.

Внешний PSR-11 контейнер можно добавить через `Container::addContainer()`. Внешние сервисы участвуют в защите от циклов и не могут затенять защищённые основные сервисы.

## Поведение во время выполнения

`get()` кеширует общий результат вместе с результатом цепочки делегаторов. `make()` создаёт новый объект и принимает параметры времени выполнения. Метаданные reflection, подготовленные планы параметров, пути aliases и выбранные resolver-ы кешируются только в памяти одного контейнера.

Разработка и боевое окружение используют один `ContainerFactory`, одну нормализацию, проверку и цепочку разрешения. Значения зависимостей времени выполнения не экспортируются, поэтому несериализуемые значения ведут себя одинаково в обоих окружениях.

## Исключения

Ошибки пакета реализуют `Componenta\DI\Exception\ExceptionInterface`, который также расширяет контракт исключений контейнера PSR-11. Основные типы: `InvalidConfigurationException`, `NotFoundException`, `ResolutionException`, `CircularDependencyException`, `ConcurrentResolutionException`, `DelegatorException` и `AttributeCompositionException`.

После того как DI разрешил явно переданную функцию и передал ей управление, исключения из тела этой функции распространяются без изменения.
