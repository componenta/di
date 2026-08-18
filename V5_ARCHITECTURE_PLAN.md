# Componenta DI v5 — целевая архитектура и план миграции

> Статус документа: архитектурная спецификация перед финальным рефакторингом `v5`.
>
> Перепроверено по фактическому коду `main` (v4, baseline `5d0e7d7c2e804dd113df5e0f732c2fff882b3bd1`) и `v5` (HEAD до создания этого документа: `aea4b767ef6438e36b33192adeb70d17c792ac25`).
>
> Этот документ фиксирует не текущее состояние `v5`, а целевое состояние, к которому ветка должна быть приведена. Реализация не считается завершённой, пока не выполнены критерии приёмки в конце документа.

---

## 1. Цель v5

Цель `componenta/di` v5 — сохранить весь фактический функционал v4, убрать исторические ограничения и дублирование dev/prod реализации, добавить детерминированную композицию атрибутов и подготовленную metadata/AOT-модель, но не вводить вторую параллельную систему разрешения значений.

Итоговая архитектура должна иметь два независимых расширяемых runtime-механизма:

1. **`ParameterResolverInterface`** — единственный механизм разрешения constructor/callable/method parameters.
2. **`AttributeHandlerInterface`** — единственный механизм выполнения атрибутов класса, свойства и метода.

Общая attribute composition (`AttributeDefinition`, `AttributePlan`, `requires`, `forbids`, `before`, `after`, cardinality, custom rules) используется обоими механизмами как metadata/validation layer, но **сама не разрешает параметры и не подменяет parameter resolver chain**.

Ключевой принцип AOT: production может заранее подготовить metadata и убрать повторные reflection/supports/composition вычисления, но не должен иметь отдельную семантическую реализацию resolver/handler logic.

---

# 2. Неподлежащие нарушению архитектурные инварианты

## 2.1. Parameter resolution

1. Любой параметр constructor, callable, setup method, factory method и другого DI-aware вызова разрешается **только** через `ParameterResolverInterface`.
2. `ParametersResolver` не имеет собственной логики выбора значения, кроме orchestration resolver chain, проверки target и result validation.
3. `AttributePlanBuilder`, `AttributeProcessor`, `ObjectPipeline`, HTTP mapper и AOT compiler не имеют права напрямую вернуть значение параметра в обход `ParameterResolverInterface`.
4. Custom parameter resolver остаётся first-class extension point.
5. Built-in и custom resolvers используют один контракт в dev и prod.

## 2.2. Attribute processing

1. Не возвращается `PropertyResolverInterface`.
2. Атрибуты класса, свойства и метода исполняются через единый `AttributeHandlerInterface`.
3. Атрибуты параметра участвуют в общей composition/validation model, но исполняются parameter resolver-ом.
4. Один service может одновременно реализовывать `ParameterResolverInterface` и `AttributeHandlerInterface`, если один атрибут имеет parameter и property semantics.
5. Один атрибут имеет одно детерминированное semantic definition; поиск handler-а через runtime linear `supportsAttribute()` больше не нужен.

## 2.3. Public API context

1. `FactoryInterface::make()` принимает обычный `array $params`.
2. `CallableInvokerInterface::call()` / DI-aware `CallableExecutor::call()` принимают обычный `array $params`.
3. `ResolutionContext` не является публичной DI abstraction.
4. Generic DI core не знает о PSR-7 request, URI, HTTP mapped/trusted sources.
5. Request-specific provenance остаётся внутренней частью HTTP resolver/mapping subsystem.

## 2.4. Dev/prod parity

1. Dev и prod должны использовать одну семантику resolver-ов и handler-ов.
2. AOT может компилировать metadata, resolver slots, attribute plans, class graph и factory shard bindings.
3. AOT не должен генерировать альтернативную бизнес-логику custom resolver-а или handler-а.
4. Custom extension не должен требовать отдельного code generator только для production.
5. Если полное устранение Reflection потребует изменения target abstraction, это отдельная optimization phase после достижения parity. Нельзя жертвовать parity ради преждевременного reflection-free codegen.

---

# 3. Повторная сверка v4 → текущий v5

Повторное сравнение `main` и `v5` подтверждает, что текущий `v5` удалил целые extension surfaces v4, а не только старую внутреннюю реализацию.

## 3.1. Что было удалено и должно быть функционально восстановлено

Удалены/заменены:

- `ParameterResolverInterface`;
- `ParameterResolutionContext`;
- `ParameterResolutionResult`;
- расширяемый priority-based `ParametersResolver`;
- `ArrayResolver`;
- `ArrayTypedResolver`;
- `AutowireByTypeResolver`;
- `DefaultValueResolver`;
- `NullableResolver`;
- `CastableResolver`;
- `ConfigAttributeResolver`;
- `CurrentUserResolver`;
- `EntryIdResolver`;
- `EnvResolver`;
- `MakeAttributeResolver`;
- `RequestResolver`;
- `MappedRequestContext`;
- `MappedRequestParameterSourceGuard`;
- `AttributeProcessor`;
- v4 `AttributeHandlerInterface`;
- `AttributeHandlerRegistry`;
- `AttributePhase`;
- `ObjectCreationContext`;
- `RequestMapper`;
- `MapQueryString`;
- `MapRequestPayload`;
- `MapHeaders`;
- `MapCookies`;
- `MapRequestAttributes`;
- `MapServerParams`;
- `MapUploadedFiles`;
- `ConfigProvider` и composer auto-discovery hook;
- значительная часть v4 parity/regression tests.

Не все эти классы обязаны вернуться с идентичной внутренней реализацией, но **каждая возможность и extension point должны иметь проверенный эквивалент**.

## 3.2. Что текущий v5 добавил, но целевая архитектура считает лишним

Текущий `v5` добавил:

- публичный `ResolutionContext`;
- `explicit`, `mapped`, `trusted` как глобальные DI categories;
- HTTP-specific `ResolutionContext::request()`;
- `CallableExecutor::execute()`;
- `ValuePipeline`;
- `ValueFallbackInterface`;
- `ValueFallbackDefinition`;
- `ValueFallbackRegistry`;
- `ExplicitValueFallback`;
- `MappedValueFallback`;
- `TrustedValueFallback`;
- `PropertyInitialValueFallback`;
- `AutowireValueFallback`;
- `DefaultValueFallback`;
- `NullableValueFallback`;
- `ValueProviderHandlerInterface`;
- `ValueTransformerHandlerInterface`;
- `ValueDefaultHandlerInterface`;
- `ValueWrapperHandlerInterface`;
- `CreationStrategyHandlerInterface`;
- `ConstructorPolicyHandlerInterface`;
- `LifecycleHookHandlerInterface`;
- `ValueProviderPrecedence`.

Эти abstractions создали второй путь resolution рядом с v4 parameter resolver/attribute processor model. В целевой архитектуре они удаляются после миграции функционала.

