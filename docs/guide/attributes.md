# Attributes and HTTP request mapping

Attributes control dependency sources, object initialization and HTTP argument mapping. The container applies them when constructing a class through reflection or resolving parameters for `call()`. An application factory or `ClassDefinition` does not execute the target object's DI attributes.

## Dependency sources and object lifecycle

| Attribute | Purpose |
| --- | --- |
| `#[Config]`, `#[Env]` | Supply a value from application configuration or environment. |
| `#[EntryId]` | Resolve an explicit service identifier. |
| `#[Make]` | Create an object through `make()` rather than retrieve a shared entry. |
| `#[Inject]` | Inject a property using its declared type. |
| `#[Cast]`, `#[Transform]` | Cast or transform a parameter/property value. |
| `#[Init]` | Initialize a property with a callable and explicit arguments, without automatic parameter injection. |
| `#[SetUp]` | Invoke ordered initialization methods or callbacks after property injection. |
| `#[NoConstructor]` | Suppress the reflected class constructor. |
| `#[Lazy]`, `#[Proxy]` | Use the selected lazy-object strategy. |

Each attribute has supported declaration targets. For example, `#[Inject]` is a property attribute, `#[SetUp]` is a repeatable class attribute and `#[Transform]` supports both parameters and properties.

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

An ordinary reflected `ApiClient` can receive its endpoint from configuration and its logger through property injection. See [Lazy objects](lazy-objects.md) for the restrictions of native lazy creation.

## HTTP parameter sources

The PSR-7 source attributes are `#[CurrentRequest]`, `#[CurrentUri]`, `#[Header]`, `#[Cookie]`, `#[QueryParam]`, `#[PayloadParam]`, `#[RequestAttribute]`, `#[ServerParam]`, and `#[UploadedFile]`. `CurrentRequest` and `CurrentUri` are invocation-only sources: a plain request or URI type hint does not select the current HTTP request.

`#[MapRequest]` maps selected sources into an array or data-transfer object. The default source is the parsed request body. The dedicated alternatives are `#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]`, and `#[MapUploadedFiles]`.

```php
use Componenta\DI\Attribute\MapRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateUserInput
{
    public function __construct(public string $name) {}
}

$handler = static fn(#[MapRequest] CreateUserInput $input): string => $input->name;

// $request is a PSR-7 request whose parsed body is ['name' => 'Ada'].
$name = $container->call($handler, [
    ServerRequestInterface::class => $request,
]);
```

For multiple request sources, choose their order and conflict policy explicitly:

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

The mapper supports source selection, field renaming and exclusion, default values, casting, and configured validation. If two sources supply the same key with different values, the default conflict policy rejects it; `RequestDataConflictPolicy::FirstWins` is an alternative. Only explicitly selected sources are read. Data from a request must not override a parameter that has an explicit DI source attribute such as `#[EntryId]`.

`MapRequest(afterMap: ...)` optionally transforms the whole mapped array before object construction. Existing source-specific mappers and custom `transform()` implementations remain supported. See [Callbacks and union types](../migrations/di6-callbacks.md).

See the [complete attribute reference](attribute-reference.md) and [request mapping guide](request-mapping.md) for signatures, composition rules, and examples.
