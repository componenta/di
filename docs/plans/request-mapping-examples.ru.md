# Отображение запроса: существующие способы и callback-вариант

[English version](request-mapping-examples.md) · [Нормативный контракт](php85-callbacks.ru.md#11-отображение-запроса-и-отложенная-работа)

**Статус на 2026-11-17:** `MapRequest(afterMap: ...)` и `CallbackMapperInterface` реализованы в ветке разработки DI 6. Руководство сохраняет существующие решения и добавляет callback-вариант. Оба способа поддерживаются; миграция существующих приложений не требуется. Примеры выполнены на реализации PHP 8.4.27 и 8.5.12.

Полезное дополнение — преобразовать весь mapped array с DI-зависимостями до автоматического создания DTO. Оно сокращает код прикладных адаптеров. Исправление raw input до валидации, новый validation framework и бизнес-транзакции в задачу не входят.

| Задача | Существующий способ, сохраняющий поддержку | Дополнительный callback-вариант |
|---|---|---|
| Переименовать поля, задать defaults/exclude | Декларативные параметры `MapRequest` | Остаются те же параметры. |
| Объединить поля без DI | Переопределить `transform(array): array` у source mapper. | Необязательный локальный callback; принципиально новой способности нет. |
| Объединить поля с DI, сохранив автоматическую DTO validation и проверки источников | Прикладной parameter handler + runtime mapper, делегирующие встроенному handler | `MapRequest(afterMap: ...)` использует существующий callback binder. |
| Вернуть преобразованный массив | `Map*` + `Transform` | Уже поддерживается; только ради этого `afterMap` не нужен. |
| Переиспользовать преобразование между endpoints/источниками | Переиспользовать прикладной mapper/handler. | Переиспользовать именованный callback, меняя `sources`. |

## 1. Один сопоставимый прикладной пример

На входе — `given`, `family` и недоверенное поле `role`. DTO получает вычисленное имя и роль из зарегистрированного сервиса. `NameFormatter` задаёт заменяемую политику форматирования. Эти объявления общие для существующего и callback-вариантов.

```php
<?php

declare(strict_types=1);

namespace Example\Profiles;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;

require __DIR__ . '/vendor/autoload.php';

final readonly class NameFormatter
{
    public function __construct(private bool $familyFirst = false) {}

    public function format(string $given, string $family): string
    {
        $given = trim($given);
        $family = trim($family);
        return $this->familyFirst ? "$family, $given" : "$given $family";
    }
}

final readonly class Profile
{
    public function __construct(
        public string $displayName,
        #[EntryId('profile.role')] public string $role,
    ) {}
}

final class ProfileInput
{
    /** @param array<string|int,mixed> $data @return array{displayName:string} */
    public static function normalize(array $data, NameFormatter $formatter): array
    {
        $given = $data['given'] ?? null;
        $family = $data['family'] ?? null;
        if (!is_string($given) || !is_string($family)) {
            throw new \InvalidArgumentException('Expected given/family strings.');
        }
        return ['displayName' => $formatter->format($given, $family)];
    }
}
```

Callback возвращает новый массив только с `displayName`: он не изменяет readonly DTO и не копирует входной `role` в аргументы конструктора. Сервис роли в примере представляет значение под управлением приложения; DI-контейнер не выполняет за приложение авторизацию.

Следующие примеры используют этот публичный bootstrap. `Nyholm\Psr7\ServerRequest` нужен только для создания тестового запроса; приложение может передать свой PSR-7 request. Фрагменты размещаются в том же namespace/файле после общих объявлений. Существующий и callback-варианты запускаются отдельно. Непосредственное нативное выражение атрибута из §4 требует PHP 8.5; вариант с константой работает на PHP 8.4.

```php
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/** @param list<mixed> $definitions @param array<string,mixed> $services */
function exampleContainer(array $definitions = [], array $services = []): Container
{
    $value = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            ConfigKey::SERVICES => $services + [
                NameFormatter::class => new NameFormatter(),
                'profile.role' => 'reader',
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => $definitions,
        ]),
    );
    if (!$value->container instanceof Container) {
        throw new \LogicException('Expected the standard container.');
    }
    return $value->container;
}
```

## 2. Простое отображение: callback не нужен

Этот пример работает сейчас и должен остаться прежним. Он переименовывает готовое имя и удаляет недоверенное поле до проверки источников DTO.

```php
function simpleProfile(
    #[MapRequest(map: ['full_name' => 'displayName'], exclude: ['role'])]
    Profile $profile,
): Profile {
    return $profile;
}

$container = exampleContainer();
$request = (new ServerRequest('POST', '/profiles'))->withParsedBody([
    'full_name' => 'Ada Lovelace',
    'role' => 'administrator',
]);
$result = $container->call(simpleProfile(...), [ServerRequestInterface::class => $request]);
// Profile(displayName: 'Ada Lovelace', role: 'reader')
```

Для чистого преобразования нескольких полей без DI существующий подкласс `MapRequestPayload` может переопределить `transform()`, вызвать `parent::transform($data)` и вернуть новый массив. Для создания readonly DTO из такого результата callback-фича не требуется.

## 3. Существующий путь: типизированный DTO и DI, поддержка сохраняется

Корректное решение уже сейчас сохраняет автоматическую DTO validation и проверки источников. Прикладной сервис получает зависимости через обычный конструктор. Адаптер создаёт его внутри `transform()`, до которого встроенный request handler доходит только после raw validation. Здесь `make()` намеренно создаёт свежий mapping service; поэтому показанная ниже замена прямой зависимости форматирования видна следующему вызову.

```php
use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final readonly class ProfileInputMapping
{
    public function __construct(private NameFormatter $formatter) {}

    public function map(array $data): array
    {
        return ProfileInput::normalize($data, $this->formatter);
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapProfile implements ParameterSourceAttributeInterface {}

// Per-attempt runtime adapter; not a cached native attribute instance.
final class ProfileRuntimeMapper extends MapRequestPayload
{
    public function __construct(private readonly FactoryInterface $factory)
    {
        parent::__construct();
    }

    public function transform(array $data): array
    {
        $mapped = parent::transform($data);
        $mapper = $this->factory->make(ProfileInputMapping::class);
        return $mapper->map($mapped);
    }
}

final readonly class MapProfileHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(
        private RequestAttributeHandler $requests,
        private FactoryInterface $factory,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if ($value->resolved) {
            return $value;
        }
        return $this->requests->resolveParameter(
            new ProfileRuntimeMapper($this->factory),
            $target,
            $context,
            $plan,
            $value,
        );
    }
}

function currentDefinition(Container $container): AttributeDefinition
{
    $handler = $container->get(RequestAttributeHandler::class);
    if (!$handler instanceof RequestAttributeHandler) {
        throw new \LogicException('Expected the configured request attribute handler.');
    }
    return new AttributeDefinition(
        MapProfile::class,
        new MapProfileHandler($handler, $container),
        capabilities: [ValueProvider::class],
    );
}

function createProfileCurrent(#[MapProfile] Profile $profile): Profile
{
    return $profile;
}
```

Регистрация переиспользует handler, уже настроенный `ContainerFactory`. Приложению не нужно собирать internal binder или вручную дублировать настройку casting/validation providers.

```php
$container = exampleContainer(definitions: [currentDefinition(...)]);
$request = (new ServerRequest('POST', '/profiles'))->withParsedBody([
    'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
]);
$first = $container->call(createProfileCurrent(...), [ServerRequestInterface::class => $request]);
$container->set(NameFormatter::class, new NameFormatter(familyFirst: true));
$second = $container->call(createProfileCurrent(...), [ServerRequestInterface::class => $request]);
// $first:  Profile(displayName: 'Ada Lovelace', role: 'reader')
// $second: Profile(displayName: 'Lovelace, Ada', role: 'reader')
```

Это эквивалентный текущий путь: типизированный readonly DTO, DI, порядок raw validation и итоговые проверки источников. `Map*` вместе с `Transform` для **массива** короче, если нужен именно массив. При последующем ручном создании DTO приложение само задаёт validation/source policy; array-mode не наследует гарантии автоматического DTO скрытым образом.

## 4. Дополнительный callback-путь: тот же normalizer, меньше адаптеров

Следующая совместимая с PHP 8.4 форма определяет closure constant при bootstrap приложения, до материализации атрибута. Константа принадлежит приложению и намеренно удерживает closure; переиспользуемые callbacks не должны захватывать request или хранить изменяемое состояние запроса.

```php
define(__NAMESPACE__ . '\PROFILE_INPUT', ProfileInput::normalize(...));

function createProfileAfter(
    #[MapRequest(afterMap: PROFILE_INPUT)] Profile $profile,
): Profile {
    return $profile;
}

function queryProfileAfter(
    #[MapRequest(sources: [RequestDataSource::Query], afterMap: PROFILE_INPUT)]
    Profile $profile,
): Profile {
    return $profile;
}
```

`ProfileInput`, `Profile` и `NameFormatter` остаются общими объявлениями из §1. Обёртка `ProfileInputMapping`, `MapProfile`, runtime mapper, parameter handler и пользовательская регистрация из §3 для этого сценария больше не нужны. Один callback работает с разными источниками и разными входными значениями:

```php
$container = exampleContainer();
$payload = (new ServerRequest('POST', '/profiles'))->withParsedBody([
    'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
]);
$query = (new ServerRequest('GET', '/profiles'))->withQueryParams([
    'given' => ' Grace ', 'family' => ' Hopper ', 'role' => 'administrator',
]);
$first = $container->call(createProfileAfter(...), [ServerRequestInterface::class => $payload]);
$search = $container->call(queryProfileAfter(...), [ServerRequestInterface::class => $query]);
$container->set(NameFormatter::class, new NameFormatter(familyFirst: true));
$second = $container->call(createProfileAfter(...), [ServerRequestInterface::class => $payload]);
$searchAgain = $container->call(queryProfileAfter(...), [ServerRequestInterface::class => $query]);
// $first:       Profile('Ada Lovelace', 'reader')
// $search:      Profile('Grace Hopper', 'reader')
// $second:      Profile('Lovelace, Ada', 'reader')
// $searchAgain: Profile('Hopper, Grace', 'reader')
```

На PHP 8.5 ссылку на тот же public static метод можно написать непосредственно в атрибуте. Этот синтаксис требует PHP 8.5, но не меняет требование пакета DI `^8.4`. Для непосредственной inline closure нужна допустимая форма `static function`; arrow function внутри атрибута не является поддерживаемой заменой. См. [новые возможности PHP](https://www.php.net/manual/en/migration85.new-features.php) и [RFC constant expressions](https://wiki.php.net/rfc/closures_in_const_expr).

```php
function createProfileNative(
    #[MapRequest(afterMap: ProfileInput::normalize(...))] Profile $profile,
): Profile {
    return $profile;
}
```

### Как этот подход растёт вместе с приложением

Именованный callback может делегировать большему прикладному normalizer через обычный DI-суффикс. Этот сервис может компоновать нормализацию имени/адреса/email и владеть порядком и промежуточными схемами. Такая композиция в приложении не требует добавлять в API контейнера порядок callback-list, ошибки промежуточных стадий и правила их повторения.

В примере выше меняется **прямой сервис суффикса** `NameFormatter`. Рекурсивного пересоздания не обещается: уже shared `ProfileNormalizer`, получивший formatter в конструкторе, сохраняет его после замены только регистрации formatter. Нужно выбрать lifetime normalizer, заменить сам сервис либо внедрять изменяемую политику непосредственно. Переиспользование именованной функции/static метода также сохраняет их нативное static-состояние; свежая closure-обёртка не сбрасывает static-переменные метода.

## 5. Необязательный callback для custom source и readonly-атрибута

Независимый mapper может подключиться без наследования изменяемого `RequestMapper`. Здесь middleware передаёт `profile-input` в атрибуте запроса. Используется хранимое readonly-свойство: оно удовлетворяет get-only контракту интерфейса без readonly property hook.

```php
use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\Request\CallbackMapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapProfileAttribute implements RequestDataExtractorInterface, CallbackMapperInterface
{
    public ?Closure $afterMap;

    public function __construct()
    {
        $this->afterMap = ProfileInput::normalize(...);
    }

    public function extract(ServerRequestInterface $request): array
    {
        $data = $request->getAttribute('profile-input');
        if (!is_array($data)) {
            throw new \InvalidArgumentException('profile-input must be an array.');
        }
        return $data;
    }

    public function transform(array $data): array
    {
        return $data;
    }
}

function customDefinition(Container $container): AttributeDefinition
{
    $handler = $container->get(RequestAttributeHandler::class);
    if (!$handler instanceof RequestAttributeHandler) {
        throw new \LogicException('Expected the configured request attribute handler.');
    }
    return new AttributeDefinition(
        MapProfileAttribute::class,
        $handler,
        capabilities: [ValueProvider::class],
    );
}

function customProfile(#[MapProfileAttribute] Profile $profile): Profile
{
    return $profile;
}

$container = exampleContainer(definitions: [customDefinition(...)]);
$request = (new ServerRequest('POST', '/profiles'))->withAttribute('profile-input', [
    'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
]);
$result = $container->call(customProfile(...), [ServerRequestInterface::class => $request]);
// Profile(displayName: 'Ada Lovelace', role: 'reader')
```

Реализация интерфейса **не регистрирует** атрибут. Нужна существующая явная definition factory. Пример переиспользует встроенный handler и настроенный binder; автоматический поиск интерфейсов и второй registry request handlers не нужны. Сообщение/event вне PSR-7 должно иметь собственную прикладную границу, вместо расширения HTTP mapping до универсального pipeline.

## 6. Валидация, защита источников и жизненный цикл

Raw DTO validation остаётся перед преобразованием mapper и перед `afterMap`. Например, raw validator, требующий `given` и `family`, отклоняет пустой `given` до normalizer. Итоговая схема содержит только `displayName`; тот же raw validator повторно на ней не запускается. Инварианты вычисленного значения принадлежат DTO/value object или явному валидатору приложения.

Этот validator регистрируется тем же bootstrap как для существующего адаптера, так и для callback-варианта:

```php
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;

$validation = new class implements ValidationProviderInterface {
    public function provide(string $entryId): ?ValidatorInterface
    {
        return $entryId === Profile::class
            ? new Validator(['given' => new Required(), 'family' => new Required()])
            : null;
    }
};
$container = exampleContainer(services: [ValidationProviderInterface::class => $validation]);
// Current adapter: also pass definitions: [currentDefinition(...)].
// Valid raw input: ['given' => 'Ada', 'family' => 'Lovelace'].
// Invalid raw input: ['given' => '', 'family' => 'Lovelace'].
```

| Конкретный случай | Требуемый результат и причина |
|---|---|
| Во входе `role: administrator`, normalizer возвращает только `displayName` | DTO получает service role `reader`. Удалённые raw-имена не становятся итоговыми аргументами конструктора. |
| Normalizer возвращает `displayName` **и** `role: administrator` | `RequestParameterSourceConflictException` до первоначального вызова фабрики для этого объявленного DTO: у `role` есть явный источник `EntryId`. |
| Callback добавляет поле, ранее удалённое `exclude` | Поле участвует в итоговых проверках. `exclude` повторно не запускается как allowlist после callback. |
| Во входе есть поле `formatter` | Оно не подменяет DI-суффикс `NameFormatter`. Суффикс получает пустой набор provided parameters. |
| Пользовательский suffix resolver читает `resolved[0]` | Он видит смысловой mapped array. Пустой provided bag не является sandbox, скрывающим данные от доверенных расширений. |
| Callback возвращает integer, DTO, `Traversable`, `null` или `void` | Ошибка array-result до фабрики DTO: callback преобразует вход, а не создаёт DTO. |
| Известен конфликт источника объявленного конструктора | Отказ до первоначальной фабрики DTO. Поздние конфликты фактического класса/setup/nested отклоняются до потребления данных соответствующим параметром; более ранние эффекты не откатываются. |

`Transform` после DTO mapping получает объект, возвращённый фабрикой, а не массив аргументов конструктора. Для readonly-свойств это не механизм переписывания входа. У lazy-объекта/proxy конструктор в этот момент может ещё не выполниться:

| Цель и стадия | Наблюдаемый порядок |
|---|---|
| Текущий eager DTO с внешним `Transform` | Raw validation → mapper → конструктор/инициализация свойств → внешний `Transform(object)`. |
| Текущий lazy/proxy DTO с внешним `Transform` | Raw validation → mapper → deferred object → внешний `Transform(object)`; конструктор/инициализация свойств позже. |
| Eager DTO с `afterMap` | Raw validation → mapper → `afterMap(array)` → проверки источников → конструктор/инициализация свойств. |
| Lazy/proxy DTO с `afterMap` | Raw validation → mapper → `afterMap(array)` → проверки источников → deferred object; инициализация позже, без повторения `afterMap`. |

Дополнительная стадия копирует верхние ячейки массива только при включённом callback. Это предотвращает запись через верхние PHP reference-ячейки; вложенные ссылки и объекты глубоко не клонируются. Побочные эффекты callback не откатываются. Новая попытка mapping после отказа допустима; поздний retry ленивой инициализации не должен повторять уже завершённый mapping callback.

## 7. Проверка и совместимость

Существующие примеры и callback-варианты выполнены на production-реализации с проверкой происхождения загруженного кода. Проверены один callback для payload/query с разными входными значениями, замена прямой зависимости, регистрация readonly custom mapper, отказ raw validation с повторной попыткой и конфликт итогового источника. Синтаксис ссылки на метод непосредственно в атрибуте проверен на PHP 8.5; формы с runtime-константами — на обеих версиях PHP.

Регрессии покрывают [публичный API и совместимость](../../tests/Contract/RequestMappingCallbackContractTest.php), [границы данных/источников/ABI](../../tests/Contract/RequestMappingCallbackBoundariesContractTest.php) и [lifecycle/recovery/lifetime](../../tests/Contract/RequestMappingCallbackLifecycleContractTest.php). Прежние suites request mapping и `Transform` сохранены. [Нормативная приёмка](php85-callbacks.ru.md#доказательства-и-последовательность-реализации) остаётся контрактом последующих изменений.

Существующий [runtime benchmark](../../benchmarks/RuntimeBench.php) содержит сопоставимые request-array сценарии без callback и с ним для 1, 16, 256 и 4096 ключей, а также прямой DI-суффикс и DTO. `DI_BENCH_CASES` выбирает, например, `request/array-16,request/callback-16,request/suffix,request/dto,request/dtoCallback`; `DI_BUILD_ITERATIONS` задаёт число итераций request-сценариев. `COMPONENTA_DI_BENCH_AUTOLOAD` и `COMPONENTA_DI_BENCH_SOURCE` задаются так же, как для других сценариев benchmark. Измеряется локальная стоимость операции, а не throughput приложения. Включённый путь добавляет линейную shallow copy и обычное разрешение callback/DI; выключенный не выполняет ни callback reflection, ни эту копию.

`afterMap` полезен для повторяющейся задачи **нормализации нескольких полей с DI до автоматического создания DTO**. Простой декларативный mapping, пользовательский `transform()`, array вместе с `Transform` и прикладной адаптер из §3 остаются допустимыми альтернативами. Новый параметр не объявляет ни один путь руководства устаревшим.
