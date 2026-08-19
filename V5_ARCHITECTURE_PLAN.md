# Componenta DI v5 — финальная архитектурная спецификация

> Статус: реализованная архитектура ветки `v5`.
>
> Baseline совместимости: фактическое поведение `main`/v4, если возможность не заменена явным более детерминированным v5 API.
>
> Главный инвариант: параметры имеют ровно один `ParameterResolverInterface` pipeline; атрибуты class/property/method имеют один `AttributeProcessor`; parameter attributes входят в parameter pipeline только через один `AttributeParameterResolver`.

---

# 1. Цели v5

V5 должен одновременно:

1. сохранить функциональные возможности v4;
2. убрать отдельную dev/prod реализацию DI semantics;
3. сделать композицию атрибутов валидируемой до исполнения;
4. сохранить custom parameter resolution;
5. сохранить custom attribute execution;
6. не иметь публичного transport-specific resolution context;
7. не иметь глобального fallback/value pipeline;
8. позволять нескольким совместимым атрибутам работать на одном target.

Итоговая модель разделяет четыре ответственности:

```text
ParametersResolver
    orchestration parameter resolver chain

AttributePlanBuilder
    composition + validation + ordering metadata

AttributeParameterResolver
    bridge AttributePlan -> parameter handlers

AttributeProcessor
    execution class/property/method handlers
```

---

# 2. Неподлежащие нарушению инварианты

## 2.1. Parameter resolution

1. Любой constructor/callable parameter разрешается только `ParameterResolverInterface`.
2. `ParametersResolver` сам не знает `Config`, `Env`, `Cast`, HTTP и другие attribute semantics.
3. `ArrayResolver` и `ArrayTypedResolver` не знают ни одного конкретного атрибута.
4. Parameter attribute никогда не исполняется напрямую из `ObjectPipeline`.
5. Все parameter attributes входят в resolver chain через один `AttributeParameterResolver`.
6. Custom convention resolver без атрибута остаётся first-class extension point.
7. Result каждого resolver-а проходит `ParameterResolutionResult` validation.
8. Resolver chain immutable после `seal()`.
9. `supports()` не может мутировать chain.

## 2.2. Attribute composition

1. `AttributeDefinition` — единственный semantic registration одного attribute class.
2. `AttributePlanBuilder` валидирует target, cardinality, requires/forbids, custom rules и ordering.
3. Capability inheritance учитывается одинаково в `AttributePlanBuilder`, `AttributeSet` и `AttributePlan`.
4. `ValueProvider` — единственный source slot на target.
5. `ValueTransformer` — отдельная capability и не занимает source slot.
6. Один source + transformer(s) допустимы.
7. Два несовместимых source attributes дают `AttributeCompositionException`.
8. Ordering задаётся semantic constraints; built-in `Cast` выполняется после `ValueProvider`.
9. AOT вызывает ту же composition validation до записи shard.

## 2.3. Attribute execution

1. `PropertyResolverInterface` отсутствует.
2. Class/property/method handlers реализуют `AttributeHandlerInterface`.
3. Parameter-only handlers реализуют отдельный `ParameterAttributeHandlerInterface`.
4. `ParameterAttributeHandlerInterface` не наследует `AttributeHandlerInterface`.
5. Parameter-only handler не обязан иметь фиктивный `handle()`.
6. Handler, работающий и с parameter, и с property/class/method, явно реализует оба интерфейса.
7. `AttributeProcessor` выполняет только `AttributeHandlerInterface`.
8. `AttributeParameterResolver` выполняет только `ParameterAttributeHandlerInterface`.

## 2.4. Public context

1. `FactoryInterface::make(string, array $params = [])`.
2. `CallableInvokerInterface::call(..., array $params = [])`.
3. `Container::make()` и `Container::call()` принимают обычный array.
4. `ResolutionContext` отсутствует.
5. Нет global `explicit`, `mapped`, `trusted` categories.
6. PSR-7 request передаётся обычным typed key.
7. Request provenance остаётся internal HTTP metadata.

