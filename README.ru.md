# Componenta DI

PSR-11-контейнер внедрения зависимостей для PHP 8.4+ с расширяемым разрешением параметров, композицией атрибутов, PSR-7 request mapping, native lazy objects и AOT entry shards.

**[English](README.md)** | **[Русский](README.ru.md)**

## Установка

```bash
composer require componenta/di
```

Требуется PHP 8.4 или новее.

## Базовый API

```php
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger())
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$shared = $container->get('logger');
$fresh = $container->make(UserService::class, ['userId' => 7]);
$result = $container->call([$service, 'handle'], ['id' => 42]);
```

`get()` выполняет shared PSR-11 resolution. `make()` создаёт свежий локальный объект и принимает обычный `array $params`. `call()` является DI-aware и разрешает параметры callable той же цепочкой, что и параметры конструктора.

Публичного resolution-context объекта нет. Состояние framework передаётся через обычный массив параметров:

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

`ParametersResolver` владеет единственной упорядоченной цепочкой. Чем выше numeric priority, тем раньше запускается resolver. При одинаковом priority сохраняется порядок регистрации.

Встроенная цепочка v5 намеренно небольшая:

```text
передано по имени/позиции     1100
передано по declared type     1000
атрибуты параметра             900
implicit request context       800
autowire class/interface       300
PHP default                    200
nullable                       100
```

Слот `атрибуты параметра` — это **один** `AttributeParameterResolver`. `#[Cast]`, `#[Config]`, `#[Env]`, `#[EntryId]`, `#[CurrentUser]`, `#[Make]`, `#[Proxy]` и request-source атрибуты не регистрируют собственные parameter resolvers.

`#[Cast]` и `#[CurrentUser]` сохраняют особую семантику v4: generic caller-value resolvers намеренно пропускают эти параметры, чтобы значение обработал соответствующий attribute handler. Для обычных источников, например `#[Config]`, `#[Env]` или `#[Make]`, явно переданный caller value может иметь приоритет.

### Пользовательский convention resolver

`ParameterResolverInterface` нужен, когда правило не привязано к атрибуту:

```php
final readonly class TenantParameterResolver implements ParameterResolverInterface
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

$builder->addParameterResolver(new TenantParameterResolver($tenant), 750);
```

Resolver можно зарегистрировать instance-ом, service id, callable factory или формой `[serviceId, method]`. `replaceParameterResolvers()` отключает built-in chain. Пользовательские priorities должны быть уникальны; конфигурация поверх предварительно настроенного builder-а заменяет пользовательский resolver с тем же priority, сохраняя контракт v4.

## Архитектура атрибутов

Композиция и исполнение атрибутов разделены.

`AttributeDefinition` связывает класс атрибута с semantic metadata и, если атрибут исполняемый, с handler-ом:

```php
new AttributeDefinition(
    attribute: Transactional::class,
    handler: new TransactionalHandler(),
    capabilities: [TransactionBoundary::class],
    requires: [],
    forbids: [],
    before: [],
    after: [],
    rules: [],
    version: 1,
);
```

`AttributePlanBuilder`:

- создаёт зарегистрированные атрибуты;
- проверяет target mask;
- проверяет cardinality capabilities;
- применяет `requires` / `forbids`;
- выполняет custom composition rules;
- строит порядок `before` / `after`;
- кеширует immutable `AttributePlan`.

Capability policies учитывают наследование.

### Атрибуты класса, свойства и метода

Object attributes выполняются одним контрактом:

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

`AttributeProcessor` исполняет провалидированные планы для классов, свойств и методов. Отдельного `PropertyResolverInterface` нет.

### Атрибуты параметров

Параметр всё равно разрешается только через `ParameterResolverInterface`. Единственный bridge из общей attribute-модели в parameter pipeline — встроенный `AttributeParameterResolver`.

Handler, способный предоставить значение параметра, реализует:

```php
interface ParameterAttributeHandlerInterface extends AttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed;
}
```

`AttributeParameterResolver` читает уже провалидированный `AttributePlan`, выбирает parameter-aware handler и делегирует ему получение значения. Второго parameter-resolution pipeline не возникает.

Пользовательский parameter-атрибут не требует собственного resolver-а:

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
    ): mixed {
        return $this->tenant;
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        throw new LogicException('CurrentTenant is parameter-only.');
    }
}

