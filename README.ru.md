# Componenta DI

PSR-11-контейнер внедрения зависимостей для PHP 8.4+. Библиотека предоставляет кеширование общих сервисов, автоматическую сборку через рефлексию, создание новых объектов, вызов функций и методов с разрешением аргументов, внедрение параметров и свойств через атрибуты, отображение PSR-7-запросов в DTO, нативные ленивые объекты, виртуальные прокси, псевдонимы, делегаторы, подключение внешних контейнеров и сгенерированные резолверы сервисов.

**[English](README.md)** | **[Русский](README.ru.md)**

## Граница ответственности

`componenta/di` отвечает за разрешение зависимостей во время выполнения. Библиотека не сканирует приложение и не выбирает его поставщиков конфигурации. Поиск классов, компиляция конфигурации, подготовка файлов кеша при развертывании и запуск приложения относятся к прикладному слою, обычно к `componenta/app`.

Свойства заполняются только через атрибуты и их обработчики. Предварительная компиляция создаёт обычные фабрики; для динамических классов сохраняется сборка через рефлексию.

## Установка

```bash
composer require componenta/di
```

Требуется PHP 8.4 или новее.

| Пакет | Назначение |
|---|---|
| `psr/container` | Контракты PSR-11. |
| `psr/http-message` | PSR-7-запросы, HTTP-атрибуты и отображение в DTO. |
| `componenta/config` | Конфигурация, переменные окружения и `ContainerValue` для фабрик. |
| `componenta/caster` | `#[Cast]` и преобразование значений запроса. |
| `componenta/validation` | Необязательная проверка DTO запроса. |
| `componenta/reflection` | Работа с рефлексией и ленивыми объектами PHP 8.4. |
| `componenta/priority-list` | Регистрация резолверов параметров по приоритету. |
| `componenta/var-export` | Создание PHP-файлов кеша конфигурации. |

## Основное поведение

- `get(string $id)` возвращает общий закешированный сервис.
- `make(string $entry, array $params = [])` всегда создает новый объект и не использует кеш сервисов.
- `call(mixed $callable, array $params = [])` разрешает недостающие аргументы и вызывает функцию или метод.
- Аргументы конструктора и вызываемого метода можно передавать в `$params` по имени или позиции.
- `make(Target::class, ['value' => 'provided'])` передает `value` параметру конструктора или метода настройки. Обычное публичное свойство с таким именем не записывается.
- Свойства с атрибутами обрабатываются отдельной цепочкой обработчиков атрибутов.

## Быстрый старт

```php
use App\Logging\FileLogger;
use App\Logging\LoggerInterface;
use App\Service\UserService;
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger('/var/log/app.log'))
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$logger = $container->get('logger');
$first = $container->make(UserService::class, ['userId' => 7]);
$second = $container->make(UserService::class, ['userId' => 7]);

assert($first !== $second);
```

Если для идентификатора нет явной привязки, резолвер на основе рефлексии может собрать подходящий класс, когда все параметры его конструктора разрешимы.

## Публичные контракты

Имена параметров входят в публичный контракт: PHP позволяет передавать именованные аргументы.

| Контракт | Сигнатура | Назначение |
|---|---|---|
| `Psr\Container\ContainerInterface` | `get(string $id)`, `has(string $id)` | Получение общих сервисов. |
| `FactoryInterface` | `make(string $entry, array $params = [])` | Создание нового объекта. |
| `CallableInvokerInterface` | `call(mixed $callable, array $params = [])` | Вызов с разрешением аргументов. |
| `CallableResolverInterface` | `resolve(mixed $callable)` | Преобразование описания вызова в `callable`. |
| `CallableExecutorInterface` | `resolve(...)` и `call(...)` | Обе возможности работы с вызовами. |
| `LazyObjectFactoryInterface` | `makeLazy(string $class, callable $initializer)` | Создание нативного ленивого объекта. |
| `VirtualProxyFactoryInterface` | `makeProxy(string $class, callable $factory)` | Создание виртуального прокси. |
| `ProxyFactoryInterface` | оба ленивых метода | Общий контракт двух способов ленивой загрузки. |
| `AliasResolverInterface` | `resolve`, `set`, `has` | Низкоуровневая работа с псевдонимами. |