## 2.5. Dev / AOT parity

1. Reflection и AOT используют один `ObjectPipeline`.
2. Reflection и AOT используют один `ParametersResolver`.
3. Reflection и AOT используют один `AttributeParameterResolver`.
4. Reflection и AOT вызывают те же handler implementations.
5. Custom resolver/handler не требует production code generator.
6. Stale semantic fingerprint отклоняется.
7. Invalid composition должна падать до записи AOT shard.

---

# 3. Финальные public contracts

## 3.1. Factory

```php
interface FactoryInterface
{
    public function make(string $entry, array $params = []): object;
}
```

`$params` поддерживает:

- parameter name;
- numeric position;
- declared class/interface key;
- framework object key, например `ServerRequestInterface::class`.

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

## 3.3. ParameterResolverInterface

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

`null` означает «продолжить chain».

## 3.4. AttributeHandlerInterface

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

Используется только для class/property/method execution.

## 3.5. ParameterAttributeHandlerInterface

```php
interface ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue;
}
```

Контракт самостоятельный и не наследует object handler.

## 3.6. ParameterAttributeValue

```php
final readonly class ParameterAttributeValue
{
    public bool $resolved;
    public mixed $value;

    public static function unresolved(): self;
    public static function resolved(mixed $value): self;
}
```

Это внутренний immutable state только одной операции композиции parameter attributes. Это не публичный `ResolutionContext`, не fallback registry и не глобальный `ValuePipeline`.

---

# 4. Финальная parameter resolver chain

```text
priority 1200  AttributeParameterResolver
priority 1100  ArrayResolver
priority 1000  ArrayTypedResolver
priority  800  RequestContextResolver
priority  300  AutowireByTypeResolver
priority  200  DefaultValueResolver
priority  100  NullableResolver
```

Причина, почему attribute resolver выше raw explicit resolvers: только он знает, является ли caller value конечным значением, input для transformer-а или запрещён authoritative source-ом.

## 4.1. ArrayResolver

Ответственность только:

```text
provided by name
provided by numeric position
```

Никаких проверок `Cast`, `CurrentUser` или других attributes.

## 4.2. ArrayTypedResolver

Ответственность только:

```text
provided object by declared class/interface key
```

Также attribute-agnostic.

## 4.3. AttributeParameterResolver

Алгоритм:

```text
ParameterTarget
    -> AttributePlanBuilder::build()
    -> определить initial ParameterAttributeValue
    -> пройти plan usages в semantic order
    -> вызвать ParameterAttributeHandlerInterface handlers
    -> получить final ParameterAttributeValue
    -> вернуть [$position, $value]
```

Initial value:

1. если plan содержит `AuthoritativeValueProvider` — unresolved;
2. иначе caller value по имени;
3. иначе caller value по позиции;
4. иначе compatible typed caller object;
5. иначе unresolved.

Таким образом `AttributeParameterResolver` не знает конкретных классов `CurrentUser`, `Cast`, `Config` и т. д. Он работает только с capabilities и handlers.

## 4.4. RequestContextResolver

Отдельный resolver нужен только для non-attribute request context, например:

```php
UriInterface $uri
```

Request-source attributes относятся к `AttributeParameterResolver`.

## 4.5. Custom convention resolver

Custom `ParameterResolverInterface` остаётся для semantics без attribute marker:

```php
final class TenantResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'tenant';
    }
}
```

---

# 5. Parameter attribute composition

## 5.1. Source + transformer

Валидная комбинация:

```php
#[QueryParam('count'), Cast('int')]
int $count
```

Flow:

```text
unresolved
    -> RequestAttributeHandler
    -> resolved('41')
    -> CastHandler
    -> resolved(41)
```

`Cast` definition содержит `after: [ValueProvider::class]`, поэтому даже:

```php
#[Cast('int'), QueryParam('count')]
```

исполняется как source → transformer.

