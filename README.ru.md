# Componenta DI

PSR-11-контейнер внедрения зависимостей для PHP 8.4+ с autowiring, композицией атрибутов, PSR-7 request mapping, native lazy objects и AOT factory shards.

**[English](README.md)** | **[Русский](README.ru.md)**

## Установка

```bash
composer require componenta/di
```

## Базовый API

```php
$container = (new Componenta\DI\ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->build();

$shared = $container->get(LoggerInterface::class);
$fresh = $container->make(UserService::class, ['userId' => 7]);
$result = $container->call([$service, 'handle'], ['id' => 42]);
```

`get()` выполняет shared PSR-11 resolution. `make()` создаёт свежий локальный объект. `call()` является DI-aware. Публичные factory/callable границы используют обычный `array $params`; публичного resolution-context объекта нет.

Framework values передаются тем же массивом:

```php
$controller = $container->make(Controller::class, [
    ServerRequestInterface::class => $request,
]);
```

## Разрешение параметров

Любой параметр конструктора или callable разрешается исключительно через `ParameterResolverInterface`:

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

Встроенная цепочка намеренно небольшая:

```text
1200  AttributeParameterResolver
1100  ArrayResolver
1000  ArrayTypedResolver
 800  RequestContextResolver
 300  AutowireByTypeResolver
 200  DefaultValueResolver
 100  NullableResolver
```

`ArrayResolver` и `ArrayTypedResolver` ничего не знают об атрибутах. Приоритеты, совместимость и порядок атрибутов полностью относятся к `AttributeParameterResolver` и `AttributePlan`.

Custom `ParameterResolverInterface` нужен для convention-based правил без атрибута:

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

$builder->addParameterResolver(new TenantResolver($tenant), 750);
```

Resolver можно зарегистрировать instance-ом, service id контейнера, callable factory или формой `[serviceId, method]`. `replaceParameterResolvers()` отключает встроенную цепочку.

## Композиция атрибутов

`AttributeDefinition` связывает атрибут с handler-ом и semantic metadata:

```php
new AttributeDefinition(
    attribute: MyAttribute::class,
    handler: new MyHandler(),
    capabilities: [MyCapability::class],
    requires: [],
    forbids: [],
    before: [],
    after: [],
    rules: [],
    version: 1,
);
```

`AttributePlanBuilder` проверяет:

- допустимые targets;
- cardinality capabilities;
- `requires` / `forbids`;
- custom composition rules;
- порядок `before` / `after`;
- совместимость parameter value sources.

Наследование capabilities одинаково учитывается в `AttributePlanBuilder`, `AttributeSet` и `AttributePlan`.

### Несколько атрибутов на одном параметре

На параметре допускается один источник значения и transformers. Например:

```php
public function __construct(
    #[QueryParam('count'), Cast('int')]
    public int $count,
) {}
```

План ставит request source перед `#[Cast]`, поэтому строковое query-значение превращается в `int`. Порядок объявления для этой встроенной композиции не важен:

```php
#[Cast('int'), QueryParam('count')]
```

даёт тот же source → transformer порядок.

Два несовместимых value source отклоняются уже при построении плана:

```php
#[QueryParam('value'), Header('X-Value')]
string $value
```

вызывает `AttributeCompositionException` до разрешения параметра. Та же проверка выполняется при AOT prepare до записи shard.

`ValueProvider` — единственный на target. `ValueTransformer` является отдельной capability: source + transformer допустимы, source + source — нет.

## Контракты исполнения атрибутов

Object attributes и parameter attributes используют разные интерфейсы.

Class/property/method handler:

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

Parameter-only handler:

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

`ParameterAttributeHandlerInterface` **не наследует** `AttributeHandlerInterface`. Поэтому parameter-only handler не обязан иметь бессмысленный `handle()`, который только выбрасывает исключение. Handler, работающий и с параметром, и с property/class/method, явно реализует оба интерфейса.

`AttributeParameterResolver` — единственный built-in мост из parameter resolution в parameter handlers. Он:

1. получает уже проверенный `AttributePlan`;
2. при необходимости использует caller input как начальное значение;
3. выполняет handlers в скомпонованном порядке;
4. передаёт immutable `ParameterAttributeValue` от source к transformer;
5. возвращает финальное значение обычному resolver pipeline.

Authoritative source, например `#[CurrentUser]`, описывается capability `AuthoritativeValueProvider`; `AttributeParameterResolver` не содержит проверок конкретных классов атрибутов.

### Custom parameter attribute

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentTenant {}

final readonly class CurrentTenantHandler
    implements ParameterAttributeHandlerInterface
{
    public function __construct(private Tenant $tenant) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return $value->resolved
            ? $value
            : ParameterAttributeValue::resolved($this->tenant);
    }
}

$builder->addAttributeDefinition(new AttributeDefinition(
    CurrentTenant::class,
    new CurrentTenantHandler($tenant),
    capabilities: [ValueProvider::class],
));
```

Отдельный custom parameter resolver для атрибутного расширения не нужен.

## Встроенные handlers

Семантика built-in атрибутов находится в handlers, а не в отдельных parameter resolvers:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler
RequestAttributeHandler
```

`ConfigHandler`, `EnvHandler`, `EntryIdHandler`, `CurrentUserHandler`, `MakeHandler` и `CastHandler` реализуют оба execution contract, так как соответствующие атрибуты работают также со свойствами. `RequestAttributeHandler` является parameter-only.