## 3.3. Что из текущего v5 необходимо сохранить

Полезные изменения `v5`, которые не должны быть потеряны:

- `AttributeDefinition`;
- `AttributeDefinitionRegistry`;
- `AttributePlan`;
- `AttributePlanBuilder`;
- `AttributeUsage`;
- `AttributeSet`;
- `AttributeCompositionRuleInterface`;
- semantic capabilities и `CapabilityPolicy` как composition metadata;
- deterministic most-specific matching inherited attribute definitions;
- rejection ambiguous semantic definitions;
- attribute plan caching и registry revision invalidation;
- корректная обработка closure targets через `WeakMap`;
- `ObjectPipeline` как единый object-creation orchestration concept;
- `ObjectMetadata` caching;
- AOT prepare-before-write validation;
- content-addressed compiled factory shards;
- pipeline fingerprint/integrity checks;
- усиленная factory specification validation;
- восстановленная DI-aware `call()` совместимость;
- восстановленная extension form `[service-id, method]`;
- исправленный request mapping порядок `map -> cast -> defaults -> sortMap -> exclude`;
- реальный CI вместо fake archive-only check;
- PHP 8.4/8.5 CI matrix.

---

# 4. Итоговые public interfaces

## 4.1. `FactoryInterface`

Целевой public contract:

```php
interface FactoryInterface
{
    public function make(
        string $entry,
        array $params = [],
    ): object;
}
```

Требования:

- `$params` поддерживает name, position и type/service-id keys в той мере, в которой это поддерживал v4 resolver chain;
- `make()` остаётся fresh-resolution path и не должен незаметно превращаться в `get()`;
- delegator/shared-cache semantics должны остаться совместимы с v4;
- никакого `ResolutionContext` в public signature.

## 4.2. Callable interfaces

Целевые contracts:

```php
interface CallableInvokerInterface
{
    public function call(
        mixed $callable,
        array $params = [],
    ): mixed;
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

`CallableExecutor::execute()` удаляется.

DI-aware execution flow:

```text
callable representation
    -> CallableResolver
    -> reflected/cached parameter targets
    -> ParametersResolver
    -> ParameterResolverInterface[]
    -> CallableInvoker
```

## 4.3. `ParameterResolverInterface`

Целевой contract сохраняет проверенную v4 модель:

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;

    /**
     * @return array{0:int,1:mixed}|null
     */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

Семантика:

- `supports()` — immutable metadata classification, не request-dependent decision;
- `resolveParameter()` — runtime resolution;
- `null` означает «resolver поддерживает этот target в принципе, но не дал значение в текущем вызове — продолжить chain»;
- успешный result всегда `[target position, value]`;
- result проходит централизованную type/position validation.

## 4.4. `AttributeHandlerInterface`

В v5 explicit `AttributeDefinition` заменяет v4 runtime `supportsAttribute()` lookup.

Целевой минимальный contract:

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

`phase`, ordering и semantic rules должны жить в definition/plan, а не в handler service.

Не нужны в итоговом root contract:

- `supportsAttribute()`;
- numeric handler priority;
- отдельные execution interfaces для provider/transformer/creation/lifecycle.

## 4.5. Composition interfaces

Сохраняются:

```php
interface AttributeCapabilityInterface
{
}
```

```php
interface AttributeCompositionRuleInterface
{
    public function validate(
        AttributeUsage $attribute,
        AttributeSet $set,
    ): void;
}
```

Capabilities — только semantic markers. Они **не являются runtime dispatch contracts**.

---

# 5. `ParameterResolutionContext`: только internal state

Публичный `ResolutionContext` удаляется, но внутренний context одной parameter-resolution operation нужен.

Целевая модель близка к v4:

```php
final class ParameterResolutionContext
{
    /** @var array<string|int,mixed> */
    public readonly array $provided;

    /** @var array<int,mixed> */
    public private(set) array $resolved;

    public function __construct(
        array $provided = [],
        array $resolved = [],
    ) {
        // HTTP-specific hidden provenance may be extracted here
        // through request-subsystem helper, but generic public API
        // does not expose mapped/trusted categories.
    }

    public function resolve(int $position, mixed $value): void
    {
        $this->resolved[$position] = $value;
    }
}
```

Важно:

- context создаётся **внутри** `ParametersResolver`;
- `FactoryInterface`, `Container`, callable API не принимают его;
- HTTP provenance не становится generic field типа `trusted`/`mapped`;
- extensions получают обычный, очищенный `provided` array как в v4.

---

# 6. Целевая архитектура `ParametersResolver`

## 6.1. Единственный execution path

```text
ReflectionParameter / cached target
    -> ParameterTarget
    -> attribute composition validation
    -> resolver slots applicable to target
    -> ParameterResolverInterface::resolveParameter()
    -> ParameterResolutionResult::validate()
    -> ParameterResolutionContext::resolve()
```

`ParametersResolver` не вызывает `ValuePipeline` и не содержит built-in resolution rules напрямую.

## 6.2. Registry/order

Вернуть поведение v4:

```php
public function add(
    ParameterResolverInterface $resolver,
    int $priority = 0,
): void;

public function seal(): void;
```

Требования:

- higher priority runs first;
- equal priorities preserve insertion order;
- duplicate resolver instance rejected;
- после `build()` chain sealed;
- supported resolver slots кешируются по target;
- mutation invalidates slot cache/revision до seal;
- `supports()` не имеет права мутировать registry.

## 6.3. Роль numeric priority после появления composition

Numeric priority остаётся **только ordering mechanism разных parameter resolver strategies**.

Numeric priority больше не должен означать:

> «если два attribute sources конфликтуют, resolver с большим числом победит».

Конфликты/зависимости между атрибутами выражаются через composition model.

На первом этапе миграции относительный порядок v4 необходимо сохранить как parity safety net:

```text
Castable       1200
Array          1100
ArrayTyped     1000
CurrentUser     900
Request         800
Make            700
Env             600
EntryId         500
Config          400
Autowire        300
Default         200
Nullable        100
```

После полной parity suite допускается упрощение чисел/категорий, но только если тестами доказано отсутствие behavioral change.

## 6.4. Почему composition не отменяет resolver chain

Composition отвечает на вопросы:

- допустимы ли эти атрибуты вместе;
- требуется ли один атрибут другому;
- запрещена ли комбинация;
- есть ли cycle ordering;
- сколько атрибутов semantic category разрешено;
- в каком semantic порядке их рассматривать.

Resolver chain отвечает на другой вопрос:

- какой механизм способен получить фактическое runtime value для parameter.

Пример без атрибутов:

```text
provided value
    -> custom convention resolver
    -> autowire by type
    -> PHP default
    -> nullable
