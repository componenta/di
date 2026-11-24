# Attributes and HTTP request mapping

## Injection and configuration

DI resolves constructor and callable parameters. Reflection-created services can also use property injection.

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

`#[Config]` and `#[Env]` read settings; `#[EntryId]` loads a named service, `#[Make]` creates a fresh object and `#[Inject]` injects a property by type. Other attributes include `#[Cast]`, `#[Init]`, `#[SetUp]`, `#[NoConstructor]`, `#[Lazy]`, `#[Proxy]` and `#[Transform]`.

Application factories and `ClassDefinition` own their results and do not execute the target's DI injection/lifecycle attributes. See [Definitions](definitions.md).

## HTTP request sources

Explicit PSR-7 sources include `#[CurrentRequest]`, `#[CurrentUri]`, `#[Header]`, `#[Cookie]`, `#[QueryParam]`, `#[PayloadParam]`, `#[RequestAttribute]`, `#[ServerParam]` and `#[UploadedFile]`. The current-request/URI attributes are invocation-only; a plain PSR-7 type does not implicitly mean the current request.

`#[MapRequest]` maps selected sources to an array or DTO, defaulting to parsed body. Alternatives: `#[MapRequestPayload]`, `#[MapQueryString]`, `#[MapHeaders]`, `#[MapCookies]`, `#[MapRequestAttributes]`, `#[MapServerParams]` and `#[MapUploadedFiles]`.

```php
use Componenta\DI\Attribute\MapRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateUserInput
{
    public function __construct(public string $name) {}
}

$handler = static fn(#[MapRequest] CreateUserInput $input): string => $input->name;
// $request has parsed body ['name' => 'Ada'].
$name = $container->call($handler, [ServerRequestInterface::class => $request]);
```

Mapping supports field selection, renaming, defaults, casting and configured validation. Conflicting values from multiple request sources are rejected by default. HTTP fields cannot replace parameters with recognized explicit DI sources such as `#[EntryId]`.

DI 6 adds optional `MapRequest(afterMap: ...)`; see [Callback migration](../migrations/di6-callbacks.md).