Конкретный `Container` также предоставляет `set()`, `alias()`, `delegator()` и `addContainer()` для кода начальной настройки. Обычному сервису следует передавать самый узкий подходящий интерфейс.

## Порядок разрешения

`Container::get($id)` работает так:

1. Возвращает уже закешированный декорированный результат для запрошенного идентификатора.
2. Преобразует псевдоним в канонический идентификатор.
3. Включает защиту от циклической зависимости.
4. Возвращает локальный базовый сервис, если он уже есть в кеше.
5. Если локального сервиса нет, обращается к зарегистрированным внешним PSR-11-контейнерам.
6. Если внешний контейнер не владеет идентификатором, запускает локальную цепочку резолверов и кеширует базовый результат.
7. Применяет делегаторы и кеширует декорированный результат.

Локальные сервисы имеют приоритет над внешними контейнерами. `has()` преобразует в `false` только ошибки контейнера; ошибки программирования внутри резолвера не скрываются.

`make()` разрешает псевдонимы, но намеренно пропускает кеш сервисов, внешние контейнеры и делегаторы. Результат обязан быть объектом.

## ContainerBuilder

`ContainerBuilder` — основной способ собрать контейнер.

| Метод | Действие |
|---|---|
| `addFactory(string $id, callable $factory)` | Регистрирует фабрику. |
| `addFactories(array $factories)` | Регистрирует фабрики списком. |
| `addInvokable(string $classOrAlias, ?string $class = null)` | Регистрирует класс; форма с двумя аргументами также создает псевдоним. |
| `addInvokables(array $invokables)` | Регистрирует классы списком. |
| `addAlias(string $alias, string $target)` | Регистрирует псевдоним. |
| `addAliases(array $aliases)` | Регистрирует псевдонимы списком. |
| `addDelegator(string $id, callable|string|array $delegator)` | Регистрирует декоратор. |
| `addDelegators(array $delegators)` | Регистрирует декораторы списком. |
| `addService(string $id, mixed $service)` | Регистрирует готовое общее значение. |
| `addServices(array $services)` | Регистрирует готовые значения списком. |
| `addParameterResolver(mixed $resolver, int $priority = 0)` | Расширяет цепочку параметров. |
| `replaceParameterResolvers(bool $replace = true)` | Отключает стандартные резолверы параметров. |
| `addAttributeHandler(mixed $handler)` | Расширяет цепочку атрибутов. |
| `replaceAttributeHandlers(bool $replace = true)` | Отключает стандартные обработчики атрибутов. |
| `compileFactories(iterable $entries, string $directory, ?ParameterResolverCodeGeneratorRegistry $generators = null, int $maxShardBytes = 131072, string $namespace = 'Componenta\DI\Generated')` | Компилирует известные корни автоматической сборки и их конкретные зависимости в шарды фабрик. |
| `toArray()` | Экспортирует текущую конфигурацию. |
| `build()` | Собирает контейнер и закрывает цепочки расширения от изменений. |

Обычная фабрика получает `Componenta\Config\ContainerValue` и контекст текущего разрешения:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn (ContainerValue $container, array $context): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

`ContainerValue` реализует `ContainerInterface` и добавляет типизированные методы чтения контейнера и конфигурации.

## Дефиниции

`Definition` создает неизменяемые описания сервисов:

```php
use Componenta\DI\Definition\Definition;

$container->set(
    ReportService::class,
    Definition::autowire(ReportService::class)
        ->constructor(['format' => 'pdf'])
        ->method('boot'),
);
```

Доступны `factory()`, `autowire()`, `reference()` и `invokable()`. `ReferenceDefinition` предназначена для аргументов конструктора или метода настройки внутри описания класса.

## Конфигурация

`Container::create(Config $config)` и `ContainerBuilder::configure(Config $config)` читают `ConfigKey::DEPENDENCIES`.