```

Это не attribute composition и должно остаться resolver pipeline.

---

# 7. `ParameterTarget` и parameter attributes

## 7.1. Сохранить v4 capabilities target-а

`ParameterTarget` должен по-прежнему давать custom resolver-у:

- reflection parameter (на первом этапе parity);
- name;
- position;
- type/typeNames/className;
- allowsNull;
- default availability/default value;
- variadic/by-reference flags;
- declaring context;
- `hasAttribute()`;
- `firstAttribute()`;
- declared-type `accepts()`.

Это важно для сохранения custom resolver functionality v4.

## 7.2. Добавить composition plan, не заменяя raw attributes

Целевой v5 может добавить к `ParameterTarget` или его factory доступ к immutable `AttributePlan`.

При этом raw/native attribute access не должен исчезнуть: v4 custom resolver мог распознавать свой attribute самостоятельно.

Предпочтительная модель:

```text
ParameterTarget
    - raw parameter metadata (v4-compatible capability)
    - raw attribute helpers
    - validated AttributePlan for registered DI semantic attributes
```

Это позволяет:

- не ломать custom parameter resolver use cases;
- использовать общий composition layer;
- постепенно оптимизировать reflection metadata later.

## 7.3. Parameter attribute execution

Атрибут parameter не должен исполняться `AttributeProcessor`.

Пример:

```php
function handle(
    #[Config('db.host')]
    string $host,
) {}
```

Flow:

```text
AttributePlanBuilder validates #[Config]
    -> ParametersResolver
    -> ConfigAttributeResolver::supports($target)
    -> ConfigAttributeResolver::resolveParameter(...)
```

Не допускается:

```text
Parameter
    -> ValuePipeline
    -> ConfigValueProvider
```

## 7.4. Shared service для parameter/property semantics

Если один attribute применим и к parameter, и к property, service может реализовывать оба контракта:

```php
final class ConfigAttributeResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    // parameter path
    public function supports(ParameterTarget $target): bool { ... }
    public function resolveParameter(...): ?array { ... }

    // class/property/method attribute path
    public function handle(...): void { ... }
}
```

То же применимо к `Cast`, `Env`, `EntryId`, `Make`, `CurrentUser` там, где реальные v4 targets это разрешают.

---

# 8. Attribute composition: что сохраняется и как меняется роль

## 8.1. Сохраняемые компоненты

- `AttributeDefinition`;
- `AttributeDefinitionRegistry`;
- `AttributePlan`;
- `AttributePlanBuilder`;
- `AttributeUsage`;
- `AttributeSet`;
- `AttributeCompositionRuleInterface`;
- `AttributeCapabilityInterface`;
- `CapabilityPolicy`.

## 8.2. `AttributeDefinition` как semantic registration

Definition должен описывать:

- attribute class;
- optional runtime handler для class/property/method path;
- execution phase для non-parameter targets;
- semantic capabilities;
- requires;
- forbids;
- before;
- after;
- custom composition rules;
- definition semantic/cache version при необходимости.

Conceptual shape:

```php
final readonly class AttributeDefinition
{
    public function __construct(
        public string $attribute,
        public ?AttributeHandlerInterface $handler = null,
        public AttributePhase $phase = AttributePhase::AfterInstantiation,
        public array $capabilities = [],
        public array $requires = [],
        public array $forbids = [],
        public array $before = [],
        public array $after = [],
        public array $rules = [],
        public int $version = 1,
    ) {}
}
```

Это conceptual contract; exact type aliases/enum names уточняются при реализации без изменения ответственности.

## 8.3. Capabilities не исполняют код

Capabilities нужны для rules/cardinality, например:

- value source;
- value transformer;
- creation strategy;
- constructor policy;
- lifecycle hook.

Но `ObjectPipeline` не должен делать runtime dispatch вида:

```php
if ($handler instanceof CreationStrategyHandlerInterface) { ... }
```

Выполняется обычный `AttributeHandlerInterface`.

## 8.4. Target-dependent semantics

Некоторые attributes имеют разные semantics на разных targets (`Proxy` — главный пример: class-level creation strategy и parameter/property proxy creation modifier/source).

Нельзя насильно кодировать все такие случаи одной глобальной static capability и потом разруливать приоритетом.

Для таких attributes допускаются:

- target-aware custom composition rule;
- несколько semantic rules в definition;
- при необходимости target-aware capability mapping как отдельное улучшение.

До введения нового API сначала восстановить реальное v4 behavior тестами. Не проектировать rule system по предположениям.

---

# 9. Возвращение универсального `AttributeProcessor`

## 9.1. Targets

`AttributeProcessor` обрабатывает:

- `ReflectionClass`;
- `ReflectionProperty`;
- `ReflectionMethod`.

Он **не разрешает `ReflectionParameter` values**.

## 9.2. Новый execution model

В v4 processor выполнял runtime search через `supportsAttribute()`. В v5 это можно упростить:

```text
reflection target
    -> AttributePlanBuilder
    -> ordered AttributeUsage[]
    -> AttributeUsage.definition.handler
    -> handler->handle()
