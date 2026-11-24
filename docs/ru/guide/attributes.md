# Атрибуты и HTTP-запросы

## Внедрение зависимостей и конфигурация

DI разрешает параметры конструкторов и callable. Для сервисов, создаваемых через reflection, доступно также внедрение свойств.

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

`#[Config]` и `#[Env]` читают настройки; `#[EntryId]` получает сервис по ID, `#[Make]` создаёт новый объект, `#[Inject]` внедряет свойство по типу. Также доступны `#[Cast]`, `#[Init]`, `#[SetUp]`, `#[NoConstructor]`, `#[Lazy]`, `#[Proxy]` и `#[Transform]`.

Фабрики приложения и `ClassDefinition` самостоятельно создают результат и не исполняют DI-атрибуты целевого класса. См. [Определения](definitions.md).

## Источники HTTP-запроса

Явные источники PSR-7: `#[CurrentRequest]`, `#[CurrentUri]`, `#[Header]`, `#[Cookie]`, `#[QueryParam]`, `#[PayloadParam]`, `#[RequestAttribute]`, `#[ServerParam]`, `#[UploadedFile]`. Текущий запрос/URI доступны при вызове callable; обычный PSR-7 type hint не означает выбор текущего запроса.

`#[MapRequest]` отображает выбранные источники в массив или DTO, по умолчанию используя разобранное тело. Альтернативы: `#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]`, `#[MapUploadedFiles]`.

```php
use Componenta\DI\Attribute\MapRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateUserInput
{
    public function __construct(public string $name) {}
}

$handler = static fn(#[MapRequest] CreateUserInput $input): string => $input->name;
// У $request разобранное body ['name' => 'Ada'].
$name = $container->call($handler, [ServerRequestInterface::class => $request]);
```

Mapping поддерживает выбор и переименование полей, значения по умолчанию, приведение типов и настроенную валидацию. Конфликтующие значения разных HTTP-источников по умолчанию отклоняются. HTTP-поля не могут подменять параметры с явным DI-источником вроде `#[EntryId]`.

DI 6 добавляет необязательный `MapRequest(afterMap: ...)`; см. [Миграцию callbacks](../migrations/di6-callbacks.md).