## 5.2. Explicit input + transformer

```php
#[Cast('int')]
int $count
```

с:

```php
['count' => '41']
```

Flow:

```text
AttributeParameterResolver seeds resolved('41')
    -> CastHandler
    -> resolved(41)
```

`ArrayResolver` при этом не знает о `Cast`.

## 5.3. Normal source + explicit override

Например `#[Config]` с caller value:

```text
AttributeParameterResolver seeds caller value
    -> ConfigHandler sees resolved state
    -> leaves it unchanged
```

Это сохраняет v4 precedence обычного caller override над Config/Env/Make.

## 5.4. Authoritative source

`#[CurrentUser]` registered как `AuthoritativeValueProvider`.

Flow:

```text
caller value ignored at initial seed stage
    -> CurrentUserHandler resolves authenticated user
```

Array resolvers не содержат специальных исключений.

## 5.5. Source conflict

```php
#[QueryParam('value'), Header('X-Value')]
string $value
```

Оба являются `ValueProvider`.

`CapabilityPolicy(ValueProvider::class, 1)` вызывает `AttributeCompositionException` ещё при построении plan.

## 5.6. Handler conflict beyond capabilities

Для parameter target `AttributePlanBuilder` дополнительно проверяет parameter-aware source handlers. Разные non-transformer handlers не могут одновременно владеть source semantics.

Несколько definitions могут ссылаться на один source handler, если это одна связанная семантика. Пример:

```text
#[Make] + #[Proxy]
```

оба обслуживаются одним `MakeHandler`.

---

# 6. Built-in handlers

```text
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
SetUpRunner
```

## 6.1. Dual-surface handlers

Следующие handlers реализуют оба интерфейса:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler
```

Они имеют parameter и property/class semantics.

## 6.2. Parameter-only handler

`RequestAttributeHandler` реализует только `ParameterAttributeHandlerInterface`.

У него нет `handle()` и нет фиктивного `LogicException` для object targets.

## 6.3. Object-only handlers

`InjectHandler`, `InitHandler`, `LazyHandler`, `NoConstructorHandler`, `SetUpRunner` относятся только к object attribute processing.

---

# 7. Property composition

`ValueProvider` и `ValueTransformer` являются разными capabilities также для property.

Для mutable property поддерживается:

```php
#[Config('raw'), Cast('trim')]
public string $value;
```

`AttributeProcessor` выполняет provider первым. `ObjectCreationContext` фиксирует claim property. `CastHandler` видит уже claimed property, читает source value и переписывает его transformed value.

`Cast` без другого source сохраняет собственную v4 semantics: использует object-creation parameters или `Cast::default`; initialized property default сам по себе не становится скрытым новым source.

---

# 8. AttributePlan и capability semantics

`AttributePlanBuilder`:

- инстанцирует только зарегистрированные DI attributes;
- валидирует PHP attribute target mask;
- корректно обрабатывает promoted reflection duplicates;
- проверяет capability cardinality;
- проверяет parameter source handler conflicts;
- проверяет requires/forbids;
- вызывает custom composition rules;
- выполняет topological ordering;
- обнаруживает ordering cycles;
- кеширует named targets;
- использует `WeakMap` для anonymous closure parameters.

`AttributeSet` и `AttributePlan` используют inheritance-aware capability matching.

`AttributePlanBuilder::FORMAT_VERSION` увеличивается при изменении semantic planning rules, поэтому AOT fingerprint меняется.

---

# 9. AttributeProcessor

Обрабатывает только:

```text
ReflectionClass
ReflectionProperty
ReflectionMethod
```

Execution flow:

```text
AttributePlan
    -> usages with handler instanceof AttributeHandlerInterface
    -> before phase
    -> after phase
