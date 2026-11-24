# Componenta DI

A PSR-11 dependency injection container for PHP 8.4+.

**These guides describe the unreleased DI 6 development branch** `refactor/di6-definitions-callbacks`, not the published release. Native callback expressions inside PHP attributes require PHP 8.5; the package requirement remains PHP 8.4+.

## Start here

[Getting started](./guide/getting-started.md) covers installation, provider composition, and a working example.

## User guide

- [Dependency definitions](./guide/definitions.md) — services, factories, invokables, aliases, and delegators.
- [Container API](./guide/container.md) — resolution, invocation, runtime changes.
- [Lazy objects and proxies](./guide/lazy-objects.md).
- [Attributes and HTTP request mapping](./guide/attributes.md).
- [Extension points](./guide/extensions.md).
- [Runtime behavior and exceptions](./guide/runtime.md).

## Upgrading to DI 6

- [Definition and extension changes](./migrations/di6-definitions.md)
- [Callbacks, union autowiring, and request mapping](./migrations/di6-callbacks.md)

[Architecture plans and audit reports](https://github.com/componenta/di/tree/refactor/di6-definitions-callbacks/docs/plans) are engineering records, not a second user guide.

[Русская версия](./ru/index.md) · [Repository](https://github.com/componenta/di)