| Ключ | Формат |
|---|---|
| `ConfigKey::FACTORIES` | `array<string, callable|string|array|FactoryDefinition|ClassDefinition|CompiledFactoryDefinition>` |
| `ConfigKey::INVOKABLES` | `list<class-string>` или `array<string, class-string>` |
| `ConfigKey::ALIASES` | `array<string, string>` |
| `ConfigKey::DELEGATORS` | `array<string, callable|string|array|list<...>>` |
| `ConfigKey::SERVICES` | `array<string, mixed>` |
| `ConfigKey::PARAMETER_RESOLVERS` | `array<int, class-string|callable|ParameterResolverInterface>` |
| `ConfigKey::PARAMETER_RESOLVERS_REPLACE` | `bool` |
| `ConfigKey::ATTRIBUTE_HANDLERS` | `list<class-string|callable|AttributeHandlerInterface>` |
| `ConfigKey::ATTRIBUTE_HANDLERS_REPLACE` | `bool` |


Неизвестные ключи и неправильные форматы приводят к `InvalidConfigurationException`.

`configureFromCache($config, $cache, $baseDir)` принимает версионированный конверт кеша или простой массив зависимостей. Если передан `$baseDir`, относительные пути из описаний скомпилированных фабрик вычисляются от него.

`ConfigProvider` регистрирует необязательные резолверы преобразования типов, текущего пользователя и PSR-7-запроса. Приложение Componenta может обнаружить его через метаданные пакета.

## Атрибуты

Значение свойства записывает только зарегистрированный обработчик атрибута. Параметры конструктора и вызываемого метода обрабатывают резолверы параметров; атрибуты, допустимые и для параметров, и для свойств, участвуют в обеих цепочках.

| Атрибут | Назначение |
|---|---|
| `#[Inject]` | Свойство: получить сервис по объявленному классу или интерфейсу. |
| `#[EntryId('id')]` | Параметр или свойство: получить сервис по явному идентификатору. |
| `#[Config('path')]` | Параметр или свойство: прочитать конфигурацию. |
| `#[Env('NAME')]` | Параметр или свойство: прочитать переменную окружения с необязательным значением по умолчанию. |
| `#[Make(Service::class)]` | Параметр или свойство: создать новый объект. |
| `#[Init(callable, params)]` | Свойство: вычислить значение вызываемым обработчиком. |
| `#[Cast(...)]` | Параметр или свойство: преобразовать значение. |
| `#[CurrentUser]` | Параметр или свойство: получить текущего пользователя. |
| `#[SetUp('method', params)]` | Класс: вызвать метод настройки после создания; атрибут повторяемый. |
| `#[NoConstructor]` | Класс: создать объект без вызова конструктора. |
| `#[Lazy]` | Класс: использовать нативный ленивый объект. |
| `#[Proxy(?ConcreteClass::class)]` | Класс или точка внедрения: использовать виртуальный прокси; для интерфейса или произвольного ID сервиса требуется конкретный класс прокси. |

Скалярные атрибуты PSR-7: `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]` и `#[UploadedFile]`.

Атрибуты отображения запроса: `#[MapQueryString]`, `#[MapRequestPayload]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]` и `#[MapUploadedFiles]`. Они возвращают преобразованный массив либо создают DTO через `FactoryInterface::make()`.

Если для класса DTO существует валидатор, сначала проверяются извлечённые сырые данные запроса и только затем выполняется `transform()`. Такой порядок намеренный: переименование, преобразование типов, значения по умолчанию, отображение сортировки и исключение полей не должны скрывать некорректный транспортный ввод.

Mapper может объединять основной источник с выбранными атрибутами запроса и загруженными файлами. Разные значения одного ключа по умолчанию приводят к `RequestDataConflictException`; одинаковые дубликаты допустимы. Политику `RequestDataConflictPolicy::FirstWins` или `RequestDataConflictPolicy::LastWins` следует задавать явно только тогда, когда приоритет источников является частью контракта endpoint.

## Вызов функций и методов

`call()` принимает замыкания, имена глобальных функций, строки `"Class::method"`, идентификаторы вызываемых сервисов, `[object, 'method']` и `[class-string, 'method']`. Явные параметры имеют приоритет над резолверами по имени или позиции. Исключения целевой функции не изменяются.