```

Преимущества:

- нет linear scan handlers на каждый attribute;
- нет ambiguous handler selection;
- explicit semantic registration;
- один plan используется для validation/order/execution;
- одинаковая metadata basis для dev/AOT.

## 9.3. Execution phases

Сохранить v4 минимум:

- `BeforeInstantiation`;
- `AfterInstantiation`.

Phase принадлежит semantic definition, не handler instance.

## 9.4. Method attributes

Method attribute processing должно вернуться полностью. Текущий `AttributePlanBuilder` умеет строить method plan, но current `ObjectPipeline` не предоставляет универсальный method execution path, эквивалентный v4 processor.

---

# 10. `ObjectCreationContext`

`ObjectCreationContext` должен быть восстановлен/переработан как mutable state **одной** object creation operation.

Он нужен generic attribute handlers и не является public request context.

Минимальные responsibilities:

- текущий object/class;
- provided constructor/context params;
- выбранная creation strategy;
- constructor enabled/disabled state;
- property ownership/claiming;
- safe property read/write helpers;
- object lifecycle state;
- доступ к необходимым DI services через injected handlers, а не глобальные static calls.

Нужно сохранить защиту от двух handlers, которые молча независимо записывают одно property.

---

# 11. Built-in parameter resolver parity

Функционально должны быть восстановлены все v4 resolver strategies.

## 11.1. Explicit/name/position values

`ArrayResolver`:

- `$params[$parameterName]`;
- `$params[$position]`;
- declared type validation;
- exact precedence совместимая с v4.

## 11.2. Type-keyed values

`ArrayTypedResolver`:

- lookup по class/interface key;
- generic mechanism, без знания про HTTP;
- `ServerRequestInterface::class => $request` — просто один из typed values.

## 11.3. Attribute-aware resolvers

Восстановить functional equivalents:

- `CastableResolver`;
- `CurrentUserResolver`;
- `RequestResolver`;
- `MakeAttributeResolver`;
- `EnvResolver`;
- `EntryIdResolver`;
- `ConfigAttributeResolver`.

Они используют common attribute composition metadata для validation, но value возвращают только через `ParameterResolverInterface`.

## 11.4. Conventional built-ins

Восстановить:

- `AutowireByTypeResolver`;
- `DefaultValueResolver`;
- `NullableResolver`.

## 11.5. Custom resolver

Custom package должен иметь возможность:

```php
$builder->addParameterResolver(
    MyParameterResolver::class,
    priority: 750,
);
```

и получить одинаковую semantics в dev/prod.

---

# 12. Built-in attributes: parity requirements

Каждый attribute проверяется по реальному v4 code/tests, а не только по текущему README.

## 12.1. `Cast`

Восстановить constructor contract:

```php
new Cast(
    name: 'int',
    default: ...,
)
```

Текущий v5 потерял `default`.

Parameter path — `ParameterResolverInterface`.

Property path — `AttributeHandlerInterface` (может быть тот же shared `CastableResolver`).

## 12.2. `Inject`

Property-only behavior сохраняется.

Constructor/callable parameter injection без attribute выполняется autowire resolver-ом.

## 12.3. `Init`

Property-only behavior сохраняется.

Callable внутри `Init` вызывается через обычный DI-aware `call()` и standard parameter resolver chain.

## 12.4. `SetUp`

Class-level repeatable lifecycle attribute.

Setup method parameters разрешаются тем же `ParametersResolver`, что constructor/callable parameters.

SetUp value unwrapping (`ContainerValue`, `EntryId`, `Config`, `Env`) сохраняется.

## 12.5. `Lazy`

Class-level creation behavior сохраняется.

Никакого отдельного `CreationStrategyHandlerInterface`: generic handler меняет `ObjectCreationContext`.

## 12.6. `NoConstructor`

Class-level constructor policy сохраняется через generic attribute handler + composition capability/rule.

## 12.7. `Proxy`

Обязательно восстановить v4 targets:

- class;
- parameter;
- property.

Обязательно поддержать optional concrete proxy class для interface-typed injection.

Комбинация `#[Make(...), Proxy(...)]` должна сохранить v4 semantics.

Текущий v5 class-only `Proxy` — функциональная регрессия.

## 12.8. `Config`, `Env`, `EntryId`, `Make`, `CurrentUser`

Восстановить все v4-supported targets/defaults/constructor arguments и negative behavior.

Parameter execution — resolver chain.

Property execution — attribute handler.

---

# 13. HTTP/request resolution

## 13.1. Boundary

Вся HTTP-specific логика находится в:

```text
Resolver/Parameter/Request/*
```

Generic DI layers не импортируют и не special-case:

- `ServerRequestInterface`;
- `UriInterface`;
- query/body/header/cookie/request attributes/server params/files.

## 13.2. `RequestResolver`

Возвращается как `ParameterResolverInterface`.

Он отвечает за:

- implicit `ServerRequestInterface`/`UriInterface` resolution;
- `QueryParam`;
- `PayloadParam`;
- `Header`;
- `Cookie`;
- `RequestAttribute`;
- `ServerParam`;
- `UploadedFile`;
- `Map*` DTO/array mapping.

## 13.3. Не переносить HTTP в generic typed resolver

`ArrayTypedResolver` может получить request как обычный provided typed value.

Но request-derived semantics выполняет только `RequestResolver`.

---

# 14. `Map*` и request mapper API

## 14.1. Обязательно восстановить public attributes v4

- `RequestMapper`;
- `MapQueryString`;
- `MapRequestPayload`;
- `MapHeaders`;
- `MapCookies`;
- `MapRequestAttributes`;
- `MapServerParams`;
- `MapUploadedFiles`.

Внутренняя реализация может быть общей.

## 14.2. Common mapping pipeline

Сохранить v4 semantics:

```text
extract source(s)
    -> validate raw DTO transport data
    -> field map/aliases
    -> cast
    -> defaults
    -> sortMap
    -> exclude
    -> construct array/DTO
```

Важно: validation DTO raw transport data происходит **до** преобразований, которые могли бы скрыть входные поля/ошибки.

## 14.3. Conflict policy

Сохранить conflict handling между request sources.

Нельзя молча терять provenance при merge.

## 14.4. Generic `MapRequest`

Текущий generic `MapRequest(sources: ...)` не должен заменять специализированные public `Map*`.

После восстановления parity отдельно решить:

- оставить `MapRequest` как дополнительный multi-source low-level attribute, если он даёт уникальную возможность;
- удалить его, если он только дублирует более понятный API.

Это одно из немногих решений, которое разрешено принять после parity, потому что оно не должно блокировать восстановление v4.

---

# 15. HTTP mapped provenance

## 15.1. Зачем provenance реально нужна

Только для защиты mapped DTO transport data от shadowing явно объявленных parameter sources.

Пример: mapped body key `user` не должен незаметно заменить `#[CurrentUser] User $user`.

## 15.2. Правильная модель

Вернуть internal подход v4 типа `MappedRequestContext`:

- hidden/private DI key в обычном parameter transport array;
- metadata содержит mapped field keys/provenance;
- metadata удаляется перед передачей обычного provided array extensions;
- generic DI API про неё не знает.

## 15.3. Source guard

Восстановить functional equivalent `MappedRequestParameterSourceGuard`:

- конфликт с explicit parameter source attributes;
- конфликт с implicit request/URI source;
- одинаковое поведение reflection/compiled.

Удалить `mapped`/`trusted` как глобальные поля `ResolutionContext`.

---

# 16. Полное удаление `ResolutionContext`

Удалить после миграции всех consumers:

```text
src/ResolutionContext.php
```

Удалить:

- `explicit`;
- `mapped`;
- `trusted`;
- `fromLegacyParameters()`;
- `withExplicit()`;
- `withMapped()`;
- `withTrusted()`;
- `visible()`;
- `request()`;
- request-specific constructor/helper signatures.

Вернуть array params во всех public/internal boundaries, где v4 действительно использовал array transport.

---

# 17. Полное удаление `ValueFallback` subsystem

После восстановления resolver/attribute paths удалить:

- `ValueFallbackInterface`;
- `ValueFallbackDefinition`;
- `ValueFallbackRegistry`;
- `ExplicitValueFallback`;
- `MappedValueFallback`;
- `TrustedValueFallback`;
- `PropertyInitialValueFallback`;
- `AutowireValueFallback`;
- `DefaultValueFallback`;
- `NullableValueFallback`;
- `ValueResult` (если больше не нужен).

Обязанности распределяются так:

```text
ExplicitValueFallback       -> ArrayResolver / ArrayTypedResolver
MappedValueFallback         -> request mapper internal transport
TrustedValueFallback        -> удаляется; source-specific resolver
PropertyInitialValueFallback-> конкретный property attribute handler
AutowireValueFallback       -> AutowireByTypeResolver
DefaultValueFallback        -> DefaultValueResolver
NullableValueFallback       -> NullableResolver
```

