# Request-source and mapping contracts

The request extension interfaces belong to `Componenta\DI\Resolver\Parameter\Request`. Built-in attributes and mappers belong to `Componenta\DI\Attribute`. All operate on PSR-7 `ServerRequestInterface` supplied for the current invocation.

## Extraction and mapping interfaces

| Interface | Public method or property | Purpose |
| --- | --- | --- |
| `ExtractorInterface` | `extract(ServerRequestInterface $request): mixed` | Supply an individual value from the request. Extends `ParameterSourceAttributeInterface`. |
| `ParameterNameAwareExtractorInterface` | `extractForParameter(ServerRequestInterface $request, string $parameterName): mixed` | Allow omitted source names to use the declaring parameter's name; extends `ExtractorInterface`. |
| `RequestDataExtractorInterface` | `extract(ServerRequestInterface $request): array` | Extract a complete data array for mapping; extends `ParameterSourceAttributeInterface`. |
| `MapperInterface` | `transform(array $data): array` | Transform a raw request data array. |
| `CastableInterface` | Readable `?string $cast` | Allow selecting a named caster for one extracted value. |
| `CallbackMapperInterface` | Readable `?Closure $afterMap` | Optional DI-aware callback after standard mapping; extends `MapperInterface`. |

A custom mapper registered as an attribute implements **both** `RequestDataExtractorInterface` and `MapperInterface`; a custom individual extractor implements `ExtractorInterface`. Its registration uses `AttributeDefinition` and a parameter attribute handler; the standard `RequestAttributeHandler` can handle compatible protocols.

## RequestMapper

`RequestMapper` is the abstract base for all built-in `Map*` attributes. It implements `MapperInterface` and `CasterProviderAwareInterface`:

```php
public function __construct(
    array $map = [],
    ?RequestDataConflictPolicy $conflictPolicy = null,
);
public function transform(array $data): array;
```

The publicly readable `$map` property is writable from the class or subclasses; `$provider` is a public caster-provider property and defaults to `NullCasterProvider` until assigned. Protected configuration includes `$cast`, `$defaults`, `$sortMap`, `$exclude`, and `$conflictPolicy`.

`transform()` applies renaming, casting, defaults, sorting and exclusions in that order. The protected `mergeRequestData()` combines named source arrays and implements the chosen conflict policy. `RequestDataConflictPolicy` has the string-backed cases `Reject = 'reject'`, `FirstWins = 'first_wins'`.

`ExtractsRequestData` is a reusable trait for mapper subclasses that want optional request attributes and uploaded-file values alongside their primary source. Its protected selectors `$attributes` and `$files` accept named keys or the sole wildcard `['*']`; its protected `extractSharedSources()` and `extractSharedData()` return these selected values.

## Built-in individual extractors

Every entry below declares `extract(ServerRequestInterface $request)`; the return types and source behavior are described in the [attribute reference](../guide/attribute-reference.md).

| Attribute class | Additional public behavior |
| --- | --- |
| `CurrentRequest`, `CurrentUri` | Return the request or its URI; no constructor arguments. |
| `Header`, `Cookie`, `ServerParam` | Constructor `($name, $default = DefaultValue::None, $cast = null)`; source-specific `extract()`. |
| `QueryParam`, `RequestAttribute` | Omitted name defaults to the target parameter; also implement `extractForParameter()`. |
| `PayloadParam` | Accepts a `ConfigPath` to reach nested values; implements `extractForParameter()`. |
| `UploadedFile` | Constructor `(string $name)`; `extract(): UploadedFileInterface\|array\|null`. |

## Mapping classes and enums

| Type | Public surface |
| --- | --- |
| `MapRequest` | Constructor with `sources`, `map`, `exclude`, `conflictPolicy`, `defaults`, `cast`, `sortMap`, `attributes`, `files`, `afterMap`; `extract(ServerRequestInterface): array`; readable `$sources`, `$attributes`, `$files`, `$afterMap`; `ALL = '*'`. |
| `MapRequestPayload`, `MapQueryString`, `MapHeaders`, `MapCookies`, `MapRequestAttributes`, `MapServerParams`, `MapUploadedFiles` | Inherit `RequestMapper::__construct()` and `transform()`; implement `extract(ServerRequestInterface): array` for their specific source. |
| `RequestDataSource` | Cases `Payload`, `Query`, `Headers`, `Cookies`, `Attributes`, `Server`, `Files`. |
| `RequestDataConflictPolicy` | Cases `Reject`, `FirstWins`. |

All built-in mapping classes target parameters. An array-typed target receives a transformed array; a class-typed target is constructed with the standard object-creation path. Class-target raw input is validated if a validation provider exists. A conflicting HTTP field cannot override a constructor parameter with an explicit DI source, including a source recognized after earlier processing.

See [Request mapping guide](../guide/request-mapping.md), [All attributes](../guide/attribute-reference.md), and [RequestAttributeHandler](attribute-handlers.md).
