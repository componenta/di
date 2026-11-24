# Componenta DI documentation

[Русская версия](ru/index.md) · [Repository](https://github.com/componenta/di)

Componenta DI is a PSR-11 dependency injection container requiring PHP 8.5.13 or a later compatible PHP 8.x release.

## Guide

- [Getting started](guide/getting-started.md)
- [Dependency definitions](guide/definitions.md)
- [Container API](guide/container.md)
- [Attributes and dependency injection](guide/attributes.md)
- [Built-in attribute reference](guide/attribute-reference.md)
- [PSR-7 request mapping](guide/request-mapping.md)
- [Lazy objects and proxies](guide/lazy-objects.md)
- [Extending the container](guide/extensions.md)
- [Runtime behavior and exceptions](guide/runtime.md)

## Public API reference

- [Container and configuration](api/container.md)
- [Definition factories and descriptors](api/definitions.md)
- [Callable resolution and invocation](api/callables.md)
- [Lazy-object factories](api/lazy.md)
- [Parameter resolution](api/parameters.md)
- [Attribute composition](api/attribute-composition.md)
- [Attribute handlers](api/attribute-handlers.md)
- [Entry resolution and object construction](api/entry-resolvers.md)
- [Request extraction and mapping contracts](api/request.md)
- [Exceptions and diagnostics](api/exceptions.md)

Types in `Componenta\DI\Internal` are implementation details, not extension contracts.

## Migrating from version 5

- [Definitions and extension interfaces](migrations/di6-definitions.md)
- [Callbacks and union autowiring](migrations/di6-callbacks.md)

## Public API reference

[All public classes, interfaces, attributes, methods and exceptions](api/index.md)