---

# 18. Полное удаление `ValuePipeline` execution model

Удалить `ValuePipeline` и `ValueContext`, когда:

- все parameters идут через resolver chain;
- все property/class/method attributes идут через generic attribute processor;
- HTTP mapping снова принадлежит RequestResolver subsystem.

После этого проверить dead code:

- `ValueTargetInterface`;
- `PropertyTarget`;
- `PropertyValuePlan`;
- прочие value-only DTOs.

Если они больше не дают самостоятельной ценности — удалить.

---

# 19. Specialized handler interfaces: удалить

После generic handler migration удалить:

- `ValueProviderHandlerInterface`;
- `ValueTransformerHandlerInterface`;
- `ValueDefaultHandlerInterface`;
- `ValueWrapperHandlerInterface`;
- `CreationStrategyHandlerInterface`;
- `ConstructorPolicyHandlerInterface`;
- `LifecycleHookHandlerInterface`;
- `ValueProviderPrecedence`.

Причина: они превращают semantic categories в закрытый runtime dispatch protocol и мешают стороннему package добавить произвольный attribute behavior без изменения DI core.

Semantic capabilities могут остаться, но handler runtime contract должен быть универсальным.

---

# 20. ContainerBuilder/configuration

## 20.1. Restore parameter resolver configuration

Вернуть:

```text
ConfigKey::PARAMETER_RESOLVERS
ConfigKey::PARAMETER_RESOLVERS_REPLACE
```

Builder API:

```php
public function addParameterResolver(
    mixed $resolver,
    int $priority = 0,
): static;

public function replaceParameterResolvers(
    bool $replace = true,
): static;
```

При наличии v4 `addParameterResolvers()`/bulk behavior — восстановить эквивалент.

## 20.2. Attribute definitions как новый v5 extension point

Сохранить:

```text
ConfigKey::ATTRIBUTE_DEFINITIONS
ConfigKey::ATTRIBUTE_CAPABILITIES
```

Добавить explicit replacement switch:

```text
ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE
```

Builder:

```php
addAttributeDefinition(...)
replaceAttributeDefinitions(...)
defineAttributeCapability(...)
```

## 20.3. `ATTRIBUTE_HANDLERS`

Старый `ATTRIBUTE_HANDLERS` может быть сознательно заменён `ATTRIBUTE_DEFINITIONS` как v5 major API, потому что definition является более полным semantic registration.

Но функциональная возможность должна быть не хуже v4:

- custom attribute;
- custom handler;
- target selection через PHP attribute + semantic definition;
- phase;
- ordering/composition;
- service/factory materialization;
- dev/prod parity.

## 20.4. Удалить fallback config

Удалить:

```text
ConfigKey::VALUE_FALLBACKS
ContainerBuilder::addValueFallback()
```

---

# 21. Extension materialization

Для parameter resolvers и attribute definitions/handlers сохранить полезные v4 forms:

1. готовый instance;
2. service id string;
3. closure/callable factory;
4. `[service-id, method]`;
5. callable string, если service id не существует;
6. object method callable там, где применимо.

String precedence:

```text
registered container service id
    -> callable string fallback
    -> configuration error
```

`[service-id, method]` должен сначала resolve service из container и только потом вызывать method.

Result type каждого extension materializer должен проверяться строго на boundary.

---

# 22. Factory/definition validation

Сохранить уже усиленные проверки v5:

- callable shape;
- arity;
- допустимый context/container ABI;
- internal callable rejection там, где он не может быть безопасно invoked;
- class existence/instantiability;
- method existence/public visibility;
- deferred service method validation в максимально ранней доступной точке;
- fail-fast invalid configuration.

Дополнительно провести отдельный v4 parity audit factory signatures по тестам и реальному runtime.

---

# 23. Core container parity

До релиза v5 необходимо проверить и покрыть тестами:

- `get()`;
- `has()`;
- `make()`;
- `set()`;
- `call()`;
- `resolve()`;
- shared `get` vs fresh `make`;
- alias resolution;
- alias cycles;
- delegators;
- alias/delegator cache invalidation;
- external container precedence;
- external container/delegator invalidation;
- factory bindings;
- invokables;
- stored services;
- definitions;
- nested reference definitions;
- class definitions;
- replacement semantics;
- cycle detection;
- Fiber/concurrent shared resolution;
- lazy ghost;
- virtual proxy;
- private constructor cases;
- callable closures;
- closure scope/signature cache;
- magic callables;
- service-id callable precedence;
- protected/core service ids;
- cache graph identity;
- cache envelope/path validation;
- compiled shard path/content integrity.

---

# 24. Core service bindings

Проверить обязательные bindings контейнера:

```text
Psr\Container\ContainerInterface
FactoryInterface
CallableInvokerInterface
CallableResolverInterface
CallableExecutorInterface
LazyObjectFactoryInterface
VirtualProxyFactoryInterface
Container
```

Не должно быть двух разных DI-aware callable execution paths.

---

# 25. ObjectPipeline: сохранить идею, упростить execution

Целевой flow:

```text
Container::make/get
    -> entry resolver
    -> ObjectPipeline
    -> cached ObjectMetadata / reflected metadata
    -> AttributeProcessor BEFORE
    -> determine constructor + eager/lazy/proxy state
    -> InstanceCreator
    -> ParametersResolver
    -> ParameterResolverInterface[]
    -> object
    -> AttributeProcessor AFTER (class/property/method plan)
    -> return object
```

`ObjectPipeline` не разрешает property values через generic `ValuePipeline`.

Property mutations принадлежат attribute handlers.

---

# 26. Creation strategy и constructor policy

`Lazy`, `Proxy`, `NoConstructor` реализуются generic attribute handlers, которые изменяют `ObjectCreationContext`.

Composition capabilities обеспечивают constraints:

- не более одной class creation strategy;
- несовместимые combinations rejected;
- ordering deterministic.

Не нужен отдельный runtime handler interface на каждую policy category.

---

# 27. AOT/compiled architecture

## 27.1. Hard requirement: semantic parity

Compiled path должен выполнять те же resolver/handler implementations, что reflection path.

Нельзя вернуть старую модель:

```text
dev: resolver->resolveParameter()
prod: separate ParameterResolverCodeGenerator reimplements semantics
```

Это основной источник divergence.

## 27.2. Что AOT должен precompute

AOT может/должен precompute:

- autowire class graph;
- entry/class binding;
- attribute definitions selected for each target;
- validated attribute ordering/composition;
- applicable parameter resolver slots;
- immutable class/object metadata;
- compiled shard paths/classes/methods;
- pipeline fingerprint.

## 27.3. Что AOT не должен дублировать

AOT не должен заново кодировать:

- custom resolver business logic;
- custom handler business logic;
- request mapper semantics;
- Config/Env/Cast/CurrentUser semantics;
- lifecycle behavior.

## 27.4. Reflection-free production — optimization, не correctness contract первого этапа

Текущий v4 `ParameterTarget` и v4 generic handler contracts допускают работу с Reflection objects. Поэтому немедленное требование «тот же extension + абсолютно никакого Reflection в prod» может потребовать отдельной target descriptor abstraction.

Правильная последовательность:

1. сначала добиться идентичной semantics через одни resolver/handler objects;
2. cache Reflection-derived metadata, чтобы reflection не повторялся на hot path;
3. измерить bottlenecks;
4. при реальной необходимости ввести immutable compiled target descriptors;
5. не вводить второй codegen semantic implementation.

## 27.5. Custom extensions в prod

Custom `ParameterResolverInterface` и `AttributeHandlerInterface` должны работать в compiled mode без обязательного custom code generator.

Если часть metadata custom extension невозможно precompute, допустим cached runtime metadata fallback, но **не изменение результата/порядка/исключений**.

---

# 28. Pipeline fingerprint/cache invalidation

Fingerprint должен учитывать минимум:

- ordered parameter resolver registrations;
- resolver class/spec identity;
- resolver priority/order;
- attribute definitions;
- selected handler/spec identity;
- definition version;
- phase;
- capabilities;
- requires/forbids/before/after;
- custom rule identity/config;
- cache format version.

Built-in semantic changes требуют bump соответствующей metadata/cache version.

Custom resolver/handler не обязан реализовывать дополнительный runtime execution interface ради fingerprint. Если semantic versioning понадобится, оно оформляется как registration metadata, а не как второй execution contract.

---

# 29. Compiled shards

Сохранить текущие улучшения v5:

- content-addressed shard files;
- integrity/content verification;
- pipeline fingerprint verification;
- validate/prepare полностью до записи shard;
- fail-closed stale/incompatible shard handling.

После изменения architecture обязательно bump `ContainerBuilder::CACHE_VERSION`/compiled format version.

---

# 30. ConfigProvider / componenta/config integration — обязательная отдельная проверка

Текущий v5 удалил `src/ConfigProvider.php` и composer auto-discovery hook, существовавшие в v4.

До финального релиза обязательно проверить реальный код последних совместимых версий:

- `componenta/config`;
- `componenta/app`;
- package ConfigProvider discovery/composition.

После проверки принять одно явное решение:

1. восстановить `ConfigProvider` уже для новой v5 architecture; или
2. доказать, что integration moved elsewhere и auto-provider больше не нужен.

Случайное удаление integration contract недопустимо.

---

# 31. Что восстановить — точный checklist

## Parameter subsystem

- [ ] `ParameterResolverInterface`.
- [ ] `ParameterResolutionContext`.
- [ ] `ParameterResolutionResult`.
- [ ] priority-based `ParametersResolver`.
- [ ] `add()` / seal / revision / supports-slot cache.
- [ ] custom resolver registration/materialization.
- [ ] replace built-ins option.
- [ ] `ArrayResolver` behavior.
- [ ] `ArrayTypedResolver` behavior.
- [ ] `CastableResolver` parameter behavior.
- [ ] `CurrentUserResolver` parameter behavior.
- [ ] `RequestResolver`.
- [ ] `MakeAttributeResolver` parameter behavior.
- [ ] `EnvResolver` parameter behavior.
- [ ] `EntryIdResolver` parameter behavior.
- [ ] `ConfigAttributeResolver` parameter behavior.
- [ ] `AutowireByTypeResolver`.
- [ ] `DefaultValueResolver`.
- [ ] `NullableResolver`.

## Attribute subsystem

- [ ] generic `AttributeHandlerInterface` execution.
- [ ] `AttributePhase` equivalent.
- [ ] `AttributeProcessor` for class/property/method.
- [ ] `ObjectCreationContext`.
- [ ] class attribute execution.
- [ ] property attribute execution.
- [ ] method attribute execution.
- [ ] custom handler extension parity.
- [ ] definition-based deterministic handler binding.
- [ ] composition validation for parameter attributes without direct execution.

## Built-in attributes

- [ ] `Cast` default.
- [ ] `Config` v4 constructor/default semantics.
- [ ] `Env` semantics.
- [ ] `EntryId` semantics.
- [ ] `Make` semantics.
- [ ] `CurrentUser` semantics.
- [ ] `Inject` property behavior.
- [ ] `Init` property behavior.
- [ ] `SetUp` repeat/order/params/unwrapping.
- [ ] `Lazy`.
- [ ] `Proxy` class/parameter/property + concrete class.
- [ ] `NoConstructor`.

## HTTP

- [ ] `RequestResolver`.
- [ ] `QueryParam`.
- [ ] `PayloadParam`.
- [ ] `Header`.
- [ ] `Cookie`.
- [ ] `RequestAttribute`.
- [ ] `ServerParam`.
- [ ] `UploadedFile`.
- [ ] `RequestMapper`.
- [ ] `MapQueryString`.
- [ ] `MapRequestPayload`.
- [ ] `MapHeaders`.
- [ ] `MapCookies`.
- [ ] `MapRequestAttributes`.
- [ ] `MapServerParams`.
- [ ] `MapUploadedFiles`.
- [ ] raw validation before transform.
- [ ] `map -> cast -> defaults -> sortMap -> exclude`.
- [ ] source conflict policy.
- [ ] mapped provenance/source guard.

## Container/core

- [ ] all public v4 core behavior matrix.
- [ ] extension service method forms.
- [ ] service-id precedence.
- [ ] aliases/delegators/external containers.
- [ ] definitions/factories/invokables.
- [ ] lazy/proxy/private constructor.
- [ ] callables/magic/closures.
- [ ] cycles/fibers/cache invalidation.
- [ ] cache/compiled shard validation.

---

# 32. Что сохранить — точный checklist

- [ ] `AttributeDefinition` concept.
- [ ] `AttributeDefinitionRegistry`.
- [ ] `AttributePlan`.
- [ ] `AttributePlanBuilder`.
- [ ] `AttributeUsage`.
- [ ] `AttributeSet`.
- [ ] custom composition rules.
- [ ] capability policies.
- [ ] deterministic inheritance matching.
- [ ] ambiguity rejection.
- [ ] plan cache/revision invalidation.
- [ ] closure-safe plan caching.
- [ ] `ObjectPipeline` orchestration concept.
- [ ] object metadata caching.
- [ ] AOT prepare-before-write.
- [ ] content-addressed shards.
- [ ] fingerprint/integrity checks.
- [ ] hardened factory validation.
- [ ] restored `[service-id, method]` extension materialization.
- [ ] real CI PHP 8.4/8.5.

---

# 33. Что удалить — точный checklist

После переноса consumers удалить:

