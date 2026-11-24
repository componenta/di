# Справочник встроенных атрибутов

Все перечисленные классы относятся к пространству имён `Componenta\DI\Attribute`. Аргументы конструкторов указаны в порядке объявления; при именованной передаче сохраняются исходные имена. **К** — класс, **С** — свойство, **П** — параметр. Для библиотеки требуется PHP 8.5.13 или новее.

## Источники зависимостей и преобразование значений

| Атрибут | Место применения | Аргументы конструктора | Назначение |
| --- | --- | --- | --- |
| `Config` | С, П | `string\|ConfigPath\|null $path = null, mixed $default = DefaultValue::None` | Чтение настройки приложения. |
| `Env` | С, П | `?string $name = null, mixed $default = DefaultValue::None` | Получение значения окружения с предусмотренным преобразованием типа. |
| `EntryId` | С, П | `string $value` | Получение службы по заданному идентификатору с соблюдением её правил повторного использования. |
| `Make` | С, П | `?string $entry = null, array $params = []` | Создание объекта через `FactoryInterface::make()` с необязательными аргументами. |
| `Inject` | С | Без аргументов | Внедрение свойства по объявленному классу или интерфейсу. |
| `Cast` | С, П | `string $name, mixed $default = DefaultValue::None` | Приведение имеющегося значения через именованный преобразователь. |
| `Transform` | С, П; допускает повторение | `Closure $callback` | Последовательное преобразование значения функцией с поддержкой внедрения зависимостей. |

`ConfigPath` и `DefaultValue` предоставляются библиотекой Componenta Config. Значение `DefaultValue::None` означает отсутствие указанного значения по умолчанию. `Transform` не получает недостающее обязательное значение самостоятельно. Несколько атрибутов `Transform` исполняются в порядке объявления. Относительный порядок `Cast` и `Transform` также следует объявлению, если правила сочетания атрибутов не устанавливают иного.

```php
use Componenta\DI\Attribute\{Config, Env, EntryId, Make, Transform};

final class Action
{
    public function __construct(
        #[Config('api.endpoint')] public string $endpoint,
        #[Env('APP_MODE', default: 'production')] public string $mode,
        #[EntryId('logger.primary')] public LoggerInterface $logger,
        #[Make(Client::class, ['timeout' => 5])] public Client $client,
        #[Transform(trim(...))] public string $label = ' пример ',
    ) {}
}
```

В примере должны быть зарегистрированы требуемые службы. Для выражения `trim(...)` внутри атрибута необходим PHP 8.5; минимальная поддерживаемая версия библиотеки — 8.5.13. Значение `label` берётся из переданного аргумента либо из значения по умолчанию.

### Значения по умолчанию и сочетания атрибутов

- Если путь `Config` равен `null`, используется имя параметра или свойства. Строка обозначает ключ конфигурации, а `ConfigPath` позволяет задать вложенный путь. При отсутствии ключа используется явно заданное значение по умолчанию; `DefaultValue::None` означает ошибку при отсутствии значения.
- Если имя `Env` равно `null`, имя объявления переводится в верхний регистр, а между строчной и следующей заглавной буквами вставляется подчёркивание: `appMode` превращается в `APP_MODE`. Для типов `string`, `int`, `float`, `bool` и `array` используются соответствующие методы окружения. Параметр переменной длины получает массив. При отсутствии переменной возвращается явно заданное значение по умолчанию.
- Если идентификатор `Make` равен `null`, используется доступный объявленный тип класса или интерфейса либо имя параметра или свойства. Применяется собственный способ создания объекта, а не получение общего значения.
- `Inject` требует свойства с типом класса или интерфейса. Для получения записи по заданному идентификатору применяется `EntryId`.
- `Cast` получает именованный преобразователь через `Componenta\Caster\CasterProviderInterface`. При отсутствии значения параметра последовательно проверяются значение по умолчанию атрибута, пустой массив для параметра переменной длины, стандартное значение параметра PHP и допустимость `null`. Для свойства используется значение ранее сработавшего источника, аргумент создания или явно заданное значение по умолчанию. Неизвестный преобразователь и несовместимый результат приводят к ошибке разрешения.
- Встроенные правила допускают не более одного атрибута источника значения для одной цели, несколько преобразований, один способ создания класса и одну политику конструктора. `Lazy` и `Proxy` нельзя совмещать у одного класса; `NoConstructor` совместим с каждым из них. У параметра или свойства `Proxy` совместим с `Make`, но не с другим источником значения. Преобразования выполняются после источников и выбора заместителя.

