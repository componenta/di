# Обработка данных HTTP-запросов

Данные HTTP-запроса передаются параметрам явно через атрибуты. Контейнер не определяет текущий запрос по одному лишь указанному типу: при вызове необходимо предоставить объект `ServerRequestInterface` стандарта PSR-7. Все атрибуты источников HTTP-данных применяются к параметрам.

## Получение отдельных значений

Для отдельных полей используются `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]` и `#[UploadedFile]`. Атрибуты `#[CurrentRequest]` и `#[CurrentUri]` предоставляют сам запрос и его адрес. Если обязательное значение отсутствует, обычно возникает исключение, пока в атрибуте не задан `default`; `UploadedFile` возвращает `null`. При отсутствии явно заданного имени `QueryParam`, `PayloadParam` и `RequestAttribute` используют имя параметра функции.

```php
use Componenta\DI\Attribute\{Header, PayloadParam, QueryParam};
use Psr\Http\Message\ServerRequestInterface;

$handler = static function (
    #[QueryParam(default: 1)] int $page,
    #[PayloadParam('userName')] string $name,
    #[Header('X-Request-ID', default: '')] string $requestId,
): array {
    return [$page, $name, $requestId];
};

$result = $container->call($handler, [
    ServerRequestInterface::class => $request,
]);
```

Атрибуты с аргументом `cast` допускают выбор преобразователя из библиотеки Componenta Caster. `PayloadParam` принимает также `ConfigPath` для обращения к вложенным значениям тела; `UploadedFile` поддерживает путь к файлу с разделением частей точками.

## Преобразование в объект или массив

`#[MapRequest]` читает один либо несколько явно выбранных источников и возвращает массив или объект, создаваемый обычным способом контейнера.

```php
use Componenta\DI\Attribute\{MapRequest, RequestDataSource};
use Psr\Http\Message\ServerRequestInterface;

final readonly class SearchInput
{
    public function __construct(
        public string $term,
        public int $page = 1,
    ) {}
}

$handler = static fn(
    #[MapRequest(sources: [RequestDataSource::Query])] SearchInput $input
): SearchInput => $input;

$input = $container->call($handler, [
    ServerRequestInterface::class => $request,
]);
```

Аргумент `sources` содержит упорядоченный список значений `RequestDataSource`:

| Значение | Метод получения |
| --- | --- |
| `Payload` | `getParsedBody()`: разобранное тело в виде массива или объекта; источник по умолчанию |
| `Query` | `getQueryParams()` |
| `Headers` | `getHeaders()`: значения каждого заголовка объединяются в строку |
| `Cookies` | `getCookieParams()` |
| `Attributes` | `getAttributes()` |
| `Server` | `getServerParams()` |
| `Files` | `getUploadedFiles()` |

Если заданы непустые списки `attributes` или `files`, но соответствующий источник не указан в `sources`, выбранные атрибуты, а затем файлы объединяются до явно перечисленных источников. При включении источника в `sources` сохраняется его указанное место. Повторное объявление одного источника отклоняется. При `conflictPolicy: RequestDataConflictPolicy::Reject` одинаковые ключи с различающимися значениями приводят к `RequestDataConflictException`; совпадающие значения допускаются. Политика `FirstWins` оставляет значение первого источника, не сравнивая его с последующими.

## Аргументы MapRequest

```php
#[MapRequest(
    sources: [RequestDataSource::Payload, RequestDataSource::Query],
    map: ['user_name' => 'name', '?legacy_id' => 'id'],
    exclude: ['debug'],
    conflictPolicy: RequestDataConflictPolicy::FirstWins,
    defaults: ['active' => true],
    cast: [],
    sortMap: [],
    attributes: ['route_id'],
    files: ['avatar'],
    afterMap: null,
)]
```

- `map` переименовывает существующие ключи. Знак `?` в начале исходного имени делает поле необязательным. Отсутствие обязательного поля и совпадение конечных имён приводят к ошибке.
- `exclude` удаляет указанные ключи после остальных преобразований.
- `defaults` дополняет только отсутствующие ключи. Существующее значение, включая `null`, сохраняется.
- `cast` сопоставляет полю имя преобразователя. Преобразуются только присутствующие значения.
- `sortMap` сопоставляет значению поля `sort` данные для `orderBy`. Если `sortMap` непуст, а поле `sort` отсутствует, в результат добавляется `orderBy => null`; поля `sort` и `order` удаляются.
- `attributes` и `files` выбирают атрибуты запроса и загруженные файлы дополнительно к основным источникам. Запись `[MapRequest::ALL]` выбирает все элементы соответствующего источника. Добавлять другие имена рядом со знаком выбора всех элементов нельзя.
- `afterMap` — необязательная функция, получающая весь собранный массив и дополнительные зависимости. Она должна вернуть массив.

Порядок работы: **чтение источников и разрешение конфликтов → проверка исходных данных объекта при наличии зарегистрированного средства проверки → переименование полей → приведение типов → дополнение значениями по умолчанию → обработка сортировки → исключение полей → необязательная функция `afterMap` → создание объекта**. Перед вызовом конструктора проверяются конфликты с явно указанными источниками зависимостей. При позднем обнаружении несовместимых атрибутов проверка повторяется. Побочные эффекты уже выполненных функций не отменяются.

Для создания объекта передачи данных нужны строковые именованные ключи до и после преобразования. Если в объявленном типе допускается `array`, параметр получает массив; иначе преобразование в объект допускает не более одного имени класса или интерфейса. Параметр с типом `array` получает массив без создания объекта. Если средство проверки данных не зарегистрировано, предварительная проверка полей объекта не выполняется.

## Атрибуты отдельных источников

Семь специализированных атрибутов: `MapRequestPayload`, `MapQueryString`, `MapHeaders`, `MapCookies`, `MapRequestAttributes`, `MapServerParams` и `MapUploadedFiles`. Каждый наследует `RequestMapper` и принимает только `map: []` и `conflictPolicy: null`, а не все аргументы `MapRequest`. Пользовательские наследники могут переопределять `transform()` и самостоятельно получать данные.

Общий метод `RequestMapper::transform(array $data): array` применяет правила преобразования. Свойство `RequestMapper::$provider` содержит поставщика преобразователей и задаётся при обработке контейнером. Массив `$map` доступен для чтения извне, но изменяется только самим классом и наследниками. Конструктор принимает `(array $map = [], ?RequestDataConflictPolicy $conflictPolicy = null)`.

Для собственного источника реализуйте `RequestDataExtractorInterface::extract(ServerRequestInterface): array` и `MapperInterface::transform(array): array`. Для одного значения достаточно `ExtractorInterface`; при необходимости используются `ParameterNameAwareExtractorInterface` и `CastableInterface`. Дополнительную функцию преобразования можно предоставить через `CallbackMapperInterface` со свойством `?Closure $afterMap`, доступным для чтения.

## Ошибки и ограничения

`RequestDataConflictException` содержит конфликтующий ключ и имена источников. `RequestParameterSourceConflictException` указывает поле объекта, которое конфликтует с явно заданным атрибутом источника зависимости. Неизвестный преобразователь и неверный результат функции обратного вызова приводят к ошибкам разрешения. Полный перечень атрибутов с конструкторами и допустимыми местами применения приведён в [справочнике](attribute-reference.md).
