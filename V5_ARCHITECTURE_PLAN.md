# Componenta DI v5 — архитектурная спецификация

> Статус: текущая архитектура ветки `v5`.
>
> Главный инвариант: development и AOT используют один runtime semantic engine. AOT не генерирует собственные правила autowiring, parameter resolution или attribute execution.

## 1. Runtime model

```text
Container
  ├─ AliasResolver
  ├─ EntryCache
  ├─ EntryResolverInterface
  │    └─ CompositeResolver
  │         ├─ FactoryResolver
  │         ├─ InvokableResolver
  │         └─ ReflectionResolver
  └─ CallableExecutor

ReflectionResolver ─┐
FactoryResolver/AOT ├─> ObjectPipeline
                    │      ├─ AttributeProcessor
                    │      └─ InstanceCreator
                    │             └─ ParametersResolver
                    └──────────────────────────────
```

Compiled shard содержит только thin entry methods, которые делегируют в тот же `ObjectPipeline`, что и reflection path.

## 2. Parameter resolution

Любой constructor/callable parameter разрешается через один `ParameterResolverInterface` pipeline:

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

Встроенный порядок:

```text
1200  AttributeParameterResolver
1100  ArrayResolver
1000  ArrayTypedResolver
 800  RequestContextResolver
 300  AutowireByTypeResolver
 200  DefaultValueResolver
 100  NullableResolver
```

`supports()` классифицирует immutable target metadata. После seal pipeline не меняется.

### 2.1. Prepared parameter plans

Hot path не повторяет resolver classification.

```text
ReflectionParameter
    -> ParameterTarget
    -> supports() classification
    -> PreparedParameter { target, resolverSlots }
    -> PreparedParameterPlan
```

`PreparedParameterPlan` содержит только execution structure. В него не попадают:

- request objects;
- mapped-request provenance;
- current user;
- resolved values;
- default object values;
- resolver instances;
- container state.

Constructor plans создаются лениво при фактическом resolution. `Container::has()` / `ObjectPipeline::canCreate()` не запускают `ParameterResolverInterface::supports()`.

Closure metadata кешируется через `WeakMap` exact closure instance. Named functions/methods могут использовать strong immutable metadata cache.

## 3. Parameter override semantics

Caller params могут адресоваться:

```text
parameter name
numeric position
declared class/interface key
framework typed key, например ServerRequestInterface::class
```

`ArrayResolver` отвечает только за name/position.

`ArrayTypedResolver` отвечает только за compatible object по declared type key.

Attribute semantics не находятся в array resolvers.

## 4. Attribute composition

`AttributeDefinitionRegistry` является mutable только во время composition/bootstrap и sealed до выдачи готового container.

`AttributePlanBuilder`:

- проверяет PHP attribute target mask;
- строит semantic `AttributePlan`;
- проверяет capability cardinality;
- проверяет `requires` / `forbids`;
- выполняет custom rules;
- строит `before` / `after` ordering;
- обнаруживает cycles;
- валидирует parameter source-handler composition;
- валидирует readonly property composition.

Capabilities inheritance-aware.

`ValueProvider` — source capability.

`ValueTransformer` — независимая transformer capability.

Допустимо:

```php
#[QueryParam('count'), Cast('int')]
int $count
```

Недопустимо два независимых source:

```php
#[QueryParam('value'), Header('X-Value')]
string $value
```

## 5. Attribute execution

Object и parameter attributes имеют отдельные execution contracts.

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

`AttributeParameterResolver` — единственный bridge из parameter resolver pipeline к parameter attribute handlers.

`AttributeProcessor` выполняет class/property/method handlers.

Каждый runtime handler invocation получает fresh attribute instance. Cached `AttributePlan` хранит semantic metadata, а не mutable runtime attribute state.

## 6. Built-in handlers

Parameter/property handlers:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler
```

Parameter-only:

```text
RequestAttributeHandler
```

Object-only lifecycle handlers:

```text
InjectHandler
InitHandler
LazyHandler
NoConstructorHandler
SetUpRunner
```

`MakeHandler` также обслуживает `#[Proxy]` semantics.

## 7. Object creation

`ObjectPipeline` является единственным runtime владельцем object construction semantics.

Он кеширует:

- `ObjectMetadata`;
- constructor `ParameterTarget` metadata;
- warmed `PreparedParameterPlan`.

Runtime flow:

```text
metadata
 -> class composition plan
 -> constructor prepared plan
 -> before-instantiation handlers
 -> eager / lazy / proxy strategy
 -> constructor parameter resolution
 -> object initialization
 -> after-instantiation handlers
```

Для classes без object-level handlers `ObjectPipeline` идёт напрямую в `InstanceCreator` с тем же prepared constructor plan.

## 8. Lazy / proxy semantics

`#[Lazy]` использует native PHP lazy ghost.

`#[Proxy]` использует native lazy proxy.

Deferred object обязан удерживать constructor input snapshot до фактической инициализации. Это object-owned deferred state, а не container-global request cache.

Raw internal resolution params хранятся во внутреннем `ObjectResolutionParameterStore` на `WeakMap<ObjectCreationContext, array>`. После освобождения creation/lazy graph store не удерживает его как strong key.

## 9. Request context boundary

PSR-7 request передаётся как обычное caller-provided typed value:

```php
[
    ServerRequestInterface::class => $request,
]
```

Сам request является public input и доступен request-aware resolver/handler.

Mapped-request provenance — internal DI metadata:

```text
\0componenta.di.*
```

Она не является частью public parameter/object context.

`ResolutionMetadata::publicParameters()` удаляет DI-owned metadata перед extension boundaries.

