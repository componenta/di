# PSR-7 request mapping

Request mapping is an explicit parameter-injection mechanism. It never infers the current HTTP request from a plain type hint; supply the PSR-7 `ServerRequestInterface` when invoking the target. All request-source attributes are parameter attributes.

## Individual values

Use `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `#[Cookie]`, `#[RequestAttribute]`, `#[ServerParam]` and `#[UploadedFile]` for individual values. `#[CurrentRequest]` and `#[CurrentUri]` provide the current request and URI. Missing required values normally fail unless the attribute specifies `default`; `UploadedFile` returns `null` when absent. `QueryParam`, `PayloadParam` and `RequestAttribute` use the PHP parameter name if their own name is omitted.

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

Attributes that expose `cast` may use a named caster supplied by Componenta Caster. `PayloadParam` also accepts `ConfigPath` to access nested body values; `UploadedFile` supports dot-separated file paths.

## Map into a DTO or array

`#[MapRequest]` reads one or more explicitly chosen sources and returns either an array or an object constructed using the container's `make()` pipeline.

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

The `sources` argument is an ordered list of `RequestDataSource` cases:

| Case | PSR-7 getter |
| --- | --- |
| `Payload` | `getParsedBody()`, converted from an object or array (default) |
| `Query` | `getQueryParams()` |
| `Headers` | `getHeaders()`, joined per header into a string |
| `Cookies` | `getCookieParams()` |
| `Attributes` | `getAttributes()` |
| `Server` | `getServerParams()` |
| `Files` | `getUploadedFiles()` |

If nonempty `attributes` or `files` selections are provided without their source in `sources`, the selected attributes and then files are merged before the explicitly ordered sources. If their source is listed, its declared position is respected. Duplicate declarations of one source are rejected. With `conflictPolicy: RequestDataConflictPolicy::Reject`, duplicate keys with different values raise `RequestDataConflictException`; equal values are accepted. `FirstWins` keeps the first source's value without comparing the later value.

## MapRequest options

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

- `map`: rename existing keys from source to destination; a source prefixed by `?` is optional. Missing non-optional sources and name collisions fail.
- `exclude`: remove keys after the other transformations.
- `defaults`: fill keys that are absent; existing keys, including explicit `null`, are preserved.
- `cast`: key-to-caster-name map; only present values are cast through the configured provider.
- `sortMap`: map the source's `sort` value to an `orderBy` array; when this option is configured, absent `sort` produces `orderBy => null`, and `sort`/`order` are removed.
- `attributes`, `files`: select named request attributes/uploaded files in addition to the chosen primary sources; `[MapRequest::ALL]` selects all in that source. A wildcard cannot be combined with other names.
- `afterMap`: optional `Closure` receiving the whole mapped array and DI-resolved extra parameters; must return an array.

Processing order is: **source extraction and conflict handling → validation of raw data for a class-typed DTO (when a validation provider exists) → mapping/renaming → casting → defaults → sorting → exclusion → optional `afterMap` → DTO creation**. The source-conflict rules of explicit DI attributes are checked before data is given to the DTO constructor and after relevant late discoveries. Side effects from callbacks that already ran are not rolled back.

For DTOs, keys must be named strings before and after transformation. A target containing an `array` type arm receives an array; otherwise mapping to a class requires at most one class/interface name in its declared type. A parameter declared as `array` receives the mapped array without DTO construction. The mapper uses the standard `FactoryInterface::make()` construction route for DTOs, with no automatic validation when no validator is registered.

## Single-source mapper attributes

The seven specialized alternatives are `MapRequestPayload`, `MapQueryString`, `MapHeaders`, `MapCookies`, `MapRequestAttributes`, `MapServerParams`, and `MapUploadedFiles`. Each inherits `RequestMapper` and accepts `map: []` and `conflictPolicy: null`, not the full `MapRequest` option list. Custom subclasses may implement their own `transform()` and extraction logic.

`RequestMapper::transform(array $data): array` implements the shared mapping rules. `RequestMapper::$provider` is a caster provider assigned during DI mapping; `$map` is a publicly readable array with protected writes. The parent constructor takes `(array $map = [], ?RequestDataConflictPolicy $conflictPolicy = null)`.

For custom extraction, implement `RequestDataExtractorInterface::extract(ServerRequestInterface): array` and `MapperInterface::transform(array): array`. For a single extracted parameter, implement `ExtractorInterface`, optionally `ParameterNameAwareExtractorInterface` and `CastableInterface`. A mapper with an optional callback may implement `CallbackMapperInterface` and expose its readable `?Closure $afterMap` property.

## Diagnostics and compatibility

`RequestDataConflictException` exposes the conflicting key and source names. `RequestParameterSourceConflictException` identifies a DTO input key that conflicts with an explicitly source-bound constructor parameter. Missing caster names and invalid callback results cause resolution errors. See the [attribute reference](attribute-reference.md) for the exact declaration targets and constructor arguments of every request attribute.
