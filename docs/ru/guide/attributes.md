# Атрибуты и обработка HTTP-запросов

Атрибуты определяют источники зависимостей, порядок инициализации объектов и преобразование данных HTTP-запроса. Контейнер применяет их при создании класса средствами отражения PHP и при разрешении параметров через `call()`. Фабрика приложения и `ClassDefinition` не исполняют атрибуты внедрения зависимостей целевого объекта.

## Источники зависимостей и создание объектов

| Атрибут | Назначение |
| --- | --- |
| `#[Config]`, `#[Env]` | Получение значений из конфигурации и окружения. |
| `#[EntryId]` | Получение службы по явно заданному идентификатору. |
| `#[Make]` | Создание объекта через `make()` вместо получения общего значения. |
| `#[Inject]` | Внедрение свойства по объявленному типу. |
| `#[Cast]`, `#[Transform]` | Приведение типа и преобразование значения параметра или свойства. |
| `#[Init]` | Инициализация свойства посредством вызова функции или метода. |
| `#[SetUp]` | Последовательное выполнение действий инициализации после внедрения свойств. |
| `#[NoConstructor]` | Создание объекта без вызова конструктора. |
| `#[Lazy]`, `#[Proxy]` | Выбор способа отложенного создания объекта. |

Каждый атрибут допускается только в определённых местах. Например, `#[Inject]` применяется к свойству, `#[SetUp]` — к классу и может повторяться, а `#[Transform]` — к свойствам и параметрам.

```php
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\Inject;

final class ApiClient
{
    #[Inject]
    public LoggerInterface $logger;

    public function __construct(
        #[Config('api.endpoint')] public string $endpoint,
    ) {}
}
```

При обычном создании `ApiClient` средствами отражения адрес поступает из конфигурации, а регистратор сообщений внедряется в свойство. Ограничения отложенного создания приведены в [соответствующем разделе](lazy-objects.md).

## Источники параметров HTTP-запроса

Атрибуты для данных PSR-7: `#[CurrentRequest]`, `#[CurrentUri]`, `#[Header]`, `#[Cookie]`, `#[QueryParam]`, `#[PayloadParam]`, `#[RequestAttribute]`, `#[ServerParam]` и `#[UploadedFile]`. Первые два доступны только во время вызова функции или метода. Одного указания типа запроса или адреса недостаточно для выбора текущего HTTP-запроса.

`#[MapRequest]` преобразует выбранные источники в массив или объект для передачи данных. По умолчанию читается разобранное тело запроса. Для отдельных источников предусмотрены `#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]` и `#[MapUploadedFiles]`.

```php
use Componenta\DI\Attribute\MapRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateUserInput
{
    public function __construct(public string $name) {}
}

$handler = static fn(#[MapRequest] CreateUserInput $input): string => $input->name;

// В $request находится запрос PSR-7 с разобранным телом ['name' => 'Ada'].
$name = $container->call($handler, [
    ServerRequestInterface::class => $request,
]);
```

При чтении нескольких источников можно явно задать их порядок и правило разрешения конфликтов:

```php
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;

$handler = static fn(
    #[MapRequest(
        sources: [RequestDataSource::Payload, RequestDataSource::Query],
        conflictPolicy: RequestDataConflictPolicy::FirstWins,
    )] CreateUserInput $input
): string => $input->name;
```

Преобразователь поддерживает выбор источников, переименование и исключение полей, значения по умолчанию, приведение типов и настроенную проверку данных. Если два источника передают разные значения под одним ключом, стандартная политика отклоняет такое сочетание. При необходимости можно выбрать `RequestDataConflictPolicy::FirstWins`: значение первого источника сохраняется. Читаются только явно выбранные источники. Данные запроса не могут заменять параметр с собственным атрибутом источника, например `#[EntryId]`.

Необязательный аргумент `MapRequest(afterMap: ...)` позволяет преобразовать весь полученный массив перед созданием объекта. Прежние атрибуты отдельных источников и пользовательские реализации `transform()` сохраняются. Подробнее — в [разделе о функциях обратного вызова](../migrations/di6-callbacks.md).

Точные сигнатуры, правила сочетания и примеры приведены в [полном справочнике атрибутов](attribute-reference.md) и [руководстве по обработке запросов](request-mapping.md).