`#[Cast]` — transformer. Он может преобразовывать caller input или значение, созданное другим source handler. Для mutable property также поддерживается source → `Cast` композиция.

`#[CurrentUser]` является authoritative source и не может быть заменён caller-provided значением.

`#[Make]` и `#[Proxy]` используют один `MakeHandler`; `#[Make('service.id'), Proxy(ConcreteService::class)]` сохраняет независимость service id и proxy class.

## Object attributes

`AttributeProcessor` выполняет планы class/property/method. `PropertyResolverInterface` отсутствует.

Поддерживаются:

- `#[Inject]`;
- `#[Init]`;
- `#[NoConstructor]`;
- `#[Lazy]`;
- `#[Proxy]`;
- repeatable `#[SetUp]`.

Обрабатываются private свойства родителей. Static DI properties дают явную ошибку. Mutable promoted `#[Init]` property может быть изменено после конструктора; уже инициализированное readonly promoted property сохраняется.

## PSR-7 request attributes

Request передаётся обычным typed parameter:

```php
$params = [ServerRequestInterface::class => $request];
```

Все request-source атрибуты обслуживает один `RequestAttributeHandler`:

```text
#[QueryParam]
#[PayloadParam]
#[Header]
#[Cookie]
#[RequestAttribute]
#[ServerParam]
#[UploadedFile]
#[MapQueryString]
#[MapRequestPayload]
#[MapHeaders]
#[MapCookies]
#[MapRequestAttributes]
#[MapServerParams]
#[MapUploadedFiles]
#[MapRequest]
```

Общий mapping pipeline:

```text
extract
-> validate raw data
-> map
-> cast
-> defaults
-> sortMap
-> exclude
-> construct DTO
```

Nested DTO mapping переносит provenance через внутренний request-only marker. `MappedRequestParameterSourceGuard` срабатывает до resolver priorities и до lazy/proxy creation, поэтому mapped payload не может подменить явно объявленный source.

`RequestContextResolver` существует отдельно только потому, что implicit `UriInterface` resolution не имеет атрибута.

## Factory, definitions и cache

Пользовательская factory использует array ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn(ContainerValue $container, array $params): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

`ClassDefinition` использует тот же runtime parameter pipeline. Runtime overrides проецируются на constructor signature по имени, позиции и declared object type.

Persistent cache имеет строгий versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Текущий cache format v5: **16**.

## AOT

`compileFactories()` создаёт content-addressed shards. Generated methods остаются тонкими:

```text
reflection entry -> ObjectPipeline
compiled shard   -> ObjectPipeline
```

Оба режима используют один `ParametersResolver`, один `AttributeParameterResolver` и те же handlers. Custom resolver/handler не требует отдельного production code generator.

Semantic fingerprint включает format attribute plan, definitions, handler classes, capabilities, composition rules/policies и фактическую ordered resolver chain. Stale shard отклоняется.

## ContainerBuilder extensions

```text
addFactory() / addFactories()
addDefinition()
addInvokable() / addInvokables()
addAlias() / addAliases()
addDelegator() / addDelegators()
addService() / addServices()
addParameterResolver()
replaceParameterResolvers()
addAttributeDefinition()
replaceAttributeDefinitions()
defineAttributeCapability()
compileFactories()
toArray()
build()
```

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

Integer keys `parameter_resolvers` являются priorities и не переиндексируются. Неизвестные dependency keys отклоняются.

Пакет экспортирует `Componenta\DI\ConfigProvider` через `extra.componenta.config-providers` для Componenta package discovery.

## Контракт исключений

`Componenta\DI\Exception\ExceptionInterface` — единая package-level граница для ошибок, принадлежащих DI или нормализованных DI. Интерфейс также наследует PSR-11 `ContainerExceptionInterface`.

- `get()` и `make()` выпускают только Componenta DI exceptions; отсутствующий entry представлен `NotFoundException`, который также реализует PSR-11 `NotFoundExceptionInterface`.
- ошибки parameter resolvers, attribute handlers, конструктора, factory, delegator, external container, validation и caster нормализуются владельцем соответствующего DI pipeline; исходный foreign throwable сохраняется в `getPrevious()`.
- `call()` разделён на две фазы: подготовка callable и аргументов относится к DI boundary, а после входа в явно вызванное пользовательское callable его исключения проходят без изменения.
- `AttributeCompositionException` является специализированным `InvalidConfigurationException`.
- неверный builder/AOT input даёт `InvalidConfigurationException`; ошибки сериализации, filesystem, lint и activation сгенерированных артефактов дают `CompilationException`.
- типы validation/caster exceptions не входят в публичный exception ABI DI. Request mapping сохраняет их как cause внутри `ResolutionException`.

Один и тот же exception contract действует в reflection и compiled/AOT режимах, включая deferred initialization встроенных lazy objects.

## CI и parity

CI выполняет Composer validation, PHP-CS-Fixer, PHPStan на максимальном уровне и Pest на PHP 8.4/8.5.

Parity suite покрывает публичные сигнатуры v4, custom convention resolvers, custom parameter/object handlers, композицию нескольких атрибутов параметра, конфликты sources, reflection/AOT parity, request provenance, persistent cache, proxy/make semantics, promoted/private/static properties, Fibers, aliases, delegators, external containers и строгий exception contract.
