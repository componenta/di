# Attributes and request mapping

## Built-in attributes

Built-in object and parameter attributes include:

```text
#[Config] #[Env] #[EntryId] #[Inject] #[Make]
#[Lazy] #[Proxy] #[NoConstructor] #[Init] #[SetUp] #[Cast] #[Transform]
#[CurrentRequest] #[CurrentUri]
#[Header] #[Cookie] #[QueryParam] #[PayloadParam]
#[RequestAttribute] #[ServerParam] #[UploadedFile]
#[MapRequest] #[MapQueryString] #[MapRequestPayload]
#[MapHeaders] #[MapCookies] #[MapRequestAttributes]
#[MapServerParams] #[MapUploadedFiles]
```

`#[CurrentRequest]` and `#[CurrentUri]` are explicit invocation-only sources. A bare PSR-7 request or URI type does not imply current-request semantics, and invocation-only values cannot be stored through constructor injection.

## Request data and conflict policy

Request mapping reads explicitly selected PSR-7 sources, detects source conflicts, applies configured casting and validation, and creates DTOs through the same `FactoryInterface::make()` pipeline.

Mapped HTTP data is rejected when it would supply a parameter whose declared attribute is recognized as an explicit source, such as `#[EntryId]`. If metadata or dependency preparation discovers that source late, DI checks again before a pending resolver, attribute handler, `Transform` callback, or `Cast` caster receives the value. The check also runs between `Transform` callback dependency parameters.

The checks cover the complete provided parameter bag, including values for sibling parameters, and run again after final parameter and constructor preparation, before a constructor or `SetUp` callable receives arguments. Every read of `ObjectCreationContext::parameters` checks the current creation attempt before exposing the bag; handlers that do not read it leave deferred parameter suppliers untouched.

Before returning a mapped DTO, DI rechecks the constructor and `SetUp` sources recorded for that result after releasing inputs owned by the mapper.

A callback or extension that has already started may have performed side effects: these are not rolled back or replayed, and an incompatible late change still prevents successful completion of the DTO or callable. Provenance stays internal and is not added to custom resolver or handler contexts.

## Callbacks after request mapping

`MapRequest(afterMap: ...)` optionally transforms the complete mapped array with DI suffix dependencies before DTO creation. Raw validation still runs first; the result must be an array and retains final source checks. `afterMap: null` preserves the original path. Existing source-specific attributes, custom mapper subclasses, declarative options, standalone `transform()`, array plus `Transform`, and application handler adapters are supported. Custom request adapters can resolve the configured `RequestAttributeHandler` by its class ID. See the [existing and callback alternatives](https://github.com/componenta/di/blob/2b4f1ae44a29a3bc7c47d74c52e6990af9160b11/docs/plans/request-mapping-examples.md) ([Русский](https://github.com/componenta/di/blob/2b4f1ae44a29a3bc7c47d74c52e6990af9160b11/docs/plans/request-mapping-examples.ru.md)) for complete PHP 8.4/8.5 examples and the custom mapper protocol.
