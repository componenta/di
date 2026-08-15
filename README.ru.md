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

- `get()` сначала передаёт исходный запрошенный id зарегистрированным внешним контейнерам; если ни один из них им не владеет, выполняется локальное shared-resolution с кешированием.
- `make()` создаёт новый объект и не использует shared cache, внешние контейнеры и delegators.
- `call()` нормализует callable, разрешает недостающие аргументы и вызывает его.
- `$params` передаются параметрам конструктора/callable по имени или позиции; обычные публичные свойства из `$params` не заполняются.

Если нет явной привязки, reflection resolver может собрать подходящий класс.

## Публичные контракты

`Container` реализует `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableInvokerInterface` и `ProxyFactoryInterface`. Отдельно доступны `CallableResolverInterface`, `CallableExecutorInterface`, `LazyObjectFactoryInterface` и `VirtualProxyFactoryInterface`.

Конкретный `Container` также предоставляет `set()`, `alias()`, `delegator()` и `addContainer()` для bootstrap/runtime-конфигурации.

## Порядок `get()`

1. Внешние PSR-11-контейнеры проверяются по исходному запрошенному id.
2. Если ни один внешний контейнер им не владеет, проверяется локальный decorated cache для requested id.
3. Разрешается локальный alias в canonical id.
4. Проверяется локальный base cache.
5. Если значения нет, запускается локальная resolver-chain и результат попадает в base cache.
6. Применяются локальные delegators и сохраняется decorated cache.

Внешний lookup выполняется ровно один раз и только по исходному id. Локальный alias никогда не отправляется во внешний контейнер вторым запросом. Если внешний контейнер владеет исходным id, его значение возвращается напрямую — без локального alias, локальных cache и локальных delegators. Поэтому внешний контейнер имеет приоритет даже над уже материализованным локальным значением при `get()`/`has()`.

Реестр внешних контейнеров — ленивое внутреннее состояние: пока внешние контейнеры не зарегистрированы, поле остаётся `null` и объект реестра не создаётся. Первый `addContainer()` создаёт `ExternalContainerRegistry`; lookup использует null-safe доступ, поэтому в обычном случае без внешних контейнеров лишнего объекта нет.

`make()` разрешает alias, но намеренно пропускает shared cache, внешние контейнеры и delegators. Циклы fresh-resolution обнаруживаются тем же `CycleGuard`, что и циклы shared resolution.

Отслеживание циклов локально для каждого execution context. Если `get()` начинает разрешать shared canonical id, который ещё разрешается в другом Fiber, выбрасывается `ConcurrentResolutionException`; вызов нужно повторить после завершения Fiber-владельца. Контейнер никогда сам не возобновляет чужой Fiber.

## ContainerBuilder

Основные методы: `addFactory()`, `addInvokable()`, `addAlias()`, `addDelegator()`, `addService()`, их bulk-варианты, `addParameterResolver()`, `addAttributeHandler()`, `compileFactories()`, `toArray()` и `build()`.

`addService()` и `ConfigKey::SERVICES` регистрируют готовые shared values без переинтерпретации. Объект, реализующий `DefinitionInterface`, в секции services остаётся обычным значением. Definition — это конфигурация resolver-а: совместимый объект definition можно передать непосредственно в секции factories/invokables либо через `Container::set($id, $definition)`. Definition и соответствующий shorthand конфигурируют одно и то же состояние resolver-а.

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

Definitions — это конфигурация resolver-ов, а не отдельный runtime-overlay. `FactoryDefinition`, `ClassDefinition` и `CompiledFactoryDefinition` можно передавать прямо в `ConfigKey::FACTORIES`; `InvokableDefinition` принимается в `ConfigKey::INVOKABLES` и нормализуется в ту же class-string форму, что и обычный invokable shorthand. Те же формы работают, когда dependency sections приходят из `ConfigProvider`.

Конфигурационные и runtime definitions используют одни и те же типы definitions, но имеют разный жизненный цикл. Definitions, находящиеся в `ContainerBuilder`/конфигурации, участвуют в нормализации и компиляции persistent cache. `FactoryDefinition` сворачивается в содержащийся в ней factory callable, `InvokableDefinition` — в class-string, а `ClassDefinition` через `DefinitionCodeGeneratorInterface` компилируется в обычную factory closure до записи cache-файла. Definition, переданная позже через `Container::set()`, изменяет только resolver уже построенного контейнера: она не записывается обратно в builder, не компилируется и не сохраняется в persistent cache.

`Container::set($id, $definition)` переконфигурирует подходящий resolver и удаляет уже материализованный локальный base entry для этого id, чтобы новая definition могла разрешиться. `Container::set($id, $value)` заменяет только локальное shared value и не удаляет/не откатывает конфигурацию resolver-а, поэтому `make($id)` продолжает использовать настроенный resolver binding.

`ReferenceDefinition` представляет ссылку на container entry внутри аргументов class-definition. При генерации кода `ClassDefinition` такая ссылка превращается в lookup контейнера внутри сгенерированной фабрики. `InvokableDefinition` проверяет тот же базовый shape, что и invokable shorthand: class-string не может быть пустым.

Генерация кода definitions расширяется через `DefinitionCodeGeneratorInterface` и `DefinitionCodeGeneratorRegistry`. Контракт генератора принимает `DefinitionInterface`, а registry выбирает конкретный генератор по классу/интерфейсу definition, поэтому для пользовательских типов definitions не требуется изменять compiler.