- [ ] `ResolutionContext`.
- [ ] `CallableExecutor::execute()`.
- [ ] `CallableExecutorInterface::execute()`.
- [ ] `ValuePipeline`.
- [ ] `ValueContext`.
- [ ] `ValueFallbackInterface`.
- [ ] `ValueFallbackDefinition`.
- [ ] `ValueFallbackRegistry`.
- [ ] все seven built-in value fallback classes.
- [ ] `ValueProviderPrecedence`.
- [ ] `ValueProviderHandlerInterface`.
- [ ] `ValueTransformerHandlerInterface`.
- [ ] `ValueDefaultHandlerInterface`.
- [ ] `ValueWrapperHandlerInterface`.
- [ ] `CreationStrategyHandlerInterface`.
- [ ] `ConstructorPolicyHandlerInterface`.
- [ ] `LifecycleHookHandlerInterface`.
- [ ] `ConfigKey::VALUE_FALLBACKS`.
- [ ] `ContainerBuilder::addValueFallback()`.
- [ ] `ValueTargetInterface`, `PropertyTarget`, `PropertyValuePlan` если после migration они не имеют самостоятельных consumers.
- [ ] obsolete tests, которые закрепляют удаляемую `ResolutionContext/ValueFallback` architecture вместо desired behavior.

---

# 34. Tests: новая parity strategy

## 34.1. Нельзя удалять v4 tests без эквивалента

Для каждого удалённого v4 regression/contract test необходимо одно из двух:

1. вернуть его почти без изменений; или
2. создать новый v5 test, который проверяет тот же behavioral invariant.

Удаление старого класса/API допустимо только после появления теста на сохранённую возможность.

## 34.2. Рекомендуемая структура parity suite

```text
tests/V5Parity/Parameter/*
tests/V5Parity/Attribute/*
tests/V5Parity/Request/*
tests/V5Parity/Container/*
tests/V5Parity/Factory/*
tests/V5Parity/Callable/*
tests/V5Parity/Lazy/*
tests/V5Parity/Cache/*
tests/V5Parity/Aot/*
```

## 34.3. Четыре execution modes

Ключевые scenarios прогонять минимум в:

1. reflection/dev;
2. rebuilt/cached runtime;
3. compiled/prod;
4. compiled cache reload.

Сравнивать:

- result value;
- result object graph;
- exception class;
- exception boundary/meaningful message;
- selected resolver behavior;
- attribute execution order;
- lifecycle side effects;
- request mapping result;
- shared/fresh identity semantics.

## 34.4. Custom extension parity

Обязательные tests:

- custom parameter resolver instance;
- resolver service id;
- resolver factory closure;
- resolver `[service-id, method]`;
- resolver priority;
- equal-priority insertion order;
- replace built-in resolvers;
- custom attribute definition;
- custom handler;
- custom composition rule;
- inherited custom attribute;
- repeatable attribute;
- custom resolver/handler in compiled mode **без custom code generator**.

---

# 35. CI

Final CI должен реально выполнять минимум:

```text
PHP 8.4
  composer validate
  composer install
  composer check

PHP 8.5
  composer validate
  composer install
  composer check
```

`composer check` должен включать:

- coding standard;
- static analysis;
- full Pest test suite.

После крупных phases проверять exact pushed HEAD, а не только локальный tree.

---

# 36. Performance/optimization phase

Optimization начинается **после parity**, не вместо неё.

## Dev hot path goal

После первого metadata build:

- не повторять ReflectionClass/Parameter scanning без необходимости;
- не повторять `supports()` scan для неизменного target;
- не перестраивать AttributePlan;
- кешировать callable target/signature metadata корректно для closures.

## Prod hot path goal

- использовать precomputed class graph/shard metadata;
- использовать precomputed attribute plan/order;
- использовать precomputed resolver slots;
- не выполнять build-time validation на каждом request;
- по возможности не выполнять reflection, но только если это не создаёт второй execution implementation.

## Benchmarks

Измерить отдельно:

- first resolution dev;
- hot resolution dev;
- compiled first resolution;
- compiled hot resolution;
- container `get` shared;
- `make` fresh;
- callable invocation;
- attribute-heavy DTO;
- request mapper DTO.

Оптимизация считается полезной только при измеримом выигрыше и сохранённой parity suite.

---

# 37. Порядок реализации

## Phase A — зафиксировать parity tests до массового удаления

1. Восстановить/адаптировать v4 behavioral tests.
2. Создать matrix lost functionality.
3. Не удалять current v5 infrastructure, пока новый path не покрыт тестами.

## Phase B — вернуть public array boundaries

1. `FactoryInterface::make(string, array)`.
2. `Container::make(..., array)`.
3. `CallableExecutor::call(..., array)`.
4. удалить необходимость `execute()` у callers.
5. временно adapter-ы допустимы только внутри migration commit sequence.

## Phase C — восстановить parameter resolver infrastructure

1. `ParameterResolverInterface`.
2. `ParameterResolutionContext`.
3. `ParameterResolutionResult`.
4. priority registry/list.
5. slot caching.
6. sealing.
7. builder/config extension registration.

## Phase D — вернуть built-in resolvers

Пошагово вернуть и тестировать:

1. Array;
2. ArrayTyped;
3. Autowire;
4. Default;
5. Nullable;
6. Config/Env/EntryId;
7. Make;
8. Cast;
9. CurrentUser;
10. Request.

На этом этапе `ParametersResolver` больше не должен использовать `ValuePipeline` для successful target resolution.

## Phase E — интегрировать composition с parameters

1. `ParameterTarget` получает/связывается с validated `AttributePlan`.
2. все registered parameter attributes проходят composition validation.
3. resolver execution остаётся единственным source of parameter value.
4. ambiguity/conflict tests переводятся с priority accidents на explicit composition rules.

## Phase F — восстановить generic attribute processor

1. вернуть `AttributePhase` equivalent;
2. вернуть `ObjectCreationContext`;
3. создать definition-driven `AttributeProcessor`;
4. class/property/method plans;
5. before/after execution;
6. custom handler tests.

## Phase G — мигрировать built-in class/property handlers

Перенести:

- Inject;
- Init;
- Config/Env/EntryId property behavior;
- Cast property behavior;
- Make/Proxy property behavior;
- CurrentUser property behavior;
- Lazy;
- Proxy class behavior;
- NoConstructor;
- SetUp.

После этого generic `Value*HandlerInterface` больше не нужен.

## Phase H — полностью восстановить HTTP/Map*

1. RequestResolver;
2. request parameter attributes;
3. RequestMapper base API;
4. specialized Map*;
5. raw validation;
6. mapping transforms;
7. conflict rules;
8. mapped provenance guard;
9. dev/prod tests.

## Phase I — удалить `ResolutionContext`

Только после отсутствия consumers.

## Phase J — удалить `ValueFallback`/`ValuePipeline`

