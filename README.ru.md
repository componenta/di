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

`get()` выполняет shared PSR-11 resolution. `make()` создаёт свежий локальный объект и принимает обычный `array $params`. `call()` является DI-aware: параметры callable разрешаются той же цепочкой parameter resolvers, что и параметры конструктора.

Публичного объекта resolution context в v5 нет. Framework-specific состояние, например PSR-7 request, передаётся как обычный typed parameter:

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

`ParametersResolver` владеет упорядоченной цепочкой resolver-ов. Чем выше numeric priority, тем раньше запускается resolver; одинаковый priority сохраняет порядок регистрации. Встроенный порядок v5 сохраняет поведение v4:

```text
Cast              1200
передано по имени 1100
передано по типу  1000
CurrentUser        900
request            800
Make               700
Env                600
EntryId            500
Config              400
autowire            300
PHP default         200
nullable            100
```

Некоторые resolver-ы сознательно не позволяют более слабому источнику перехватить значение. Например, caller input не может заменить `#[CurrentUser]`, а обычный именованный параметр может опередить `#[Config]`.

### Пользовательский parameter resolver

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

Resolver можно зарегистрировать instance-ом, service id контейнера, callable factory или формой `[serviceId, method]`. `replaceParameterResolvers()` отключает встроенную цепочку. Пользовательские priorities должны быть уникальны. Если конфигурация накладывается на предварительно настроенный subclass-builder, resolver с тем же priority заменяется, как в v4.

## Модель атрибутов

Композиция и исполнение атрибутов разделены.

`AttributeDefinition` описывает семантику композиции:

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

`AttributePlanBuilder` проверяет cardinality, зависимости, custom rules и порядок `before`/`after`. Наследование capabilities учитывается: ограничение родительской capability распространяется на её подтипы.

Атрибуты класса, свойства и метода выполняются одним общим контрактом:

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

Отдельного `PropertyResolverInterface` нет. Атрибуты параметров участвуют в той же композиционной модели, но никогда не обходят `ParameterResolverInterface`: для parameter-only атрибута `AttributeDefinition` может иметь `handler: null`, а значение выдаёт соответствующий parameter resolver.

### Пользовательский атрибут параметра

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentTenant {}

$builder
    ->addAttributeDefinition(new AttributeDefinition(
        CurrentTenant::class,
        handler: null,
        capabilities: [ValueProvider::class],
    ))
    ->addParameterResolver(new CurrentTenantResolver($tenant), 750);
```

### Пользовательский атрибут класса/свойства/метода

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class InjectClock {}

$builder->addAttributeDefinition(new AttributeDefinition(
    InjectClock::class,
    new InjectClockHandler($clock),
    capabilities: [ValueProvider::class],
));
```

## Встроенные value-атрибуты

Публичный набор атрибутов v4 сохранён, включая имена аргументов конструкторов.

`#[Config]` читает Componenta Config. `#[Env]` сохраняет v4-преобразование по declared target type для `string`, `int`, `float`, `bool` и `array`, а также использует свой default даже если в `Config` отсутствует `Environment`. `#[EntryId]`, `#[Make]`, `#[CurrentUser]`, request attributes и обычный autowiring разрешаются parameter resolvers.

`#[Cast]` сохраняет v4-конструктор `name, default` и resolver-семантику. Он занимает value-resolution slot target-а, берёт caller input либо собственное/PHP default значение и применяет выбранный caster. Request-атрибуты используют собственный параметр `cast:`, когда преобразование относится непосредственно к извлечению request value:

```php
public function __construct(
    #[Header('X-Count', cast: 'int')]
    public int $count,
) {}
```

`#[Inject]` и `#[Init]` исполняются как property handlers. `#[Init]` может изменить mutable promoted property после конструктора, но не переписывает уже инициализированное readonly promoted property.

## Жизненный цикл объекта

Reflection и compiled entries используют один runtime pipeline:

```text
построить/проверить AttributePlan
        -> before-instantiation handlers
        -> constructor parameters через ParameterResolverInterface[]
        -> instantiate / lazy / proxy
        -> after-instantiation class/property/method handlers
        -> вернуть объект
```

`#[NoConstructor]` отключает вызов конструктора. `#[Lazy]` выбирает native lazy object. `#[Proxy]` выбирает virtual proxy и, как в v4, поддерживается на классах, параметрах и свойствах.

Для interface-typed proxy injection конкретный proxy class необходимо указать, если его нельзя вывести автоматически:

```php
public function __construct(
    #[Proxy(RedisCache::class)]
    public CacheInterface $cache,
) {}
```

`#[Make('service.id'), Proxy(ConcreteService::class)]` разделяет service id и concrete proxy class.

`#[SetUp]` остаётся repeatable и выполняется после создания объекта. Его метод вызывается через тот же DI-aware `call()`, поэтому параметры setup-метода используют обычную resolver chain. Дескрипторы `Config`, `Env`, `EntryId` и `ContainerValue` в `SetUp::params` сохраняют семантику v4.

## PSR-7 request resolution

HTTP-логика изолирована в `Resolver/Parameter/Request`; generic DI core ничего не знает о `ServerRequestInterface`.

Request передаётся обычным typed parameter:

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

Scalar request attributes:

```text
#[QueryParam]
#[PayloadParam]
#[Header]
#[Cookie]
#[RequestAttribute]
#[ServerParam]
#[UploadedFile]
```

Специализированные mapper-атрибуты v4 также сохранены:

```text
#[MapQueryString]
#[MapRequestPayload]
#[MapHeaders]
#[MapCookies]
#[MapRequestAttributes]
#[MapServerParams]
#[MapUploadedFiles]
```

Все они используют единый `RequestMapper` transformation pipeline:

```text
map -> cast -> defaults -> sortMap -> exclude
```

Дополнительно v5 содержит generic `#[MapRequest]` для явного multi-source mapping:

```php
public function __construct(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Query],
        map: ['user_id' => 'userId'],
    )]
    public CreateOrder $command,
) {}
```

Если разные request sources передают разные значения одного ключа, по умолчанию выбрасывается `RequestDataConflictException`. Для намеренного ordered precedence доступен `RequestDataConflictPolicy::FirstWins`.

При создании вложенного DTO provenance mapping-а передаётся через внутренний request-only marker. Он скрыт от обычных resolver-ов. До применения resolver priorities `MappedRequestParameterSourceGuard` запрещает mapped-значению затереть явный источник параметра, например `#[Header]`, request object или URI. Даже custom resolver с очень высоким priority не может обойти эту проверку.

При наличии `ValidationProviderInterface` class-typed request mapping сохраняет поддержку validation.

## Фабрики и definitions

Пользовательские factory используют array-based runtime ABI:

```php
$builder->addFactory(
    MailerInterface::class,
    static fn(ContainerValue $container, array $params): MailerInterface =>
        new SmtpMailer($container->get(SmtpConfig::class)),
);
```

Factory может принимать меньше совместимых аргументов. `FactorySpecificationValidator` заранее отклоняет несовместимую required signature.

`ClassDefinition` остаётся immutable declarative data. Runtime и persistent cache разрешают его через тот же `ObjectPipeline`; constructor/property semantics атрибутов не реализуются второй раз generated-кодом.

`Container::set($id, $definition)` меняет definition поддерживающего resolver-а уже построенного контейнера. `Container::set($id, $value)` заменяет локальное shared base value.

## ContainerBuilder

Основные extension methods:

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

Dependency keys v5:

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

Неизвестные dependency keys отклоняются. Integer keys в `parameter_resolvers` являются priorities и не переиндексируются.

Пакет по-прежнему экспортирует `Componenta\DI\ConfigProvider` через `extra.componenta.config-providers` для discovery в `componenta/composer-plugin`/`componenta/app`. Встроенные resolver-ы и attribute definitions v5 собираются непосредственно `ContainerBuilder`, поэтому package provider намеренно не дублирует эти runtime registrations.

## Shared resolution, aliases и delegators

`get()` сохраняет shared container semantics: external PSR-11 ownership, decorated cache, aliases, local base entries, resolver lookup и delegators.

`make()` выполняет fresh local resolution и не использует shared entry cache, external containers и delegators. Циклы fresh-resolution по-прежнему обнаруживаются, включая циклы через `#[Make]`.

Изменение alias, замена deferred delegator service и смена ownership во внешнем контейнере инвалидируют затронутые decorated entries. Одновременный shared resolve из другого Fiber приводит к `ConcurrentResolutionException`, а не ошибочно определяется как обычный dependency cycle.

## AOT compiled entries

`compileFactories()` получает известные autowiring roots и создаёт content-addressed shards:

```php
$compiled = $builder->compileFactories(
    entries: [CreateOrder::class],
    directory: __DIR__ . '/var/cache/di',
);
```

Generated factory methods намеренно тонкие:

```text
reflection resolution -> ObjectPipeline
compiled AOT shard    -> ObjectPipeline
```

Поэтому custom `ParameterResolverInterface` или `AttributeHandlerInterface` не требует отдельного production code generator. Build-time metadata может убрать reflection/classification overhead, но execution semantics общие.

Semantic fingerprint включает формат attribute plan, зарегистрированные definitions/capability policies и фактический ordered parameter-resolver chain. Несовпадение fingerprint отклоняет stale shard. Content-addressed shard проверяется по содержимому перед использованием.

`AutowireClassGraph` сохраняет v4 eligibility semantics и исключает bootstrap extensions DI — parameter resolvers и attribute handlers — из compiled entry roots.

## Persistent cache

Persistent cache использует строгий versioned envelope:

```php
[
    'version' => ContainerBuilder::CACHE_VERSION,
    ConfigKey::DEPENDENCIES => $dependencies,
]
```

Текущая версия cache format — `14`. Старые envelopes отклоняются.

`DiCacheGenerator` экспортирует поддерживаемые immutable definitions/configuration. Cached `ClassDefinition` интерпретируется той же runtime resolver/parameter pipeline, что обычное reflection resolution.

## Основные исключения

Все исключения пакета реализуют `Componenta\DI\Exception\ExceptionInterface`:

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

## Parity development/production

CI пакета запускает `composer validate`, PHPStan на максимальном уровне, coding-style checks и Pest на PHP 8.4 и 8.5. V5 parity suite проверяет public named-argument signatures, custom resolver/attribute extensions, reflection против AOT, persistent cache, request provenance, proxy/make, promoted/private properties, Fiber ownership и invalidation aliases/delegators.
