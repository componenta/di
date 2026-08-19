# Componenta DI v5 — финальная архитектурная спецификация

> Статус: реализованная архитектура ветки `v5`.
>
> Baseline совместимости: фактическое поведение `main`/v4 с сохранением публичных возможностей, если они не заменены явно более узким и детерминированным v5 API.
>
> Основной критерий готовности: constructor/callable parameters имеют ровно один resolution pipeline; class/property/method attributes имеют один execution pipeline; dev и AOT используют одни и те же resolver/handler implementations.

---

# 1. Цели v5

V5 решает четыре задачи одновременно:

1. сохранить функциональный контракт v4;
2. убрать дублирование reflection/dev и compiled/prod семантики;
3. сделать композицию атрибутов детерминированной и валидируемой;
4. сохранить расширяемость контейнера без глобальных fallback-цепочек и transport-specific DI context.

Архитектура строится вокруг трёх независимых обязанностей:

```text
ParameterResolverInterface
    разрешает параметры constructor/callable

AttributePlanBuilder
    строит и валидирует semantic metadata атрибутов

AttributeHandlerInterface
    исполняет object attributes
```

Параметрические атрибуты соединяют первые два механизма через один встроенный `AttributeParameterResolver` и `ParameterAttributeHandlerInterface`.

---

# 2. Неподлежащие нарушению инварианты

## 2.1. Parameter resolution

1. Любой DI-aware параметр разрешается только `ParameterResolverInterface`.
2. `ParametersResolver` является orchestration layer, а не вторым source resolver-ом.
3. Attribute metadata сама не возвращает parameter value.
4. Attribute handler не вызывается напрямую из `ObjectPipeline` для parameter target.
5. Parameter attribute входит в resolver pipeline только через `AttributeParameterResolver`.
6. Custom convention resolution остаётся доступным через custom `ParameterResolverInterface`.
7. Built-in attribute semantics не регистрируют по отдельному `ParameterResolverInterface`.
8. Parameter resolver pipeline seal-ится после build.
9. `supports()` не должен мутировать resolver chain.
10. Result любого resolver-а проходит централизованную validation.

## 2.2. Attribute processing

1. `PropertyResolverInterface` отсутствует.
2. Class/property/method attributes выполняются через `AttributeHandlerInterface`.
3. Parameter attributes используют ту же `AttributeDefinition`/`AttributePlan` модель.
4. Parameter-aware handler реализует `ParameterAttributeHandlerInterface`.
5. Один `AttributeDefinition` детерминированно связывает attribute class и handler.
6. Linear runtime-поиск `supportsAttribute()` отсутствует.
7. Composition validation выполняется до handler execution.
8. Registry после build immutable/sealed.

## 2.3. Public context

1. `FactoryInterface::make(string, array $params = [])` — публичная factory boundary.
2. `CallableInvokerInterface::call(..., array $params = [])` — публичная callable boundary.
3. `Container::make()` и `Container::call()` используют обычные arrays.
4. `ResolutionContext` отсутствует.
5. Нет global `explicit/mapped/trusted` DI categories.
6. PSR-7 request не является special case generic DI core.
7. Request mapping provenance является internal HTTP metadata.

## 2.4. Dev/AOT parity

1. Reflection и AOT вызывают те же resolver instances.
2. Reflection и AOT вызывают те же attribute handler instances/classes.
3. Generated shard не реализует DI semantics заново.
4. Custom resolver не требует resolver code generator.
5. Custom attribute handler не требует attribute code generator.
6. Invalid semantic fingerprint отклоняет stale shard.
7. AOT validation выполняется до записи shard.

---

# 3. Финальные public interfaces

## 3.1. Factory

```php
interface FactoryInterface
{
    public function make(string $entry, array $params = []): object;
}
```

`$params` могут содержать:

- parameter name;
- numeric position;
- declared class/interface key;
- framework value, например `ServerRequestInterface::class`.

Fresh `make()` не превращается в shared `get()`.

## 3.2. Callable API

```php
interface CallableInvokerInterface
{
    public function call(mixed $callable, array $params = []): mixed;
}
```

