# Built-in attribute reference

All classes below belong to `Componenta\DI\Attribute`. Constructor arguments are listed in declaration order; named arguments use the same names. **C** = class, **P** = property, **A** = parameter. An attribute can only be used on the indicated declaration targets. PHP 8.5.13+ is required by the package.

## Dependency sources and conversion

| Attribute | Target | Constructor arguments and defaults | Behavior |
| --- | --- | --- | --- |
| `Config` | P, A | `string|ConfigPath|null $path = null, mixed $default = DefaultValue::None` | Read a configuration value. The default sentinel means no fallback was supplied. |
| `Env` | P, A | `?string $name = null, mixed $default = DefaultValue::None` | Read an environment value, with conversion for supported target types. |
| `EntryId` | P, A | `string $value` | Resolve the explicitly named container entry through its sharing rules. |
| `Make` | P, A | `?string $entry = null, array $params = []` | Create an object through `FactoryInterface::make()`, optionally naming an entry and passing constructor parameters. |
| `Inject` | P | No arguments | Inject a property from the container using its declared class/interface type. |
| `Cast` | P, A | `string $name, mixed $default = DefaultValue::None` | Apply a named caster to an available value, with optional default. |
| `Transform` | P, A; repeatable | `Closure $callback` | Transform the current value. The callback's first argument is the value; subsequent parameters are resolved through DI. |

`ConfigPath` and `DefaultValue` come from Componenta Config. `Transform` does not create a missing required source. Multiple `Transform` attributes execute in declaration order; with `Cast`, declaration order applies unless composition rules override it.

```php
use Componenta\DI\Attribute\{Config, Env, EntryId, Make, Transform};

final class Action
{
    public function __construct(
        #[Config('api.endpoint')] public string $endpoint,
        #[Env('APP_MODE', default: 'production')] public string $mode,
        #[EntryId('logger.primary')] public LoggerInterface $logger,
        #[Make(Client::class, ['timeout' => 5])] public Client $client,
        #[Transform(trim(...))] public string $label = ' example ',
    ) {}
}
```

The example depends on the named logger and client being registered. `#[Transform(trim(...))]` uses PHP 8.5 constant-expression callable syntax. It transforms an explicitly provided or default value; the `label` default is only used when no argument is supplied.

## Construction and lifecycle

| Attribute | Target | Constructor arguments and defaults | Behavior |
| --- | --- | --- | --- |
| `Init` | P | `mixed $callable, array $params = []` | Populate a property by invoking a DI-aware callable with optional parameters. |
| `SetUp` | C; repeatable | `string|Closure $method, array $params = []` | Invoke a public instance method (string form), or a callback receiving the instance first, after property injection. Repeated hooks run in declaration order. |
| `NoConstructor` | C | None | Skip the constructor, while retaining property injection and setup. |
| `Lazy` | C | None | Select native lazy-ghost creation for reflection-created instances. |
| `Proxy` | C, P, A | `?string $class = null` | Select a native virtual proxy; at injection points optionally supply the concrete proxy class. |

`Lazy` and `Proxy` require a class eligible for the selected factory. The default PHP-native adapter requires at least one backed instance property and a compatible proxy backing object. `ClassDefinition` and application factories do not execute these target attributes. See [Lazy objects](lazy-objects.md).

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

## Individual PSR-7 request values

All attributes in this table target **parameters only** and require the corresponding current PSR-7 `ServerRequestInterface` to be supplied to the invocation.

| Attribute | Constructor arguments and defaults | Extracted value |
| --- | --- | --- |
| `CurrentRequest` | None | Current `ServerRequestInterface`. |
| `CurrentUri` | None | Current request's `UriInterface`. |
| `Header` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | `getHeaderLine($name)`; use `default` for a missing header. |
| `Cookie` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Value from `getCookieParams()`. |
| `QueryParam` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Query value; omitted name uses the parameter name. |
| `PayloadParam` | `string|ConfigPath|null $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | Parsed body field; `ConfigPath` supports nested fields. Omitted name uses the parameter name. |
| `RequestAttribute` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` | PSR-7 request attribute; omitted name uses the parameter name. |
| `ServerParam` | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` | Entry from `getServerParams()`. |
| `UploadedFile` | `string $name` | Uploaded file or nested file array; dot-separated names may address nested values. Returns `null` when absent. |

For the first eight extractors, missing required named values without a supplied `default` fail. `UploadedFile` is nullable. Attributes with `cast` allow a named caster to transform extracted input. `CurrentRequest` and `CurrentUri` cannot be used as stored constructor sources.

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

## Request mapping attributes

All mapper attributes target **parameters only**, return an array or construct a DTO, and inherit `RequestMapper::transform(array $data): array`.

| Attribute | Constructor arguments | Source |
| --- | --- | --- |
| `MapRequest` | `sources, map, exclude, conflictPolicy, defaults, cast, sortMap, attributes, files, afterMap` | Selected sources, defaulting to the parsed body. See full signature below. |
| `MapRequestPayload` | `array $map = [], ?RequestDataConflictPolicy $conflictPolicy = null` | Parsed body. |
| `MapQueryString` | Same as `MapRequestPayload` | Query parameters. |
| `MapHeaders` | Same | HTTP headers (joined into header-line strings). |
| `MapCookies` | Same | Cookies. |
| `MapRequestAttributes` | Same | Request attributes. |
| `MapServerParams` | Same | Server parameters. |
| `MapUploadedFiles` | Same | Uploaded files. |

`MapRequest` constructor (all arguments are optional):

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

`RequestDataSource` enum values: `Payload`, `Query`, `Headers`, `Cookies`, `Attributes`, `Server`, `Files`. `RequestDataConflictPolicy` values: `Reject`, `FirstWins`. `MapRequest::ALL` is `'*'`, the single-element wildcard for `attributes` or `files`. No other element may accompany it in a selector array.

Mapping can rename fields, exclude them, supply defaults, cast data and validate input through the configured providers. The optional `afterMap` closure receives the complete mapped array as its first argument and must return an array before DTO creation. Earlier source-specific mappers remain valid; they don't acquire `MapRequest`'s additional constructor arguments.

See [HTTP mapping](request-mapping.md) for examples and the public `RequestMapper` extension contract, or [Callbacks](../migrations/di6-callbacks.md) for binding and compatibility rules.
