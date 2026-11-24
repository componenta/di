# Интерфейсы источников и преобразования HTTP-данных

Интерфейсы расширения находятся в `Componenta\DI\Resolver\Parameter\Request`; встроенные атрибуты — в `Componenta\DI\Attribute`. Все они работают с `ServerRequestInterface` стандарта PSR-7, переданным в текущую операцию вызова.

## Получение отдельных значений и преобразование массивов

| Интерфейс | Открытые методы или свойства | Назначение |
| --- | --- | --- |
| `ExtractorInterface` | `extract(ServerRequestInterface $request): mixed` | Получение одного значения запроса; наследует `ParameterSourceAttributeInterface`. |
| `ParameterNameAwareExtractorInterface` | `extractForParameter(ServerRequestInterface $request, string $parameterName): mixed` | Возможность использовать имя объявленного параметра, если имя источника не задано; наследует `ExtractorInterface`. |
| `RequestDataExtractorInterface` | `extract(ServerRequestInterface $request): array` | Получение полного массива исходных данных для преобразования; наследует `ParameterSourceAttributeInterface`. |
| `MapperInterface` | `transform(array $data): array` | Преобразование массива полей запроса. |
| `CastableInterface` | Свойство для чтения `?string $cast` | Выбор именованного средства приведения типа для одного значения. |
| `CallbackMapperInterface` | Свойство для чтения `?Closure $afterMap` | Необязательная функция после обычного преобразования; наследует `MapperInterface`. |

Собственный преобразователь, регистрируемый как атрибут, реализует **оба** интерфейса: `RequestDataExtractorInterface` и `MapperInterface`. Для получения отдельного значения достаточно `ExtractorInterface`. Регистрация выполняется через `AttributeDefinition` и обработчик атрибутов параметров. Стандартный `RequestAttributeHandler` поддерживает эти договоры.

## RequestMapper

`RequestMapper` — абстрактный базовый класс встроенных атрибутов `Map*`. Он реализует `MapperInterface` и `CasterProviderAwareInterface`:

```php
public function __construct(
    array $map = [],
    ?RequestDataConflictPolicy $conflictPolicy = null,
);
public function transform(array $data): array;
```

Свойство `$map` доступно извне для чтения, а изменяется классом и его наследниками. Открытое свойство `$provider` хранит средство приведения типов и по умолчанию использует `NullCasterProvider`, пока не задано другое. Защищённые настройки включают `$cast`, `$defaults`, `$sortMap`, `$exclude`, `$conflictPolicy`.

`transform()` последовательно выполняет переименование, приведение типов, дополнение значениями по умолчанию, обработку сортировки и исключение полей. Защищённый `mergeRequestData()` объединяет именованные массивы источников. Перечисление `RequestDataConflictPolicy` содержит `Reject = 'reject'` и `FirstWins = 'first_wins'`.

`ExtractsRequestData` — примесь для наследников, которым дополнительно нужны атрибуты запроса и загруженные файлы. Защищённые списки `$attributes` и `$files` принимают имена или единственный знак выбора всех элементов `['*']`. Защищённые методы `extractSharedSources()` и `extractSharedData()` получают отобранные значения.

## Встроенные источники отдельных значений

Каждый указанный класс предоставляет `extract(ServerRequestInterface $request)`; полный результат и правила выбора описаны в [справочнике атрибутов](../guide/attribute-reference.md).

| Класс атрибута | Особенности открытого интерфейса |
| --- | --- |
| `CurrentRequest`, `CurrentUri` | Возвращают запрос или его адрес; конструкторы без аргументов. |
| `Header`, `Cookie`, `ServerParam` | Конструктор `($name, $default = DefaultValue::None, $cast = null)`; получение данных соответствующего источника. |
| `QueryParam`, `RequestAttribute` | При отсутствии имени используют имя целевого параметра; дополнительно реализуют `extractForParameter()`. |
| `PayloadParam` | Принимает `ConfigPath` для вложенных полей; реализует `extractForParameter()`. |
| `UploadedFile` | Конструктор `(string $name)`; `extract(): UploadedFileInterface\|array\|null`. |

## Классы преобразования и перечисления

| Тип | Открытые элементы |
| --- | --- |
| `MapRequest` | Конструктор с `sources`, `map`, `exclude`, `conflictPolicy`, `defaults`, `cast`, `sortMap`, `attributes`, `files`, `afterMap`; `extract(ServerRequestInterface): array`; свойства для чтения `$sources`, `$attributes`, `$files`, `$afterMap`; `ALL = '*'`. |
| `MapRequestPayload`, `MapQueryString`, `MapHeaders`, `MapCookies`, `MapRequestAttributes`, `MapServerParams`, `MapUploadedFiles` | Наследуют конструктор и `transform()` класса `RequestMapper`; реализуют `extract(ServerRequestInterface): array` для соответствующего источника. |
| `RequestDataSource` | Значения `Payload`, `Query`, `Headers`, `Cookies`, `Attributes`, `Server`, `Files`. |
| `RequestDataConflictPolicy` | Значения `Reject`, `FirstWins`. |

Все встроенные атрибуты преобразования предназначены для параметров. Цель с типом `array` получает преобразованный массив; цель с типом класса создаётся обычным способом контейнера. Исходные данные для класса проверяются при наличии зарегистрированного средства проверки. HTTP-поле не может подменить параметр конструктора с явно заданным источником зависимости, в том числе при позднем обнаружении атрибута.

См. [Обработку HTTP-данных](../guide/request-mapping.md), [Все атрибуты](../guide/attribute-reference.md) и [RequestAttributeHandler](attribute-handlers.md).