## Ленивые объекты и прокси

Инициализатор ленивого объекта изменяет переданный неинициализированный объект. Фабрика виртуального прокси возвращает настоящий объект:

```php
$lazy = $container->makeLazy(
    Service::class,
    static function (Service $instance): void {
        $instance->__construct();
    },
);

$proxy = $container->makeProxy(
    Service::class,
    static fn (object $proxy): Service => new Service(),
);
```

Сервисы обычных фабрик создаются сразу, если фабрика не реализует `LazyServiceFactoryInterface`. Атрибуты класса `#[Lazy]` и `#[Proxy]` действуют на сборку через рефлексию или invokable-резолвер, но не на произвольные объекты, возвращенные фабрикой. Для точки внедрения с типом-интерфейсом или произвольным ID сервиса используйте `#[Proxy(ConcreteClass::class)]`, чтобы нативный API PHP получил конкретный класс прокси.

## Расширение

Резолвер параметра реализует:

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

Успешный результат имеет вид `[position, value]`, а `null` передает управление следующему резолверу. Сначала выполняется резолвер с большим приоритетом.

Обработчик атрибута реализует `AttributeHandlerInterface`, предоставляет неизменяемые свойства `phase` и `priority`, а также методы `supportsAttribute()` и `handle()`. Обработчик, способный создавать PHP-код, дополнительно реализует `CompilableAttributeHandlerInterface`.

После сборки контейнера обе цепочки закрываются от изменений. Попытка изменить полученный из контейнера реестр приводит к ошибке.

## Скомпилированные фабрики в боевом окружении

Известные корни автоматической сборки компилируются в обычные элементы `ConfigKey::FACTORIES`. Компилятор проходит по конкретным зависимостям конструктора, `#[Inject]` и `#[SetUp]`. Готовые сервисы, invokable-классы и явно настроенные фабрики сохраняют приоритет и не заменяются.

```php
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

$builder = ContainerBuilder::configure($config);
$compiled = $builder->compileFactories(
    entries: [new AutowireEntry(CreateOrder::class)],
    directory: __DIR__ . '/var/cache/build',
);

$dependencies = $config->get(ConfigKey::DEPENDENCIES, []);
$dependencies[ConfigKey::FACTORIES] = array_replace(
    $compiled,
    $dependencies[ConfigKey::FACTORIES] ?? [], // явные фабрики имеют приоритет
);
```

Каждый `CompiledFactoryDefinition` содержит относительный путь к шарду, имя сгенерированного класса и метод фабрики. Имя шарда зависит от его содержимого. Файл подключается только при первом запросе одного из его элементов, после чего экземпляр шарда повторно используется контейнером. При запуске SHA-256 исходных файлов не пересчитывается. Для динамических классов сохраняется автоматическая сборка через рефлексию.

Корни обычно определяет прикладной слой. `componenta/app` предоставляет сборочный контракт `AutowireEntryContributorInterface` и обрабатывает `#[Autowire]`; интеграции Router, CQRS и boot автоматически добавляют известные им классы.

`DiCacheGeneratorInterface::generate(array $config, string $path)` атомарно записывает переданный массив как PHP-файл. Он не ищет классы и не компилирует фабрики. Кеши сервисов принадлежат экземпляру `Container`, а постоянными файлами кеша и OPcache управляет развертывание.
## Исключения

| Исключение | Причина |
|---|---|
| `NotFoundException` | Ни один резолвер не поддерживает идентификатор. |
| `CircularDependencyException` | Обнаружен цикл зависимостей. |
| `ResolutionException` | Не удалось разрешить объект, параметр, свойство, фабрику или конструктор. |
| `InvalidConfigurationException` | Неверна конфигурация или дефиниция. |
| `InvalidCallableException` | Невозможно преобразовать описание вызова. |
| `DelegatorException` | Делегатор завершился с ошибкой. |
| `RequestDataConflictException` | Несколько источников request mapping передали разные значения одного ключа. |

Все исключения пакета реализуют `Componenta\DI\Exception\ExceptionInterface`.