```php
use Componenta\DI\Attribute\{Cast, Config, Env, Inject, Make};

final class Settings
{
    #[Config(default: 'UTC')]
    public string $timezone;

    #[Env(default: 30)]
    public int $requestTimeout;

    #[Inject]
    public LoggerInterface $logger;

    #[Make(Client::class)]
    public Client $client;

    #[Config('display.name'), Cast('trim')]
    public string $displayName;
}
```

Преобразователь с именем `trim` и необходимые службы должны быть доступны в настроенном контейнере.

## Создание и инициализация объектов

| Атрибут | Место применения | Аргументы конструктора | Назначение |
| --- | --- | --- | --- |
| `Init` | С | `mixed $callable, array $params = []` | Получение значения свойства через вызов функции или метода с поддержкой зависимостей. |
| `SetUp` | К; допускает повторение | `string\|Closure $method, array $params = []` | Вызов общедоступного метода экземпляра либо функции, получающей объект первым аргументом, после внедрения свойств. |
| `NoConstructor` | К | Без аргументов | Создание объекта без вызова конструктора с сохранением остальных действий инициализации. |
| `Lazy` | К | Без аргументов | Выбор нативного отложенного заполнения состояния объекта. |
| `Proxy` | К, С, П | `?string $class = null` | Выбор виртуального заместителя; при внедрении можно указать конкретный класс. |

Атрибуты `Lazy` и `Proxy` работают с классами, поддерживаемыми выбранной фабрикой. Стандартный механизм PHP требует хотя бы одного сохраняемого свойства экземпляра, а для виртуального заместителя — подходящий основной объект. Фабрики приложения и `ClassDefinition` не обрабатывают атрибуты целевого объекта. Подробности — в разделе [«Отложенное создание»](lazy-objects.md).

```php
use Componenta\DI\Attribute\{Inject, SetUp};

#[SetUp('initialize')]
final class Service
{
    #[Inject]
    public LoggerInterface $logger;

    public function initialize(): void
    {
        $this->logger->info('initialized');
    }
}
```

### Примеры инициализации

`Init` вызывает функцию или метод и записывает результат в свойство. Строковый вариант `SetUp` требует общедоступного, нестатического и не абстрактного метода экземпляра; замыканию первым аргументом передаётся созданный объект. Возвращаемое значение не используется. В явных `params` допускаются именованные значения и поддерживаемые описания `new Config(...)`, `new Env(...)` и `new EntryId(...)`.

```php
use Componenta\DI\Attribute\{Init, NoConstructor, SetUp};

#[NoConstructor]
#[SetUp('initialize')]
final class ImportedRecord
{
    #[Init(strlen(...), ['string' => 'example'])]
    public int $length;

    public bool $initialized = false;

    public function initialize(): void
    {
        $this->initialized = true;
    }
}
```

`NoConstructor` оставляет неинициализированными свойства без собственных значений по умолчанию и правил внедрения. Атрибут `#[Proxy]` у класса не принимает имя класса заместителя. В точке внедрения `#[Make('client'), Proxy(Client::class)]` выбирает конкретный совместимый класс заместителя и создание основного объекта по указанному идентификатору. Оба встроенных способа подробно описаны в разделе [«Отложенное создание»](lazy-objects.md).

## Отдельные значения HTTP-запроса

Все атрибуты этой таблицы применяются **только к параметрам**. Для их работы при вызове должна быть доступна соответствующая реализация `ServerRequestInterface` из PSR-7.

| Атрибут | Аргументы конструктора | Получаемое значение |
| --- | --- | --- |
| `CurrentRequest` | Без аргументов | Текущий запрос `ServerRequestInterface`. |
| `CurrentUri` | Без аргументов | Адрес запроса `UriInterface`. |
| `Header` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Значение строки заголовка по имени; можно задать значение при отсутствии заголовка. |
| `Cookie` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Значение сохранённых данных браузера из `getCookieParams()`. |
| `QueryParam` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Параметр строки запроса; без имени используется имя аргумента функции. |
| `PayloadParam` | `string\|ConfigPath\|null $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Поле разобранного тела; `ConfigPath` позволяет обратиться к вложенному значению. |
| `RequestAttribute` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Атрибут запроса PSR-7, по умолчанию с именем параметра. |
| `ServerParam` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Значение из `getServerParams()`. |
| `UploadedFile` | `string $name` | Загруженный файл или вложенный массив файлов. Для вложенных значений допускается разделение имени точками; при отсутствии возвращается `null`. |

У `Header`, `Cookie`, `QueryParam`, `PayloadParam`, `RequestAttribute` и `ServerParam` отсутствие обязательного поля приводит к исключению, если в атрибуте не задан `default`. Стандартное значение параметра PHP само по себе не заменяет отсутствующее поле запроса. У `UploadedFile` отсутствующий файл обозначается `null`. Аргумент `cast` позволяет выбрать преобразователь извлечённого значения. `CurrentRequest` и `CurrentUri` не предназначены для сохранения в зависимостях конструктора.

```php
use Componenta\DI\Attribute\{CurrentRequest, Header, QueryParam};
use Psr\Http\Message\ServerRequestInterface;