$builder->addAttributeDefinition(new AttributeDefinition(
    CurrentTenant::class,
    new CurrentTenantHandler($tenant),
    capabilities: [ValueProvider::class],
));
```

При необходимости convention-based или неатрибутного разрешения пакет по-прежнему может зарегистрировать собственный `ParameterResolverInterface`.

## Встроенные attribute handlers

Value-семантика built-in атрибутов реализована handlers, а не отдельными parameter resolvers:

```text
CastHandler
ConfigHandler
EnvHandler
EntryIdHandler
CurrentUserHandler
MakeHandler          // Make + Proxy
RequestAttributeHandler
```

Один handler может обслуживать parameter и property targets. `MakeHandler` также обрабатывает class-level `#[Proxy]`, поэтому `#[Make] + #[Proxy]` остаётся одной семантической операцией получения значения.

Публичный набор атрибутов v4 и имена named arguments сохранены.

### Config и Env

`#[Config]` читает `Componenta\Config\Config`. String key остаётся literal key, `ConfigPath` сохраняет nested-path semantics.

`#[Env]` сохраняет преобразование по declared target type для `string`, `int`, `float`, `bool` и `array`, а также свой default при отсутствии `Environment` или переменной.

### Cast

`#[Cast]` сохраняет v4-конструктор `name, default`. Он берёт caller input по имени/позиции, использует собственный либо PHP default, когда это допустимо, и применяет caster из `CasterProviderInterface`.

Request-source атрибуты используют собственный `cast:` для transport casting:

```php
public function __construct(
    #[Header('X-Count', cast: 'int')]
    public int $count,
) {}
```

### Object injection и lifecycle

`#[Inject]` и `#[Init]` являются property handlers. `#[Init]` может изменить mutable promoted property после конструктора, но не переписывает уже инициализированное readonly promoted property.

`#[NoConstructor]` отключает конструктор. `#[Lazy]` выбирает native lazy object. `#[Proxy]` выбирает virtual proxy и поддерживается на классах, параметрах и свойствах.

Для interface-typed proxy injection concrete class указывается, если его нельзя вывести:

```php
public function __construct(
    #[Proxy(RedisCache::class)]
    public CacheInterface $cache,
) {}
```

`#[Make('service.id'), Proxy(ConcreteService::class)]` сохраняет независимость service id и proxy class.

`#[SetUp]` остаётся repeatable и выполняется после создания объекта. Setup-метод вызывается через DI-aware `call()`, поэтому его параметры используют тот же `ParametersResolver`. Дескрипторы `Config`, `Env`, `EntryId` и `ContainerValue` внутри `SetUp::params` сохраняют v4-семантику.

## Object pipeline

Reflection и compiled entries используют один runtime:

```text
ObjectPipeline
    -> построить/проверить AttributePlan metadata
    -> before-instantiation AttributeProcessor
    -> аргументы конструктора через ParametersResolver
    -> instantiate / lazy / proxy
    -> after-instantiation AttributeProcessor
    -> object
```

AOT не генерирует отдельную реализацию parameter resolver-а или attribute handler-а.

## PSR-7 request resolution

HTTP-логика отделена от generic DI.

Request передаётся как обычный typed parameter:

```php
$result = $container->call(
    static fn(
        #[Header('X-Token')] string $token,
        UriInterface $uri,
        ServerRequestInterface $request,
    ) => [$token, $uri, $request],
    [ServerRequestInterface::class => $request],
);
```

Все request-source parameter attributes обслуживаются одним `RequestAttributeHandler`:

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

Специализированные mapper-атрибуты v4 и generic `#[MapRequest]` используют одну семантику:

```text
extract sources
-> validate raw transport data
-> map
-> cast
-> defaults
-> sortMap
-> exclude
-> construct DTO
```

Пример multi-source mapping:

```php
public function __construct(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Query],
        map: ['user_id' => 'userId'],
    )]
    public CreateOrder $command,
) {}
```

Конфликт значений из разных sources по умолчанию вызывает `RequestDataConflictException`. Для намеренного precedence доступен `RequestDataConflictPolicy::FirstWins`.

При nested DTO mapping provenance переносится через internal request-only marker. `MappedRequestParameterSourceGuard` выполняется до resolver priority и до создания lazy/proxy object, поэтому mapped input не может подменить source-bound параметр — `#[Header]`, `ServerRequestInterface`, `UriInterface` и т. п. — даже через alias, `ClassDefinition`, lazy object или compiled entry.

