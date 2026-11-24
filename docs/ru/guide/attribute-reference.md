# Справочник встроенных атрибутов

Все перечисленные классы относятся к пространству имён `Componenta\DI\Attribute`. Аргументы конструкторов указаны в порядке объявления; при именованной передаче сохраняются исходные имена. **К** — класс, **С** — свойство, **П** — параметр. Для библиотеки требуется PHP 8.5.13 или новее.

## Источники зависимостей и преобразование значений

| Атрибут | Место применения | Аргументы конструктора | Назначение |
| --- | --- | --- | --- |
| `Config` | С, П | `string|ConfigPath|null $path = null, mixed $default = DefaultValue::None` | Чтение настройки приложения. |
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

## Создание и инициализация объектов

| Атрибут | Место применения | Аргументы конструктора | Назначение |
| --- | --- | --- | --- |
| `Init` | С | `mixed $callable, array $params = []` | Получение значения свойства через вызов функции или метода с поддержкой зависимостей. |
| `SetUp` | К; допускает повторение | `string|Closure $method, array $params = []` | Вызов общедоступного метода экземпляра либо функции, получающей объект первым аргументом, после внедрения свойств. |
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

## Отдельные значения HTTP-запроса

Все атрибуты этой таблицы применяются **только к параметрам**. Для их работы при вызове должна быть доступна соответствующая реализация `ServerRequestInterface` из PSR-7.

| Атрибут | Аргументы конструктора | Получаемое значение |
| --- | --- | --- |
| `CurrentRequest` | Без аргументов | Текущий запрос `ServerRequestInterface`. |
| `CurrentUri` | Без аргументов | Адрес запроса `UriInterface`. |
| `Header` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Значение строки заголовка по имени; можно задать значение при отсутствии заголовка. |
| `Cookie` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Значение файла данных браузера из `getCookieParams()`. |
| `QueryParam` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Параметр строки запроса; без имени используется имя аргумента функции. |
| `PayloadParam` | `string|ConfigPath|null $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Поле разобранного тела; `ConfigPath` позволяет обратиться к вложенному значению. |
| `RequestAttribute` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Атрибут запроса PSR-7, по умолчанию с именем параметра. |
| `ServerParam` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Значение из `getServerParams()`. |
| `UploadedFile` | `string $name` | Загруженный файл или вложенный массив файлов. Для вложенных значений допускается разделение имени точками; при отсутствии возвращается `null`. |

Если обязательное именованное значение отсутствует, большинство источников выбрасывают исключение, пока не задан `default`. У `UploadedFile` отсутствующий файл обозначается `null`. Аргумент `cast` позволяет выбрать преобразователь извлечённого значения. `CurrentRequest` и `CurrentUri` не предназначены для сохранения в зависимостях конструктора.

```php
use Componenta\DI\Attribute\{CurrentRequest, Header, QueryParam};
use Psr\Http\Message\ServerRequestInterface;

$handler = static function (
    #[CurrentRequest] ServerRequestInterface $request,
    #[Header('X-Trace', default: '')] string $trace,
    #[QueryParam] int $page = 1,
): array {
    return [$request->getMethod(), $trace, $page];
};
```

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

Примеры приведены в разделе [«Обработка HTTP-данных»](request-mapping.md), а правила внедрения зависимостей в функции — в разделе [«Функции обратного вызова»](../migrations/di6-callbacks.md).