$handler = static function (
    #[CurrentRequest] ServerRequestInterface $request,
    #[Header('X-Trace', default: '')] string $trace,
    #[QueryParam(default: 1)] int $page,
): array {
    return [$request->getMethod(), $trace, $page];
};
```

Дополнительные независимые объявления параметров:

| Пример | Результат |
| --- | --- |
| `#[CurrentUri] UriInterface $uri` | Адрес запроса |
| `#[Cookie('session', default: null)] ?string $session` | Сохранённые данные сеанса |
| `#[PayloadParam('name')] string $name` | Поле тела запроса |
| `#[RequestAttribute('routeId')] string $routeId` | Атрибут запроса |
| `#[ServerParam('REMOTE_ADDR')] string $ip` | Серверный параметр |
| `#[UploadedFile('avatar')] ?UploadedFileInterface $avatar` | Файл или `null` |

## Атрибуты преобразования HTTP-данных

Все следующие атрибуты используются **только для параметров** и преобразуют данные в массив или объект передачи данных. Они наследуют метод `RequestMapper::transform(array $data): array`.

| Атрибут | Аргументы конструктора | Источник |
| --- | --- | --- |
| `MapRequest` | `sources, map, exclude, conflictPolicy, defaults, cast, sortMap, attributes, files, afterMap` | Выбранные источники, по умолчанию — разобранное тело. |
| `MapRequestPayload` | `array $map = [], ?RequestDataConflictPolicy $conflictPolicy = null` | Разобранное тело. |
| `MapQueryString` | Те же, что у `MapRequestPayload` | Строка запроса. |
| `MapHeaders` | Те же | Заголовки HTTP, объединённые в строки. |
| `MapCookies` | Те же | Данные браузера. |
| `MapRequestAttributes` | Те же | Атрибуты запроса. |
| `MapServerParams` | Те же | Серверные параметры. |
| `MapUploadedFiles` | Те же | Загруженные файлы. |

Полная сигнатура конструктора `MapRequest` с исходными значениями по умолчанию:

```php
new MapRequest(
    sources: [RequestDataSource::Payload],
    map: [],
    exclude: [],
    conflictPolicy: RequestDataConflictPolicy::Reject,
    defaults: [],
    cast: [],
    sortMap: [],
    attributes: [],
    files: [],
    afterMap: null,
);
```

У `RequestDataSource` есть значения `Payload`, `Query`, `Headers`, `Cookies`, `Attributes`, `Server`, `Files`. Возможные политики конфликта — `RequestDataConflictPolicy::Reject` и `RequestDataConflictPolicy::FirstWins`. Константа `MapRequest::ALL` равна `'*'`: она выбирает все атрибуты запроса либо все загруженные файлы и должна быть единственным элементом списка выбора.

Преобразование поддерживает переименование и исключение полей, значения по умолчанию, приведение типов и проверку исходных данных. Необязательная функция `afterMap` получает весь собранный массив первым аргументом и должна вернуть массив до создания объекта. Специализированные атрибуты сохраняют собственные конструкторы и не принимают все дополнительные параметры `MapRequest`.

Независимые примеры отдельных объявлений параметров (не самостоятельные инструкции PHP):

```php
#[MapRequestPayload] array $body
#[MapQueryString] array $query
#[MapHeaders] array $headers
#[MapCookies] array $cookies
#[MapRequestAttributes] array $attributes
#[MapServerParams] array $server
#[MapUploadedFiles] array $files
```

Примеры приведены в разделе [«Обработка HTTP-данных»](request-mapping.md), а правила внедрения зависимостей в функции — в разделе [«Функции обратного вызова»](../migrations/di6-callbacks.md).