```

Parameter-only handlers игнорируются `AttributeProcessor`.

Private properties и private methods родителей включаются в execution plan.

---

# 10. PSR-7 request subsystem

Request передаётся как:

```php
[
    ServerRequestInterface::class => $request,
]
```

Все request attributes обслуживаются одним `RequestAttributeHandler`:

```text
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
MapRequest
```

## 10.1. Mapping pipeline

```text
extract raw sources
    -> conflict merge
    -> raw validation
    -> map
    -> cast
    -> defaults
    -> sortMap
    -> exclude
    -> DTO construction
```

## 10.2. Request provenance

Nested DTO mapping добавляет internal `MappedRequestContext` metadata.

`MappedRequestParameterSourceGuard` выполняется до resolver priority и до lazy/proxy creation.

Mapped payload не может spoof:

- explicitly sourced parameter;
- request object;
- URI;
- typed object source;
- source-bound parameter через ClassDefinition/alias/lazy/AOT boundary.

Internal marker удаляется из обычных object/property parameters.

---

# 11. Object creation pipeline

```text
ObjectPipeline::create()
    -> cached ObjectMetadata
    -> AttributeProcessor BEFORE
    -> creation strategy
    -> InstanceCreator
    -> ParametersResolver
    -> object initialization
    -> AttributeProcessor AFTER
    -> object
```

Reflection entry и compiled entry используют этот же pipeline.

`ObjectCreationContext` хранит только state одной object creation operation:

- constructor enabled;
- creation strategy;
- initialized entry;
- ordinary parameters;
- internal resolution parameters with request provenance;
- claimed property set.

Он не является public factory context.

---

# 12. Lazy / Proxy / Make

`#[Lazy]` выбирает native lazy strategy.

`#[Proxy]`:

- на class выбирает proxy creation strategy;
- на parameter/property работает через `MakeHandler`;
- может принимать explicit concrete proxy class.

`#[Make] + #[Proxy]` обслуживаются одним `MakeHandler`, поэтому service id и concrete proxy class могут быть заданы независимо.

Lazy/proxy path не должен обходить request provenance preflight.

---

# 13. SetUp

`#[SetUp]` repeatable.

Setup method вызывается через DI-aware `call()`, поэтому его обычные method parameters идут через тот же `ParametersResolver`.

Descriptors внутри `SetUp::params` сохраняют v4 behavior:

```text
Config
Env
EntryId
ContainerValue
```

---

# 14. Factory ABI

Public factory:

```php
callable(ContainerValue|ContainerInterface, array $params): mixed
```

Допустимы compatible сокращённые signatures.

`FactorySpecificationValidator` заранее отклоняет incompatible required arguments и invalid method factories.

---

# 15. ClassDefinition

`ClassDefinition` остаётся declarative definition.

Runtime override normalization выполняется по:

```text
parameter name
parameter position
declared object/interface type
```

После normalization значение всё равно проходит обычный `ParametersResolver`; отдельной hidden resolution implementation нет.

Persistent cache использует тот же path.

---

# 16. Extension materialization

Поддерживаются:

```text
instance
service id
Closure factory
callable factory
[serviceId, method]
```

String service id имеет container ownership semantics; service-method factory валидируется как callable после materialization.

---

# 17. ContainerBuilder configuration

Dependency keys:

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

Integer keys `parameter_resolvers` — priorities и не переиндексируются.

Custom user resolver priorities должны быть уникальны в builder registration.

`replaceParameterResolvers()` отключает default chain.

`replaceAttributeDefinitions()` отключает built-in definitions.

---

# 18. Package discovery

`Componenta\DI\ConfigProvider` экспортируется через:

```text
extra.componenta.config-providers
```

Built-in resolver/attribute registrations собираются самим `ContainerBuilder`, поэтому package ConfigProvider не дублирует runtime registrations.

---

# 19. AOT

Generated factory method имеет только thin delegation:

```php
public function createEntry(array $params = []): object
{
    return $this->objects->create(Target::class, $params);
}
```

AOT prepare вызывает тот же `ObjectPipeline::prepare()` и тот же `AttributePlanBuilder`.

Следовательно:

- invalid composition падает до shard write;
- source + transformer semantics совпадают dev/prod;
- custom parameter handler работает без code generator;
- custom object handler работает без code generator.

Semantic fingerprint включает:

- attribute plan format;
- definition versions;
- handler classes;
- phases;
- capabilities;
- requires/forbids/before/after;
- custom rule semantics;
- capability policies;
- ordered parameter resolver chain.

---

# 20. Persistent cache

Current cache format:

```text
ContainerBuilder::CACHE_VERSION = 16
```

Envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Older/unknown versions отклоняются fail-closed.

---

# 21. Удалённые архитектурные поверхности

Не должны возвращаться:

```text
ResolutionContext
CallableExecutor::execute()
ValuePipeline
ValueContext
ValueResult
ValueFallbackInterface
ValueFallbackRegistry
ExplicitValueFallback
MappedValueFallback
TrustedValueFallback
AutowireValueFallback
DefaultValueFallback
NullableValueFallback
ValueProviderHandlerInterface
ValueTransformerHandlerInterface
CreationStrategyHandlerInterface
ConstructorPolicyHandlerInterface
LifecycleHookHandlerInterface
PropertyResolverInterface
CastableResolver as parameter resolver
ConfigAttributeResolver as parameter resolver
EnvResolver as parameter resolver
EntryIdResolver as parameter resolver
CurrentUserResolver as parameter resolver
MakeAttributeResolver as parameter resolver
RequestResolver as attribute-specific parameter resolver
```

---

# 22. Обязательная test matrix

## Public API

- `FactoryInterface::make(..., array)`;
- callable array API;
- v4 attribute constructor named arguments;
- ConfigProvider discovery.

## Parameter resolution

- explicit name;
- explicit position;
- typed explicit;
- custom convention resolver;
- autowire;
- default;
- nullable;
- unsupported variadic/reference diagnostics.

## Parameter attribute composition

- `QueryParam + Cast`;
- reverse declaration `Cast + QueryParam`;
- explicit input + Cast;
- source + source conflict;
- authoritative CurrentUser;
- custom parameter handler without custom resolver;
- dev/AOT parity.

## Object attribute composition

- Config + Cast mutable property;
- Inject/Init source conflicts;
- class/property/method custom handler;
- private inherited properties;
- promoted/readonly behavior;
- static property failure.

## Request

- scalar extractors;
- specialized Map*;
- generic MapRequest;
- source merge conflict;
- raw validation order;
- nested DTO provenance;
- typed key spoof protection;
- ClassDefinition boundary;
- alias/lazy boundary;
- high-priority custom resolver cannot bypass provenance guard.

## Core

- shared get / fresh make;
- aliases;
- delegators;
- external container ownership;
- cycles;
- cross-Fiber concurrent resolution;
- runtime invalidation.

## AOT/cache

- reflection == AOT result;
- invalid composition before shard write;
- stale semantic fingerprint rejected;
- cache version rejected when stale;
- cached ClassDefinition uses same resolver semantics.

---

# 23. Критерии готовности

V5 считается архитектурно готовым только если одновременно выполняются все условия:

1. Parameters всегда идут через `ParameterResolverInterface`.
2. Все parameter attributes идут через один `AttributeParameterResolver`.
3. `ArrayResolver`/`ArrayTypedResolver` не знают concrete attributes.
4. Source + transformer реально работают на одном parameter.
5. Несовместимые sources дают `AttributeCompositionException`.
6. Parameter-only handler не наследует object handler.
7. Class/property/method используют `AttributeProcessor`.
8. Нет `ResolutionContext` и fallback subsystem.
9. Request-specific metadata не попадает в generic public API.
10. Reflection и AOT используют одну runtime semantics.
11. Custom parameter handler не требует custom resolver/codegen.
12. PHPStan max clean.
13. Pest clean.
14. CI PHP 8.4 clean.
15. CI PHP 8.5 clean.
16. README и эта спецификация описывают фактический код.