## Конфигурация

`Container::create()` и `ContainerBuilder::configure()` читают `ConfigKey::DEPENDENCIES`. Поддерживаются factories, invokables, aliases, delegators, services, parameter resolvers и attribute handlers. Неизвестные ключи/форматы приводят к `InvalidConfigurationException`.

Persistent cache загружается только из versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Старый raw dependency cache не поддерживается. Прежний маркер `validated: true` принимается только как deprecated и полностью игнорируемое поле совместимости для старых application-level генераторов: он не отключает проверки и не делает compiled factories доверенными. Новые генераторы должны его пропускать. Relative compiled-factory paths при заданном `$baseDir` ограничиваются этим каталогом.

Версия cache envelope проверяется строго. Версия 9 намеренно несовместима с ранее сгенерированными кэшами: при деплое нужно заново собрать persistent cache и все compiled-factory shards, а не переносить или редактировать старые артефакты.

## Атрибуты и request mapping

Основные атрибуты: `#[Inject]`, `#[EntryId]`, `#[Config]`, `#[Env]`, `#[Make]`, `#[Init]`, `#[Cast]`, `#[CurrentUser]`, `#[SetUp]`, `#[NoConstructor]`, `#[Lazy]`, `#[Proxy]`.

`#[Lazy]` и class-level `#[Proxy]` нельзя одновременно применять к одному классу. Mutable promoted property может быть явно переинициализирована через property-only `#[Init]`; initialized readonly promoted property остаётся constructor-owned. Built-in DI property handlers отклоняют static properties вместо молчаливого игнорирования атрибута.

PSR-7 extraction: `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]`, `#[UploadedFile]`.

Mappers: `#[MapQueryString]`, `#[MapRequestPayload]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]`, `#[MapUploadedFiles]`.

Для class-typed HTTP DTO разрешены только именованные строковые ключи верхнего уровня — как до валидации, так и после mapper transform. Целочисленные ключи, включая числовые ключи JSON-объекта, которые PHP декодировал как integer, отклоняются и не интерпретируются как позиции конструктора. Ограничение относится только к HTTP DTO mapping; доверенные программные вызовы `Container::make()` по-прежнему принимают аргументы по имени и позиции.

Если DTO валидируется, сначала проверяются исходные transport-data, затем выполняется mapper transform. Это не позволяет casts/defaults/exclusions скрыть некорректный input. Конфликт разных значений одного ключа по умолчанию приводит к `RequestDataConflictException`; `FirstWins` включается явно.

## Callable

`call()` поддерживает closures, функции, `"Class::method"`, callable service id, `[object, 'method']`, `[class/interface/service-id, 'method']`.

Ошибки разрешения/нормализации представлены DI-исключениями. После начала целевого вызова PHP engine errors и исключения самого callable проходят без обёртки с исходным типом и stack trace.

## Lazy, proxy и invokable

`makeLazy()` создаёт native lazy ghost, `makeProxy()` — native virtual proxy. Class-level `#[Lazy]`/`#[Proxy]` участвуют в reflection autowiring и взаимно исключаются.

Явный invokable намеренно создаётся обычным zero-argument `new`: attribute lifecycle и context из `make()` для него не выполняются.

Для interface/opaque-id proxy injection в `#[Proxy(ConcreteClass::class)]` указывается concrete class.

## Compiled factories

`compileFactories()` компилирует известные autowiring roots и статически известные concrete dependencies (`constructor`, `#[Inject]`, `#[SetUp]`) в обычные factory definitions. Явные services/factories/invokables сохраняют ownership и не заменяются этим autowiring compiler. Конфигурационные `ClassDefinition` обрабатываются отдельно definition compiler при генерации persistent cache: они превращаются в обычные closure factories и поэтому не попадают в autowiring compilation graph.

Shard-файлы имеют content-addressed имена и загружаются по требованию. Недоверенные пути ограничиваются cache base directory; traversal и symlink за пределы корня отклоняются. Динамические классы продолжают разрешаться через reflection.

Перед загрузкой недоверенного shard runtime проверяет, что его байты соответствуют digest в имени файла. Каждый сгенерированный shard также содержит fingerprint упорядоченных parameter resolvers и attribute handlers; несовпадение с runtime pipeline отклоняется и требует перекомпиляции. Сгенерированные артефакты нужно размещать в каталоге, недоступном для записи request-процессу: integrity-проверки дополняют, но не заменяют filesystem permissions.

`DiCacheGeneratorInterface::generate()` нормализует переданную dependency-конфигурацию, запускает компиляцию declarative definitions и атомарно записывает полученный PHP cache. Он не выполняет discovery классов приложения и не запускает `compileFactories()` для autowiring roots.

Экспорт persistent cache сохраняет повторную идентичность поддерживаемых readonly-объектов и closures, в том числе closures внутри массивов. Если существующий cache-файл был загружен в OPcache, его замена должна также успешно инвалидировать закэшированный script, иначе генерация завершается явной ошибкой.

## Исключения

Все исключения реализуют `Componenta\DI\Exception\ExceptionInterface`. Основные: `NotFoundException`, `CircularDependencyException`, `ConcurrentResolutionException`, `ResolutionException`, `InvalidConfigurationException`, `InvalidCallableException`, `DelegatorException`, `RequestDataConflictException`.