Только после отсутствия consumers и green parity suite.

## Phase K — AOT refactor

1. compiler использует тот же resolver registry/attribute definitions;
2. precompute resolver slots/plans;
3. один runtime execution contract;
4. no custom code generator requirement;
5. fingerprint update;
6. cache version bump;
7. stale shard tests.

## Phase L — componenta/config/app integration audit

Проверить ConfigProvider/autodiscovery и исправить integration contract.

## Phase M — full v4 feature audit

Пройти весь main test tree и public API manifest feature-by-feature.

## Phase N — performance pass

Только после correctness.

## Phase O — documentation cleanup

README/README.ru описывают только фактический final v5 API, без migration chatter и без устаревшей v4/internal architecture.

## Phase P — exact HEAD CI verification

PHP 8.4 + 8.5, full `composer check`, exact pushed HEAD.

---

# 38. Acceptance criteria

`v5` можно считать готовой только если одновременно выполнено всё ниже.

## Functional parity

- [ ] Каждая функциональная возможность v4 имеет passing v5 equivalent test.
- [ ] Custom parameter resolver снова first-class extension.
- [ ] Custom attribute handler снова first-class extension.
- [ ] Parameter attributes не обходят resolver chain.
- [ ] Property resolution не возвращено как отдельная subsystem.
- [ ] Все Map* и HTTP mapping possibilities восстановлены.
- [ ] Cast default восстановлен.
- [ ] Proxy parameter/property behavior восстановлен.
- [ ] SetUp/Init/Inject behavior восстановлено.

## Architecture

- [ ] `ResolutionContext` отсутствует в public/runtime architecture.
- [ ] Generic DI core не знает о PSR-7 request.
- [ ] `ValueFallback` subsystem удалена.
- [ ] `ValuePipeline` не используется для parameter resolution.
- [ ] Specialized `Value*HandlerInterface` execution hierarchy удалена.
- [ ] Parameter resolution имеет один execution path.
- [ ] Attribute class/property/method processing имеет один generic execution path.
- [ ] Composition отвечает за semantic relationships, resolver priority — за resolver strategy order.

## Dev/prod parity

- [ ] Dev/prod вызывают одни resolver implementations.
- [ ] Dev/prod вызывают одни handler implementations.
- [ ] Custom extensions работают в compiled mode без custom code generator.
- [ ] Same input -> same value/object graph.
- [ ] Same invalid input/config -> semantically same exception.
- [ ] Attribute order одинаков.
- [ ] Request mapping одинаков.
- [ ] Cache reload не меняет semantics.

## Cache/AOT

- [ ] build validates composition before shard write.
- [ ] shard content/integrity checked.
- [ ] pipeline fingerprint reflects resolver/attribute composition.
- [ ] incompatible cache fails closed.
- [ ] cache/compiled format version bumped after architecture migration.

## Quality

- [ ] no dead migration classes.
- [ ] no duplicate resolution abstractions.
- [ ] no fake CI path.
- [ ] static analysis clean.
- [ ] Pest clean PHP 8.4.
- [ ] Pest clean PHP 8.5.
- [ ] README matches actual code.

---

# 39. Финальная целевая схема

```text
                              Container
                                 |
                 +---------------+---------------+
                 |                               |
               make()                          call()
                 |                               |
                 v                               v
          Entry/Object path               CallableExecutor
                 |                               |
                 v                               v
          ObjectPipeline                  CallableResolver
                 |                               |
       +---------+---------+                     v
       |                   |              ParametersResolver
       v                   v                     |
AttributeProcessor     InstanceCreator            |
(class/property/            |                     |
 method only)               +----------+----------+
       |                               |
       v                               v
AttributeHandlerInterface       ParameterResolverInterface[]
       |                               |
       |                  +------------+-----------------------+
       |                  | provided / custom / attribute-aware|
       |                  | autowire / default / nullable      |
       |                  +------------------------------------+
       |
       +---- ObjectCreationContext


COMMON ATTRIBUTE METADATA / COMPOSITION
---------------------------------------

Reflection target / cached target metadata
        |
        v
AttributeDefinitionRegistry
        |
        v
AttributePlanBuilder
        |
        v
AttributePlan
   |                         |
   | parameter               | class/property/method
   v                         v
validate/compose         AttributeProcessor
only                     executes handler
   |
   v
ParametersResolver
executes resolver


AOT / PRODUCTION
----------------

build-time Reflection + validation
        |
        +-> precomputed class graph
        +-> precomputed attribute plan/order
        +-> precomputed resolver slots
        +-> content-addressed shard metadata
        +-> pipeline fingerprint

runtime uses SAME resolver/handler semantics as dev.
```

---

# 40. Решения, которые считаются зафиксированными этим документом

1. **Параметры разрешаются только через `ParameterResolverInterface`.**
2. **Общая attribute composition применяется к parameter attributes, но не заменяет resolver execution.**
3. **Class/property/method attributes выполняются generic `AttributeHandlerInterface`.**
4. **Отдельный property resolver не возвращается.**
5. **Public `ResolutionContext` удаляется; public transport — `array $params`.**
6. **`CallableExecutor::execute()` удаляется.**
7. **`ValueFallback`/`ValuePipeline` architecture удаляется после migration.**
8. **Specialized `Value*HandlerInterface` hierarchy удаляется.**
9. **Numeric resolver priority остаётся для resolver strategy order; attribute conflicts/order переходят в composition rules.**
10. **Сначала сохраняется относительный v4 resolver order как parity safety net; упрощение возможно только после тестов.**
11. **Специализированные `Map*` возвращаются; internal mapping implementation остаётся общей.**
12. **HTTP-specific provenance остаётся внутри request subsystem.**
13. **AOT не создаёт вторую semantic implementation custom resolver/handler logic.**
14. **Полное устранение Reflection в production — optimization phase после semantic parity, если оно действительно необходимо и измеримо полезно.**
15. **Нельзя считать v5 готовой, пока весь фактический v4 behavior не прошёл feature-by-feature parity audit.**

---

# 41. Оставшиеся обязательные verification decisions

Эти пункты не являются неопределённостью основной архитектуры, но требуют проверки реального integration/performance context перед release:

1. **ConfigProvider/autodiscovery:** восстановить или доказанно заменить после проверки `componenta/config`/`componenta/app`.
2. **Generic `MapRequest`:** оставить только если после возврата `Map*` он даёт самостоятельный multi-source use case.
3. **Numeric built-in priority values:** после parity можно упростить числа, но не resolver-chain concept.
4. **Reflection-free compiled targets:** реализовывать только если benchmark показывает пользу; не через отдельный semantic codegen.
5. **Public API aliases/deprecations для v4 names:** определить после полного `PublicApiSignatureTest` audit; major version позволяет break, но функциональность и clear migration должны быть сохранены.

Все остальные основные архитектурные решения зафиксированы выше.
