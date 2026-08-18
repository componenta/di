# Componenta DI

PSR-11-контейнер внедрения зависимостей для PHP 8.4+ с семантической композицией атрибутов, autowiring, фабриками, PSR-7 request mapping, native lazy objects и AOT entry shards.

**[English](README.md)** | **[Русский](README.ru.md)**

## Установка

```bash
composer require componenta/di
```

Требуется PHP 8.4+.

## Основной API

```php
use Componenta\DI\ContainerBuilder;
use Componenta\DI\ResolutionContext;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$shared = $container->get('logger');
$fresh = $container->make(
    UserService::class,
    ResolutionContext::explicit(['userId' => 7]),
);
```

`get()` выполняет shared PSR-11 resolution. `make()` создаёт fresh object через локальный resolution pipeline и принимает явный `ResolutionContext`; shared cache, external containers и delegators на этом пути не используются.

DI-aware вызов callable отделён от низкоуровневого invoker-а:

```php
$result = $container->execute(
    [$service, 'handle'],
    ResolutionContext::explicit(['id' => 42]),
);
```

`CallableInvokerInterface::call()` остаётся прямым array-based invoker. `CallableExecutorInterface::execute()` — DI-aware API.

## ResolutionContext

В V5 framework metadata больше не переносится через служебные ключи обычного массива. Контекст имеет три независимых канала:

```php
new ResolutionContext(
    explicit: ['id' => 42],
    mapped: ['title' => 'Hello'],
    trusted: [ServerRequestInterface::class => $request],
);
```

- `explicit` — доверенные значения, явно переданные caller-ом;
- `mapped` — значения, полученные при HTTP DTO mapping;
- `trusted` — framework-owned context, например текущий PSR-7 request.

Для типичных случаев есть helpers:

```php
ResolutionContext::explicit(['id' => 42]);
ResolutionContext::mapped($payload, $request);
```

Mapped input никогда не может заменить target с объявленным value provider. Provider также может запретить explicit override — так работает `#[CurrentUser]`.

## Модель атрибутов

Атрибуты — пассивные immutable declarations. Поведение находится в зарегистрированном handler-е `AttributeDefinition`. До выполнения любого поведения строится и валидируется семантический `AttributePlan`.

Встроенные capabilities являются открытыми контрактами, а не закрытым enum типов атрибутов:

| Capability | Cardinality | Смысл |
|---|---:|---|
| `ValueProvider` | 0..1 | Получает исходное значение parameter/property. |
| `ValueTransformer` | 0..N | Преобразует уже полученное значение. |
| `CreationStrategy` | 0..1 | Выбирает eager/lazy/proxy creation. |
| `ConstructorPolicy` | 0..1 | Управляет вызовом конструктора. |
| `LifecycleHook` | 0..N | Выполняет ordered lifecycle после population. |

Сторонний пакет может определить собственный capability и собственную cardinality policy без изменения DI core.

### Взаимоисключающие providers

Все атрибуты, которые полностью предоставляют значение target-а, занимают один слот `ValueProvider`. Поэтому такие комбинации невалидны:

```php
#[Header('X-Token'), Config('token')]
string $token;

#[Inject, Init([Factory::class, 'create'])]
private Service $service;
```

Конфликт обнаруживается composition engine до запуска handler-ов. Priority больше не используется для случайного выбора победителя.

Встроенные value providers:

- `#[Config]`
- `#[Env]`
- `#[EntryId]`
- `#[Inject]`
- `#[Init]`
- `#[Make]`
- `#[CurrentUser]`
- `#[Header]`, `#[Cookie]`, `#[QueryParam]`, `#[PayloadParam]`, `#[RequestAttribute]`, `#[ServerParam]`, `#[UploadedFile]`
- `#[MapRequest]`

### Value transformers

`#[Cast]` repeatable и не конкурирует с provider-ами:

```php
public function __construct(
    #[Header('X-Count')]
    #[Cast('trim')]
    #[Cast('int')]
    public int $count,
) {}
```

Pipeline:

```text
provider/fallback -> transformer #1 -> transformer #2 -> ... -> final type check
```

Результат provider-а специально не проверяется против конечного PHP type до выполнения transforms. Например header может вернуть строку `"42"`, `#[Cast('int')]` превращает её в `42`, и только затем выполняется проверка `int`.

`#[Env]` также возвращает raw environment value. Любое преобразование задаётся явно через `#[Cast]`; скрытого приведения по type target-а нет.

### Parameter и property используют один pipeline

Constructor/callable parameters и non-promoted properties обрабатываются одним `ValuePipeline`. Property только с transformer-ами может преобразовать собственное initialized value:

```php
final class Options
{
    #[Cast('trim')]
    public string $mode = '  safe  ';
}
```

Promoted property принадлежит constructor pipeline. Его value-атрибуты выполняются на promoted constructor parameter ровно один раз; post-construction population promoted properties пропускает.

## Object lifecycle

Object creation задаётся семантическими стадиями, а не integer priorities:

```text
build/validate class AttributePlan
        -> constructor policy
        -> creation strategy
        -> instantiate
        -> populate non-promoted value properties
        -> lifecycle hooks
```

`#[Lazy]` и class-level `#[Proxy]` занимают один `CreationStrategy`, поэтому их совместное применение запрещено.

`#[NoConstructor]` задаёт `ConstructorPolicy`.

`#[SetUp]` — repeatable `LifecycleHook`; hooks выполняются в declaration order после property population:

```php
#[SetUp('configure')]
#[SetUp('boot')]
final class Service {}
```

## PSR-7 request values

Scalar request attributes являются обычными value providers:

```php
public function __construct(
    #[QueryParam('page')]
    #[Cast('int')]
    public int $page,
) {}
```

Для DTO mapping в V5 используется один `#[MapRequest]` с явным списком sources:

```php
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;

public function __construct(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Attributes],
        map: ['user_id' => 'userId'],
        exclude: ['internal'],
    )]
    public CreateOrder $command,
) {}
```

Доступные sources: `Payload`, `Query`, `Headers`, `Cookies`, `Attributes`, `Server`, `Files`.

Разные значения одного ключа из разных sources по умолчанию приводят к `RequestDataConflictException`. `RequestDataConflictPolicy::FirstWins` выбирается явно, если ordered precedence является частью endpoint contract.

Class-typed mapping допускает только именованные string keys. Nested DTO создаётся через `FactoryInterface::make()` с `ResolutionContext::mapped()`, поэтому trust boundary provider-ов контролируется обычным `ValuePipeline`; отдельного request-resolver/provenance path нет.

Если зарегистрирован `ValidationProviderInterface`, `#[MapRequest]` валидирует окончательные mapped/excluded данные перед созданием class-typed DTO.

## Расширение системы атрибутов

Новый passive attribute подключается через definition:

```php
$builder->addAttributeDefinition(new AttributeDefinition(
    attribute: CurrentTenant::class,
    handler: new CurrentTenantProvider($tenantContext),
    capabilities: [ValueProvider::class],
));
```

Поскольку `ValueProvider` уже имеет `maxPerTarget: 1`, `#[CurrentTenant]` автоматически конфликтует со всеми другими providers без pairwise rules.

Definition может дополнительно задавать `requires`, `forbids`, `before`, `after`. Selector может ссылаться на конкретный attribute class или capability. Ordering собирается в стабильный DAG; цикл является явной composition error. При отсутствии зависимости сохраняется declaration order.

Custom capability:

```php
$builder->defineAttributeCapability(
    new CapabilityPolicy(TransactionBoundary::class, maxPerTarget: 1),
);
```

## Value fallbacks

Если `ValueProvider` отсутствует, V5 использует ordered fallback registry:

```text
explicit
-> mapped
-> trusted
-> initialized property value
-> autowire
-> PHP parameter default
-> nullable
```

Fallback ordering задаётся именованными `before`/`after`, а не числовыми priorities:

```php
$builder->addValueFallback(new ValueFallbackDefinition(
    id: 'tenant-default',
    fallback: new TenantFallback(),
    after: ['trusted'],
    before: ['property_initial'],
));
```

Unknown dependency и ordering cycle приводят к ошибке при composition контейнера.

## Фабрики и definitions

Обычная user factory использует V5 ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn (
        ContainerValue $container,
        ResolutionContext $context,
    ): MailerInterface => new SmtpMailer(
        $container->get(SmtpConfig::class),
    ),
);
```

`ClassDefinition` остаётся immutable declarative data. Она не превращается в специальную constructor closure. Runtime и persistent-cache resolution `ClassDefinition` проходят через тот же `ObjectPipeline`, что и обычный reflection entry.

`Container::set($id, $definition)` меняет definition соответствующего resolver-а уже построенного контейнера. `Container::set($id, $value)` заменяет локальное shared base value.

## ContainerBuilder

Основные методы:

- `addFactory()` / `addFactories()`
- `addDefinition()`
- `addInvokable()` / `addInvokables()`
- `addAlias()` / `addAliases()`
- `addDelegator()` / `addDelegators()`
- `addService()` / `addServices()`
- `addAttributeDefinition()`
- `defineAttributeCapability()`
- `addValueFallback()`
- `compileFactories()`
- `toArray()`
- `build()`

V4 parameter-resolver priorities, attribute-handler registries и replace flags в V5 отсутствуют.

Поддерживаемые declarative dependency keys:

- `ConfigKey::FACTORIES`
- `ConfigKey::INVOKABLES`
- `ConfigKey::ALIASES`
- `ConfigKey::DELEGATORS`
- `ConfigKey::SERVICES`
- `ConfigKey::ATTRIBUTE_DEFINITIONS`
- `ConfigKey::ATTRIBUTE_CAPABILITIES`
- `ConfigKey::VALUE_FALLBACKS`

Неизвестные keys отклоняются.

## Shared resolution, aliases, delegators

`get()` сохраняет обычную container semantics: external PSR-11 ownership, local decorated cache, alias canonicalization, local base cache, entry resolution, delegators.

`make()` разрешает local aliases, но не использует shared cache, external containers и delegators. Fresh-resolution cycles продолжают обнаруживаться.

## AOT compiled entries

`compileFactories()` принимает известные autowiring roots и создаёт content-addressed shards:

```php
$compiled = $builder->compileFactories(
    entries: [CreateOrder::class],
    directory: __DIR__ . '/var/cache/di',
);
```

Generated methods в V5 намеренно тонкие: они не воспроизводят provider/transform/lifecycle logic, а делегируют в тот же runtime `ObjectPipeline`, что используется reflection resolution.

Инвариант production parity:

```text
reflection -> ObjectPipeline
AOT shard   -> ObjectPipeline
```

Shard содержит semantic fingerprint от зарегистрированных attribute definitions, capability policies и ordered value fallbacks. Несовпадение runtime fingerprint отклоняет артефакт и требует recompilation.

Перед загрузкой content-addressed shard проверяется по hash. Generated directory должна быть недоступна для изменения request process-ом.

## Persistent cache

Cache использует строгий versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Текущий V5 cache format — `12`. Более старые envelopes отклоняются.

`DiCacheGenerator` экспортирует поддерживаемые immutable definitions/configuration objects. `ClassDefinition` остаётся данными в cache и интерпретируется runtime `FactoryResolver` через `ObjectPipeline`, а не отдельным generated special-case кодом.

## Основные исключения

Все исключения пакета реализуют `Componenta\DI\Exception\ExceptionInterface`.

- `AttributeCompositionException` — неправильная cardinality/dependency/order композиция атрибутов.
- `ValueProviderConflictException` — mapped или запрещённый explicit input пытается занять provider-owned target.
- `RequestDataConflictException` — request sources передали разные значения одного mapped key.
- `ResolutionException` — ошибка resolution target/object.
- `InvalidConfigurationException` — ошибка container/extension/fallback/cache configuration.