Internal marker удаляется из обычных object/property parameters и не превращается в публичный DI context.

`RequestContextResolver` остаётся отдельным intentionally: он обслуживает только implicit non-attribute request context, например `UriInterface`.

При наличии `ValidationProviderInterface` class-typed request mapping выполняет validation до transformations.

## Фабрики и definitions

Пользовательские factory используют array-based ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn(ContainerValue $container, array $params): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

Factory может принимать меньше совместимых аргументов. `FactorySpecificationValidator` заранее отклоняет несовместимую required signature.

`ClassDefinition` остаётся immutable declarative data. Runtime overrides нормализуются по constructor signature: имени, позиции и declared object type — и затем входят в ту же resolver chain. Persistent-cache `ClassDefinition` использует тот же runtime path.

`Container::set($id, $definition)` меняет definition поддерживающего resolver-а уже построенного контейнера. `Container::set($id, $value)` заменяет local shared base value.

## ContainerBuilder

Основные extensions:

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

Неизвестные dependency keys отклоняются. Integer keys в `parameter_resolvers` — это priorities; они не переиндексируются.

Пакет экспортирует `Componenta\DI\ConfigProvider` через `extra.componenta.config-providers` для discovery в Componenta composer-plugin/app. Built-in resolvers и attribute definitions собирает `ContainerBuilder`, поэтому package provider их не дублирует.

## Shared resolution, aliases и delegators

`get()` сохраняет shared semantics: external PSR-11 ownership, decorated cache, aliases, local base entries, resolver lookup и delegators.

`make()` выполняет fresh local resolution и не использует shared entry cache, external containers и delegators. Циклы fresh-resolution обнаруживаются, включая циклы через `#[Make]`.

Изменение alias, замена deferred delegator service и смена external-container ownership инвалидируют затронутые decorated entries. Одновременный shared resolve из другого Fiber вызывает `ConcurrentResolutionException`, а не маскируется под dependency cycle.

## AOT compiled entries

`compileFactories()` создаёт content-addressed entry shards:

```php
$compiled = $builder->compileFactories(
    entries: [CreateOrder::class],
    directory: __DIR__ . '/var/cache/di',
);
```

Generated methods намеренно тонкие:

```text
reflection entry -> ObjectPipeline
compiled shard   -> ObjectPipeline
```

В обоих режимах вызываются те же `ParameterResolverInterface`, тот же `AttributeParameterResolver` и те же attribute handlers. Custom convention resolver, custom parameter attribute handler или custom object attribute handler не требует production-specific code generator.

Semantic fingerprint включает:

- формат attribute plan;
- attribute definitions и их versions;
- классы handlers и phases;
- capabilities, dependency constraints и versions custom rules;
- capability policies;
- фактическую ordered resolver chain.

Несовпадение fingerprint отклоняет stale shard. Content-addressed shard дополнительно проверяется по content hash.

`AutowireClassGraph` исключает ineligible roots и bootstrap extensions DI, включая parameter resolvers и attribute handlers.

## Persistent cache

Persistent cache использует строгий versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Текущий cache format v5 — `15`. Более старые envelopes отклоняются.

## Исключения

Все package exceptions реализуют `Componenta\DI\Exception\ExceptionInterface`. Основные ошибки:

```text
AttributeCompositionException
CircularDependencyException
ConcurrentResolutionException
InvalidConfigurationException
NotFoundException
RequestDataConflictException
RequestParameterSourceConflictException
ResolutionException
```

## Dev/prod parity

CI запускает Composer validation, PHP-CS-Fixer, PHPStan max level и Pest на PHP 8.4 и 8.5.

V5 parity suite проверяет:

- публичные v4 named-argument signatures;
- custom convention parameter resolvers;
- custom parameter attributes через `ParameterAttributeHandlerInterface`;
- custom class/property/method handlers;
- reflection vs AOT execution;
- semantic fingerprint invalidation;
- persistent cache и `ClassDefinition` override normalization;
- request provenance через alias, lazy objects и cache;
- `Proxy`/`Make`;
- promoted/private/static properties;
- Fiber ownership;
- alias/delegator/external-container invalidation.