Internal provenance разрешено видеть только встроенным resolution components, которым она нужна для security checks.

Custom:

- `ParameterResolverInterface`;
- `ParameterAttributeHandlerInterface`;
- `AttributeHandlerInterface`;
- `EntryResolverInterface`;
- user factory;
- lazy factory;

не получают `\0componenta.di.*` metadata.

## 10. Request source protection

`MappedRequestParameterSourceGuard` не является обычным resolver-ом и не участвует в priority competition.

Security preflight выполняется до resolver chain и запрещает mapped DTO data подменять explicit sources:

```text
#[Header]
#[QueryParam]
#[Cookie]
#[RequestAttribute]
#[ServerParam]
#[UploadedFile]
ServerRequestInterface
UriInterface
```

Provenance сохраняется внутри DI через nested alias / `ClassDefinition` / lazy / `SetUp`, но удаляется перед user extension boundary.

Custom `ExtractorInterface` получает исходный request без служебных DI attributes. Fallback parameter name доступен только через `ParameterNameAwareExtractorInterface`.

## 11. Current user

`CurrentUserProviderInterface` остаётся публичным provider contract.

Default `CurrentUserProvider` хранит main-context state и Fiber-local snapshot/override state через `WeakMap`.

Уже начавшийся Fiber не должен начать видеть изменившегося main user после suspend/resume.

Sequential long-running integration всё равно обязана очищать main request state в `finally`.

`PreparedParameterPlan` никогда не кеширует результат `getUser()`.

## 12. Callable execution

`CallableExecutor` разделяет:

```text
null plan  = magic/dynamic callable без отражаемой сигнатуры
empty plan = реальный reflected zero-argument callable
```

Magic `__call` / `__callStatic`, включая inaccessible declared method, сохраняет native PHP dispatch semantics.

Closure plans кешируются через `WeakMap`, поэтому executor не владеет lifetime closure/captured request graph.

User callable body не является DI exception boundary: throwable из тела explicitly invoked callable выходит без DI wrapping.

## 13. Exception diagnostics

`ResolutionException` хранит detached diagnostics:

- parameter/property names;
- positions;
- declared types;
- declaring context;
- types provided/resolved values.

Он не хранит live request/service arrays или `ReflectionParameter` closure-а.

`InvalidCallableException` хранит detached callable type/description, а не callable object.

Foreign cause сохраняется через `getPrevious()`.

## 14. Factory resolver

`FactoryResolver` зависит только от того, что реально требуется runtime factory resolution:

```text
factory definitions
ContainerInterface
ProxyFactoryInterface
ObjectPipeline
CallableExecutorInterface
optional compiled factory base dir
```

Attribute registry и parameter resolver не являются его зависимостями.

User factory получает sanitized `array $params`; internal DI metadata перед invocation удаляется.

## 15. AOT

AOT shard не содержит resolver/attribute semantics.

Generated method:

```php
public function createEntry0(array $params = []): object
{
    return $this->objects->create(\App\Service::class, $params);
}
```

Shard ABI содержит:

```text
FORMAT_VERSION
ENTRIES: method => entry class
content-addressed PHP file
```

Loader проверяет:

- path trust boundary;
- content address/hash;
- shard format;
- method metadata;
- target class availability;
- `ObjectPipeline::canCreate()`.

Stale/malformed artifact отклоняется fail-closed с требованием rebuild.

Resolver registration, attribute definitions и handler semantics не зашиваются в shard и всегда берутся из текущего runtime pipeline.

## 16. Cache generation

Persistent DI cache использует versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Current v5 cache format: `17`.

Generated PHP cache/shard:

1. пишется в exclusive `xb` temp file;
2. полностью flush-ится;
3. проверяется `php -l`;
4. только затем atomically переименовывается;
5. temp очищается при failure.

Warning suppression применяется только непосредственно к конкретным синхронным native operations. Global process-wide error handler вокруг произвольного callback отсутствует.

## 17. Extension points

Builder:

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

Internal builder extension points не должны получать dependencies, которыми они не пользуются.

## 18. Dev / AOT parity

Обязательный инвариант:

```text
DEV                         AOT
ReflectionResolver          Compiled factory method
       \                    /
        \                  /
          ObjectPipeline
                |
       PreparedParameterPlan
                |
        ParametersResolver
                |
 AttributeParameterResolver
                |
              handlers
```

Для одинакового runtime container state должны совпадать:

- resolved values;
- resolver precedence;
- attribute semantics;
- request source-conflict behavior;
- lazy/proxy behavior;
- exception boundary.

AOT оптимизирует discovery/artifact lookup, но не дублирует DI semantics.

## 19. CI и performance

CI на PHP 8.4 и 8.5 выполняет:

```text
composer validate --strict
PHP-CS-Fixer dry run
PHPStan level=max
Pest
RuntimeBench smoke
GeneratedVsReflectionBench smoke
BuildPhasesBench smoke
ParameterPlanBench smoke
```

`ParameterPlanBench` отдельно измеряет preparation, adapter `resolveTargets()` и warmed `resolvePrepared()`.

Полный v4 ↔ v5 benchmark запускается отдельно и не является основанием для изменения semantic contract.

## 20. Запрещённые направления

Не возвращать без отдельного доказанного RFC:

- production-only autowire resolver;
- generated copies resolver semantics;
- отдельный dev/prod parameter pipeline;
- request/current-user values внутри prepared plan;
- strong closure-signature cache с reflection objects;
- public transport-specific resolution context;
- semantic fingerprint, который замораживает runtime resolver/attribute registrations в thin AOT shard.