```php
interface CallableResolverInterface
{
    public function resolve(mixed $callable): callable;
}
```

```php
interface CallableExecutorInterface
    extends CallableInvokerInterface, CallableResolverInterface
{
}
```

Отдельного `execute()` нет.

## 3.3. Parameter resolver

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;

    /** @return array{0:int,1:mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

`null` означает «продолжить resolver chain».

## 3.4. Object attribute handler

```php
interface AttributeHandlerInterface
{
    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void;
}
```

Он используется `AttributeProcessor` для class/property/method targets.

## 3.5. Parameter attribute handler

```php
interface ParameterAttributeHandlerInterface
    extends AttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed;
}
```

Это handler contract, а не самостоятельный resolver pipeline.

`AttributeParameterResolver` является единственным built-in adapter-ом, который вызывает этот contract для parameter target.

---

# 4. Финальная resolver chain

Default chain:

```text
priority 1100  ArrayResolver
priority 1000  ArrayTypedResolver
priority  900  AttributeParameterResolver
priority  800  RequestContextResolver
priority  300  AutowireByTypeResolver
priority  200  DefaultValueResolver
priority  100  NullableResolver
```

Это принципиально отличается от промежуточной идеи «один resolver на каждый атрибут».

Отдельных built-in parameter resolvers для:

```text
Cast
Config
Env
EntryId
CurrentUser
Make
Proxy
Header
Cookie
QueryParam
PayloadParam
RequestAttribute
ServerParam
UploadedFile
Map*
```

нет.

Их semantics принадлежат attribute handlers.

## 4.1. ArrayResolver

Разрешает значения по:

```text
parameter name
parameter position
```

Проверяет declared type.

Особые v4-compatible exclusions:

- `#[Cast]` должен получить raw caller value и применить caster сам;
- `#[CurrentUser]` является authoritative source и не подменяется caller input.

Поэтому эти targets generic explicit resolver пропускает.

## 4.2. ArrayTypedResolver

Разрешает object value по declared class/interface key.

Пример:

```php
[
    LoggerInterface::class => $logger,
]
```

Также не перехватывает `#[Cast]` и `#[CurrentUser]`.

## 4.3. AttributeParameterResolver

Ответственность:

1. получить `AttributePlan` parameter target;
2. найти `ParameterAttributeHandlerInterface` внутри plan usages;
3. дедуплицировать один handler, используемый несколькими совместимыми attributes;
4. вызвать handler;
5. вернуть `[$position, $value]` в обычный parameter pipeline.

`#[Make] + #[Proxy]` — важный пример: обе definitions используют один `MakeHandler`, поэтому комбинация не создаёт два независимых resolver-а.

## 4.4. RequestContextResolver

Остаётся отдельным resolver-ом, потому что обслуживает **неатрибутную** семантику:

```php
UriInterface $uri
```

Request source attribute для этого не требуется.

`ServerRequestInterface` может быть передан обычным typed key и будет разрешён стандартным explicit typed resolver.

## 4.5. AutowireByTypeResolver

Разрешает class/interface dependency через container lookup.

## 4.6. DefaultValueResolver

Возвращает PHP default parameter value.

## 4.7. NullableResolver

Возвращает `null`, если target допускает null и более сильный resolver не предоставил значение.

---

# 5. Custom ParameterResolverInterface

Custom resolver остаётся first-class extension point для convention-based semantics.

Пример:

```php
final readonly class TenantResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'tenant';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, $this->tenant];
    }
}
```

Регистрация:

```php
$builder->addParameterResolver(new TenantResolver($tenant), 750);
```

Extension specification может быть:

```text
instance
service id
callable factory
[serviceId, method]
```

Custom priority должен быть уникальным среди user registrations.

`replaceParameterResolvers(true)` отключает built-ins.

---

# 6. AttributeDefinition

`AttributeDefinition` — semantic registration одного DI attribute.

Концептуальный shape:

```php
new AttributeDefinition(
    attribute: AttributeClass::class,
    handler: $handler,
    capabilities: [...],
    requires: [...],
    forbids: [...],
    before: [...],
    after: [...],
    rules: [...],
    version: 1,
    phase: AttributePhase::AfterInstantiation,
);
```

## 6.1. Ответственность definition

Definition фиксирует:

- attribute class;
- runtime handler;
- semantic capabilities;
- dependencies;
- incompatibilities;
- ordering constraints;
- custom rules;
- semantic version;
- execution phase.

## 6.2. Null handler

`handler: null` допустим для metadata-only attributes или когда package намеренно использует отдельный custom parameter resolver.

Built-in parameter attributes используют handlers и проходят через `AttributeParameterResolver`.

---

# 7. AttributePlanBuilder

Plan builder является единственным semantic planning layer.

Он:

1. читает reflection attributes;
2. находит `AttributeDefinition`;
3. проверяет `#[Attribute]` target mask;
4. корректно обрабатывает promoted parameter/property reflection duplication;
5. создаёт `AttributeUsage`;
6. проверяет capability cardinality;
7. проверяет `requires`;
8. проверяет `forbids`;
9. запускает custom rules;
10. строит topological `before/after` ordering;
11. кеширует immutable plan;
12. инвалидирует cache при изменении registry revision.

Closure/anonymous targets используют `WeakMap`, чтобы metadata cache не создавал process-lifetime leaks.

---

# 8. Capabilities

Capabilities являются metadata, а не execution interfaces.

Built-in categories включают:

```text
ValueProvider
AuthoritativeValueProvider
ValueTransformer
CreationStrategy
ConstructorPolicy
LifecycleHook
```

`CapabilityPolicy` задаёт cardinality.

Capability inheritance учитывается.

Например `AuthoritativeValueProvider extends ValueProvider`, поэтому parent policy продолжает действовать.

---

# 9. AttributeProcessor

`AttributeProcessor` исполняет class/property/method plans.

Он:

- не разрешает constructor parameters;
- не имеет property resolver chain;
- не делает linear `supportsAttribute()` scan;
- получает handler из semantic definition;
- соблюдает execution phase;
- обходит private inherited properties;
- обрабатывает methods;
- пропускает parameter-only targets.

Фазы:

```text
BeforeInstantiation
AfterInstantiation
Both
```

---

# 10. Parameter attributes

Parameter attributes используют общую composition model, но остаются внутри parameter resolver invariant.

Flow:

```text
ReflectionParameter
    -> ParameterTarget
    -> AttributePlanBuilder
    -> AttributePlan
    -> ParametersResolver
    -> AttributeParameterResolver
    -> ParameterAttributeHandlerInterface
    -> ParameterResolutionResult validation
```

Никакой handler не вставляет parameter value напрямую в constructor arguments.

---

# 11. Custom parameter attribute

Рекомендуемая extension model:

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentTenant {}
```

```php
final readonly class CurrentTenantHandler
    implements ParameterAttributeHandlerInterface
{
    public function __construct(private Tenant $tenant) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed {
        return $this->tenant;
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        throw new LogicException('Parameter-only attribute.');
    }
}
```

```php
$builder->addAttributeDefinition(new AttributeDefinition(
    CurrentTenant::class,
    new CurrentTenantHandler($tenant),
    capabilities: [ValueProvider::class],
));
```

Custom `ParameterResolverInterface` для такого attribute не обязателен.

---

# 12. Built-in attribute handlers

Финальный набор shared value handlers:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler
RequestAttributeHandler
```

Отдельные `CastableResolver`, `ConfigAttributeResolver`, `EnvResolver`, `EntryIdResolver`, `CurrentUserResolver`, `MakeAttributeResolver`, `RequestResolver` не являются частью финальной архитектуры.

---

# 13. CastHandler

Targets:

```text
parameter
property
```

Parameter behavior:

1. caller value by name;
2. caller value by position;
3. `Cast::$default`;
4. PHP default;
5. nullable;
6. required-value error;
7. caster lookup;
8. cast result.

Property behavior использует object creation parameters и property name.

Это сохраняет v4 semantics без отдельного resolver priority 1200.

`ArrayResolver` просто не перехватывает `#[Cast]` target.

---

# 14. ConfigHandler

Targets:

```text
parameter
property
```

Использует `Componenta\Config\Config::get()`.

Сохраняются:

- literal string key semantics;
- `ConfigPath` semantics;
- attribute default;
- container exception boundary.

Caller-provided обычное значение имеет более высокий generic priority и может override Config source, как в v4.

---

# 15. EnvHandler

Targets:

```text
parameter
property
```

Сохраняется преобразование:

```text
string
int
float
bool
array
```

Для остальных типов используется raw environment value.

Если `Environment` отсутствует:

- attribute default возвращается, если задан;
- иначе resolution error.

Если variable отсутствует — та же схема.

---

# 16. EntryIdHandler

Targets:

```text
parameter
property
```

Получает явно указанный container entry.

---

# 17. CurrentUserHandler

Targets:

```text
parameter
property
```

Использует `CurrentUserProviderInterface`.

Особенности:

- source authoritative;
- generic caller input не подменяет user;
- nullable target может получить null;
- explicit `CurrentUser::type` проверяется;
- обычная declared-type validation остаётся централизованной.

Semantic capability: `AuthoritativeValueProvider`.

---

# 18. MakeHandler

Обслуживает:

```text
#[Make]
#[Proxy]
```

Targets:

```text
class      // Proxy creation strategy
parameter
property
```

## 18.1. Make

Определяет backing entry и params.

## 18.2. Proxy

Определяет concrete proxy class.

Если type является interface и concrete class нельзя вывести, требуется:

```php
#[Proxy(Concrete::class)]
Interface $service
```

## 18.3. Make + Proxy

```php
#[Make('service.id')]
#[Proxy(Concrete::class)]
Interface $service
```

Service id и proxy class независимы.

Обе definitions используют один `MakeHandler`, поэтому `AttributeParameterResolver` выполняет одну value operation.

## 18.4. Class-level Proxy

`#[Proxy]` на классе выбирает `CreationStrategy::Proxy` до instantiation.

---

# 19. RequestAttributeHandler

Один handler обслуживает:

```text
QueryParam
PayloadParam
Header
Cookie
RequestAttribute
ServerParam
UploadedFile
MapRequest
MapQueryString
MapRequestPayload
MapHeaders
MapCookies
MapRequestAttributes
MapServerParams
MapUploadedFiles
```

Это parameter-only handler.

Он:

1. получает request из обычного typed parameter array;
2. выполняет scalar extraction либо mapping;
3. применяет transport caster, если задан `cast:`;
4. валидирует raw DTO data;
5. запускает mapper transformation;
6. переносит internal mapping provenance;
7. вызывает обычный `FactoryInterface::make()` для nested DTO.

---

# 20. RequestContextResolver

Не является attribute handler.

Он существует только для implicit non-attribute request context:

```php
UriInterface $uri
```

Generic DI core не содержит request object в специальном context object.

---

# 21. Request mapping pipeline

Специализированные Map* v4 и generic `MapRequest` используют один pipeline:

```text
source extraction
-> conflict merge
-> raw validation
-> field map
-> caster transformations
-> defaults
-> sortMap
-> exclude
-> DTO construction
```

Validation выполняется на raw transport data до mapping transformations, сохраняя v4 behavior.

---

# 22. Request provenance

`MappedRequestContext` является internal helper.

Он хранит hidden marker внутри обычного params array.

`ParameterResolutionContext` извлекает provenance и не раскрывает marker custom resolvers как обычный пользовательский parameter.

`MappedRequestParameterSourceGuard` предотвращает подмену:

- named source-bound parameter;
- declared object type key;
- `ServerRequestInterface`;
- `UriInterface`;
- custom `ParameterSourceAttributeInterface`;
- источников через alias;
- источников через `ClassDefinition`;
- источников через lazy/proxy boundary;
- источников через high-priority custom resolver.

Guard выполняется до resolver priority.

Object-level preflight происходит при создании `ObjectCreationContext`, поэтому lazy object не может отложить security/source-boundary validation до первого обращения.

---

# 23. ObjectCreationContext

Одна mutable state object на object creation attempt.

Хранит:

```text
ReflectionClass
constructorEnabled
CreationStrategy
entry
public stripped parameters
internal raw resolution parameters
claimed properties
```

Internal raw parameters сохраняют request provenance для constructor resolution.

Public property-handler parameters marker не содержат.

Property API:

```text
claimProperty()
readProperty()
writeProperty()
```

Static property с DI handler отклоняется явно.

Readonly initialized property не перезаписывается.

---

# 24. ObjectPipeline

Один runtime для reflection и compiled entries.

Flow:

```text
metadata(class)
-> ObjectCreationContext(raw params)
-> AttributeProcessor BEFORE
-> CreationStrategy
-> eager / lazy / proxy
-> InstanceCreator -> ParametersResolver
-> initialize object context
-> AttributeProcessor AFTER
-> object
```

## 24.1. Eager

Обычное создание instance.

## 24.2. Lazy

Preflight attributes и request provenance проверяются до создания lazy object.

Constructor resolution откладывается до initialization, но использует те же raw resolution params.

## 24.3. Proxy

Virtual proxy использует тот же eager object pipeline для backing creation.

---

# 25. Attribute lifecycle handlers

## 25.1. Inject

Property-only injection.

## 25.2. Init

Property initialization после constructor.

Mutable promoted property может быть обновлён.

Initialized readonly promoted property сохраняется.

## 25.3. NoConstructor

Before-instantiation handler отключает constructor.

## 25.4. Lazy

Before-instantiation handler выбирает lazy strategy.

## 25.5. Proxy

`MakeHandler` выбирает proxy strategy для class target и value proxy для parameter/property target.

## 25.6. SetUp

After-instantiation lifecycle hook.

Метод вызывается DI-aware callable executor-ом.

SetUp descriptors сохраняют Config/Env/EntryId/ContainerValue semantics.

---

# 26. Callable execution

```text
Container::call()
-> CallableExecutor
-> CallableResolver
-> reflected parameter targets
-> ParametersResolver
-> CallableInvoker
```

Same parameter chain используется constructor и callable paths.

Opaque service id имеет приоритет над same-named native function/string callable.

Native explicit array callable сохраняет PHP semantics.

---

# 27. Factory ABI

User factory получает:

```php
(ContainerValue $container, array $params)
```

или совместимое сокращение аргументов.

Validator проверяет callable signature до first resolution, где это возможно.

Поддерживаются deferred factory/service method формы.

---

# 28. ClassDefinition

`ClassDefinition` остаётся immutable data object.

Configured constructor params и runtime overrides нормализуются по constructor signature.

Runtime override может быть задан:

```text
name
position
declared class/interface key
```

Runtime override имеет приоритет над соответствующим configured constructor argument.

Method calls используют DI-aware callable path.

Persistent cache не генерирует альтернативную resolution logic для `ClassDefinition`.

---

# 29. Container core semantics

Сохраняются:

```text
PSR-11 get/has
fresh make
aliases
external containers
delegators
runtime definitions
stored services
lazy/proxy factories
cycle detection
shared resolution
Fiber ownership detection
cache invalidation
```

## 29.1. get

Shared resolution.

External container ownership может иметь precedence согласно container contract.

## 29.2. make

Fresh local resolution.

Не использует shared cache/external/delegator semantics как `get()`.

## 29.3. Fiber concurrency

Concurrent shared build в другом Fiber приводит к `ConcurrentResolutionException`, а не к ложному circular dependency.

---

# 30. ContainerBuilder extension API

Финальные основные methods:

```text
addFactory / addFactories
addDefinition
addInvokable / addInvokables
addAlias / addAliases
addDelegator / addDelegators
addService / addServices
addParameterResolver
replaceParameterResolvers
addAttributeDefinition
replaceAttributeDefinitions
defineAttributeCapability
compileFactories
toArray
build
```

---

# 31. Dependency configuration

Финальные DI keys:

```text
factories
invokables
aliases
delegators
services
parameter_resolvers
parameter_resolvers_replace
attribute_definitions
attribute_definitions_replace
attribute_capabilities
```

Unknown keys отклоняются.

Integer keys `parameter_resolvers` — priorities и не переиндексируются.

`*_replace` — bool flags.

---

# 32. Extension materialization

Поддерживаются:

```text
ready instance
service id
Closure/callable factory
[serviceId, method]
```

String service id имеет container precedence перед интерпретацией как callable там, где спецификация допускает обе формы.

---

# 33. ConfigProvider discovery

Пакет содержит `Componenta\DI\ConfigProvider`.

Composer metadata экспортирует его через:

```text
extra.componenta.config-providers
```

Provider не дублирует built-in resolver/attribute registrations: composition root собирает их внутри `ContainerBuilder`.

---

# 34. AOT architecture

`compileFactories()` строит class graph и генерирует тонкие entry methods.

Generated method концептуально:

```php
public function createEntry(array $params = []): object
{
    return $this->objects->create(Target::class, $params);
}
```

Production не содержит второй implementation `Cast`, `Env`, request mapping, parameter resolver или lifecycle handler.

---

# 35. AOT prepare

Перед shard write:

```text
ObjectPipeline::prepare(class)
-> class AttributePlan
-> constructor parameter AttributePlans
-> property/method AttributePlans
```

Invalid built-in composition отклоняется до записи shard.

Custom runtime resolver/handler всё равно вызывается тем же implementation в production.

---

# 36. Semantic fingerprint

Fingerprint включает:

```text
compiler format version
AttributePlanBuilder format version
attribute class
definition version
handler class
handler phase
capabilities
requires / forbids
before / after
custom rule class + semantic version
capability policies
ordered parameter resolver class
parameter resolver priority
parameter resolver semantic version
```

Изменение semantic registration invalidates compiled shard.

---

# 37. Content-addressed shards

Shard filename содержит hash содержимого.

Runtime проверяет:

1. content-addressed filename shape;
2. file content hash;
3. semantic pipeline fingerprint;
4. generated class/method validity.

---

# 38. Cache envelope

Текущий cache format:

```php
ContainerBuilder::CACHE_VERSION === 15
```

Envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Старые versions fail closed.

---

# 39. Что сохранено из v4

Проверенный parity surface включает:

```text
array-based make/call API
ParameterResolverInterface
priority-based custom resolver registration
resolver replacement
provided values by name/position/type
autowire
PHP defaults
nullable
Config
Env target-type conversion
EntryId
CurrentUser authority
Cast default/casting semantics
Make
Proxy class/parameter/property semantics
Inject
Init
NoConstructor
Lazy
SetUp
QueryParam
PayloadParam
Header
Cookie
RequestAttribute
ServerParam
UploadedFile
MapQueryString
MapRequestPayload
MapHeaders
MapCookies
MapRequestAttributes
MapServerParams
MapUploadedFiles
request mapper ordering
request source guard
ClassDefinition runtime overrides
factory signatures
service-method extension specifications
ConfigProvider discovery
alias/delegator/external ownership semantics
Fiber shared-resolution behavior
```

V5 дополнительно содержит generic multi-source `MapRequest`.

---

# 40. Что намеренно удалено

Не являются частью финального v5:

```text
ResolutionContext
CallableExecutor::execute
ValuePipeline
ValueContext
ValueResult
ValueFallbackInterface
ValueFallbackDefinition
ValueFallbackRegistry
ExplicitValueFallback
MappedValueFallback
TrustedValueFallback
PropertyInitialValueFallback
AutowireValueFallback
DefaultValueFallback
NullableValueFallback
ValueProviderHandlerInterface
ValueTransformerHandlerInterface
ValueDefaultHandlerInterface
ValueWrapperHandlerInterface
CreationStrategyHandlerInterface
ConstructorPolicyHandlerInterface
LifecycleHookHandlerInterface
ValueProviderPrecedence
PropertyResolverInterface
ParameterResolverCodeGeneratorInterface
parameter-resolver code generator registry
attribute code generator runtime hierarchy
```

Также удалены transitional attribute-specific parameter resolver classes:

```text
CastableResolver
ConfigAttributeResolver
EnvResolver
EntryIdResolver
CurrentUserResolver
MakeAttributeResolver
RequestResolver
```

Их функциональность находится в attribute handlers + одном `AttributeParameterResolver`.

---

# 41. Финальный namespace layout

Ключевая структура:

```text
Componenta\DI\Resolver\Parameter
    ParametersResolver
    ParameterResolverInterface
    ParameterResolutionContext
    ParameterResolutionResult
    ArrayResolver
    ArrayTypedResolver
    AttributeParameterResolver
    RequestContextResolver
    AutowireByTypeResolver
    DefaultValueResolver
    NullableResolver

Componenta\DI\Resolver\Attribute
    AttributeHandlerInterface
    ParameterAttributeHandlerInterface
    AttributeProcessor
    AttributePhase

Componenta\DI\Resolver\Attribute\Handler
    CastHandler
    ConfigHandler
    EnvHandler
    EntryIdHandler
    CurrentUserHandler
    MakeHandler
    RequestAttributeHandler
    InjectHandler
    InitHandler
    LazyHandler
    NoConstructorHandler

Componenta\DI\Resolver\Parameter\Request
    request extraction/mapping/provenance helpers
```

Таким образом классы с названием `Resolver`, оставшиеся вне `Resolver\Parameter`, больше не являются parameter resolvers. Attribute semantics находятся в `Resolver\Attribute\Handler`.

---

# 42. Почему Cast/Env/Config не являются отдельными parameter resolvers

Если каждый built-in attribute имеет собственный resolver:

```text
CastResolver
EnvResolver
ConfigResolver
MakeResolver
RequestResolver
...
```

то parameter resolver priority одновременно начинает кодировать:

- source precedence;
- attribute semantics;
- attribute composition;
- transformation ordering.

Это снова создаёт скрытую конкуренцию между атрибутами.

Финальный v5 разделяет эти обязанности:

```text
Parameter resolver priority
    выбирает тип resolution strategy

AttributePlan
    валидирует композицию атрибутов

Attribute handler
    реализует semantics конкретного атрибута
```

Поэтому один `AttributeParameterResolver` является правильной границей.

---

# 43. Почему ParameterResolverInterface всё ещё нужен

Не вся parameter resolution является attribute-driven.

Без атрибутов должны работать:

```text
caller values
typed explicit values
custom naming/convention rules
implicit UriInterface
autowire
PHP default
nullable
```

Поэтому полностью заменить `ParameterResolverInterface` на attribute processor нельзя.

---

# 44. Почему AttributeProcessor не разрешает параметры

`AttributeProcessor` работает с mutable object lifecycle:

```text
class
property
method
```

Parameter resolution имеет другую ответственность:

```text
ordered source selection
argument position
resolved preceding arguments
type validation
caller params
```

Если `AttributeProcessor` начнёт напрямую возвращать constructor arguments, появится второй parameter pipeline.

Поэтому parameter attribute handler вызывается **внутри `AttributeParameterResolver`**.

---

# 45. Почему RequestContextResolver остаётся resolver-ом

`UriInterface $uri` не обязан иметь attribute.

Следовательно это convention/context parameter resolution, а не attribute execution.

`RequestContextResolver` расположен в `Resolver\Parameter` и является обычным `ParameterResolverInterface`.

Все request attributes находятся в `RequestAttributeHandler`.

---

# 46. Проверяемая расширяемость

## 46.1. Custom convention resolver

Тестируется в dev и AOT.

## 46.2. Custom parameter attribute handler

Тестируется без custom parameter resolver:

```text
AttributeDefinition
-> ParameterAttributeHandlerInterface
-> built-in AttributeParameterResolver
-> same result dev/AOT
```

## 46.3. Custom class/property/method handler

Один handler тестируется на всех трёх target types в dev и AOT.

## 46.4. Custom composition rule

Получает complete `AttributeSet` до execution.

---

# 47. Request security/source-boundary tests

Parity suite проверяет:

```text
mapped named key vs Header
mapped typed key vs custom source
mapped ServerRequestInterface key
mapped UriInterface key
high-priority custom resolver cannot bypass guard
ClassDefinition boundary
persistent cache boundary
alias boundary
lazy alias boundary
dev/AOT parity
```

---

# 48. Object lifecycle parity tests

Проверяются:

```text
Make cycles
Proxy interface/concrete compatibility
Proxy backing object type
Make + Proxy independence
readonly promoted Init
mutable promoted Init
private inherited Inject
static property rejection
SetUp descriptors
```

---

# 49. Core container parity tests

Проверяются:

```text
get shared / make fresh
external container ownership
runtime definition ownership
alias invalidation
deferred delegator invalidation
external callable ownership changes
Fiber concurrent shared resolution
callable service-id precedence
factory callable signatures
```

---

# 50. Public API parity tests

Reflection-based manifest фиксирует named constructor arguments v4 attributes.

Отдельно фиксируются:

```text
FactoryInterface::make(array)
CallableExecutorInterface без execute()
ParameterResolverInterface
ParameterAttributeHandlerInterface
AttributeHandlerInterface
ConfigProvider discovery
Map* public classes
```

---

# 51. CI acceptance gate

Обязательный exact-HEAD gate:

```text
composer validate --strict
PHP-CS-Fixer check
PHPStan max level
Pest
```

Matrix:

```text
PHP 8.4
PHP 8.5
```

Ветка не считается готовой, если хотя бы один exact-HEAD status не `success`.

---

# 52. Финальные acceptance criteria

V5 готова к дальнейшему release process только если одновременно выполняется всё следующее:

- [x] public factory/callable ABI array-based;
- [x] `ResolutionContext` удалён;
- [x] `CallableExecutor::execute()` удалён;
- [x] parameter resolution идёт только через `ParameterResolverInterface`;
- [x] default parameter chain содержит один `AttributeParameterResolver`;
- [x] Cast/Config/Env/EntryId/CurrentUser/Make/Request не являются отдельными parameter resolvers;
- [x] class/property/method attributes выполняются `AttributeProcessor`;
- [x] custom parameter attribute работает через `ParameterAttributeHandlerInterface` без custom resolver;
- [x] custom convention resolver остаётся доступен;
- [x] `PropertyResolverInterface` отсутствует;
- [x] ValuePipeline/fallback subsystem удалён;
- [x] специализированные Map* восстановлены;
- [x] generic `MapRequest` работает через тот же request mapping path;
- [x] request provenance не загрязняет generic DI public API;
- [x] mapped source guard работает до lazy/proxy creation;
- [x] v4 Cast default semantics восстановлены;
- [x] v4 Env type conversion восстановлен;
- [x] Proxy parameter/property/class semantics восстановлены;
- [x] `ClassDefinition` runtime override normalization восстановлена;
- [x] package ConfigProvider discovery восстановлен;
- [x] custom resolver dev/AOT parity покрыт;
- [x] custom parameter handler dev/AOT parity покрыт;
- [x] custom object handler dev/AOT parity покрыт;
- [x] generated shard является thin ObjectPipeline delegate;
- [x] semantic fingerprint включает resolver/attribute registrations;
- [x] persistent cache version fail-closed;
- [x] README EN/RU описывают фактическую архитектуру;
- [x] PHP 8.4 CI проходит;
- [x] PHP 8.5 CI проходит.

---

# 53. Архитектура в одной схеме

```text
                          Container
                             |
              +--------------+--------------+
              |                             |
            make()                         call()
              |                             |
        ObjectPipeline                CallableExecutor
              |                             |
              |                       ParametersResolver
              |                             |
      InstanceCreator ----------------------+
              |
        ParametersResolver
              |
              v
    ParameterResolverInterface[]
              |
      +-------+-------------------------------+
      |       |             |                 |
   explicit  typed   AttributeParameter   RequestContext
                       Resolver
                          |
                          v
                    AttributePlan
                          |
                          v
              ParameterAttributeHandler
                          |
       Cast / Config / Env / EntryId /
       CurrentUser / Make+Proxy / Request


Object attribute path
---------------------

ReflectionClass/Property/Method
              |
              v
      AttributePlanBuilder
              |
              v
        AttributeProcessor
              |
              v
      AttributeHandlerInterface


AOT
---

compiled shard -> same ObjectPipeline
                    |
              same resolvers
              same handlers
              same plans semantics
```

Это является финальной архитектурной моделью `componenta/di` v5.
