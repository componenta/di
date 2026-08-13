# Componenta DI

PSR-11-контейнер внедрения зависимостей для PHP 8.4+ с autowiring, фабриками, invokable-классами, alias, delegator, внешними контейнерами, атрибутами, PSR-7 request mapping, lazy objects, proxy и AOT-фабриками.

**[English](README.md)** | **[Русский](README.ru.md)**

## Установка

```bash
composer require componenta/di
```

Требуется PHP 8.4+.

## Основной API

```php
$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$logger = $container->get('logger');
$fresh = $container->make(UserService::class, ['userId' => 7]);
$result = $container->call($callable, ['id' => 7]);
```

- `get()` разрешает общий сервис и кеширует результат.
- `make()` создаёт новый объект и не использует shared cache, внешние контейнеры и delegators.
- `call()` нормализует callable, разрешает недостающие аргументы и вызывает его.
- `$params` передаются параметрам конструктора/callable по имени или позиции; обычные публичные свойства из `$params` не заполняются.

Если нет явной привязки, reflection resolver может собрать подходящий класс.

## Публичные контракты

`Container` реализует `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableInvokerInterface` и `ProxyFactoryInterface`. Отдельно доступны `CallableResolverInterface`, `CallableExecutorInterface`, `LazyObjectFactoryInterface` и `VirtualProxyFactoryInterface`.

Конкретный `Container` также предоставляет `set()`, `alias()`, `delegator()` и `addContainer()` для bootstrap/runtime-конфигурации.

## Порядок `get()`

1. Декорированный cache для запрошенного id.
2. Разрешение alias.
3. Защита от циклической зависимости.
4. Локальный base cache.
5. Явная runtime definition, ранее установленная через `Container::set()`.
6. Внешние PSR-11-контейнеры.
7. Локальная resolver-chain.
8. Delegators и decorated cache.

Готовые/уже материализованные локальные значения и явные runtime definitions имеют локальный ownership. В остальных случаях внешний контейнер проверяется до ещё не выполненных configured factories, invokables и reflection fallback. `has()` использует тот же порядок ownership, скрывает только container-level ошибки разрешения и не скрывает ошибки программирования.

`make()` разрешает alias, но намеренно пропускает shared cache, внешние контейнеры и delegators. Циклы fresh-resolution обнаруживаются тем же `CycleGuard`, что и циклы shared resolution.

## ContainerBuilder

Основные методы: `addFactory()`, `addInvokable()`, `addAlias()`, `addDelegator()`, `addService()`, их bulk-варианты, `addParameterResolver()`, `addAttributeHandler()`, `compileFactories()`, `toArray()` и `build()`.

`addService()` и `ConfigKey::SERVICES` регистрируют готовые shared values без переинтерпретации. Объект, реализующий `DefinitionInterface`, в секции services остаётся обычным значением. Definition намеренно устанавливается через `Container::set($id, $definition)` либо через factories/invokables configuration.

Delegator может быть callable, class/interface method reference или ссылкой на произвольный service id. В bulk/config input opaque method reference записывается вложенно: `[['decorator.service', 'decorate']]`; плоская пара строк означает два строковых delegator, если она не является реальной ссылкой на class/interface method.

Parameter resolvers и attribute handlers можно передавать экземплярами, service id и callable-фабриками. После `build()` extension registries закрываются от изменений.

## Дефиниции

`Definition` содержит helpers `factory()`, `reference()` и `invokable()`. Для настройки конструктора и setup-вызовов используется `ClassDefinition`:

```php
use Componenta\DI\Definition\ClassDefinition;

$container->set(
    ReportService::class,
    ClassDefinition::create(ReportService::class)
        ->constructor(['format' => 'pdf'])
        ->method('boot'),
);
```

Runtime `InvokableDefinition` проверяет тот же базовый shape, что и declarative invokables: class-string не может быть пустым.

## Конфигурация

`Container::create()` и `ContainerBuilder::configure()` читают `ConfigKey::DEPENDENCIES`. Поддерживаются factories, invokables, aliases, delegators, services, parameter resolvers и attribute handlers. Неизвестные ключи/форматы приводят к `InvalidConfigurationException`.

Persistent cache загружается только из versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Старый raw dependency cache и маркер `validated` не поддерживаются. Relative compiled-factory paths при заданном `$baseDir` ограничиваются этим каталогом.

## Атрибуты и request mapping

Основные атрибуты: `#[Inject]`, `#[EntryId]`, `#[Config]`, `#[Env]`, `#[Make]`, `#[Init]`, `#[Cast]`, `#[CurrentUser]`, `#[SetUp]`, `#[NoConstructor]`, `#[Lazy]`, `#[Proxy]`.

`#[Lazy]` и class-level `#[Proxy]` нельзя одновременно применять к одному классу. Mutable promoted property может быть явно переинициализирована через property-only `#[Init]`; initialized readonly promoted property остаётся constructor-owned. Built-in DI property handlers отклоняют static properties вместо молчаливого игнорирования атрибута.

PSR-7 extraction: `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]`, `#[UploadedFile]`.

Mappers: `#[MapQueryString]`, `#[MapRequestPayload]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]`, `#[MapUploadedFiles]`.

Если DTO валидируется, сначала проверяются исходные transport-data, затем выполняется mapper transform. Это не позволяет casts/defaults/exclusions скрыть некорректный input. Конфликт разных значений одного ключа по умолчанию приводит к `RequestDataConflictException`; `FirstWins` включается явно.

## Callable

`call()` поддерживает closures, функции, `"Class::method"`, callable service id, `[object, 'method']`, `[class/interface/service-id, 'method']`.

Ошибки разрешения/нормализации представлены DI-исключениями. После начала целевого вызова PHP engine errors и исключения самого callable проходят без обёртки с исходным типом и stack trace.

## Lazy, proxy и invokable

`makeLazy()` создаёт native lazy ghost, `makeProxy()` — native virtual proxy. Class-level `#[Lazy]`/`#[Proxy]` участвуют в reflection autowiring и взаимно исключаются.

Явный invokable намеренно создаётся обычным zero-argument `new`: attribute lifecycle и context из `make()` для него не выполняются.

Для interface/opaque-id proxy injection в `#[Proxy(ConcreteClass::class)]` указывается concrete class.

## Compiled factories

`compileFactories()` компилирует известные autowiring roots и статически известные concrete dependencies (`constructor`, `#[Inject]`, `#[SetUp]`) в обычные factory definitions. Явные services/factories/invokables сохраняют ownership.

Shard-файлы имеют content-addressed имена и загружаются по требованию. Недоверенные пути ограничиваются cache base directory; traversal и symlink за пределы корня отклоняются. Динамические классы продолжают разрешаться через reflection.

`DiCacheGeneratorInterface::generate()` только атомарно записывает переданную конфигурацию как PHP и не выполняет discovery/компиляцию.

## Исключения

Все исключения реализуют `Componenta\DI\Exception\ExceptionInterface`. Основные: `NotFoundException`, `CircularDependencyException`, `ResolutionException`, `InvalidConfigurationException`, `InvalidCallableException`, `DelegatorException`, `RequestDataConflictException`.
