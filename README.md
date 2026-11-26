# Componenta DI

[Русская версия](README.ru.md)

A [PSR-11](https://www.php-fig.org/psr/psr-11/) dependency injection container for PHP 8.x starting at 8.5.13.

Supports autowiring, explicit service and factory definitions, aliases, decorators, PHP attributes, callable invocation, PSR-7 request mapping, and native lazy objects.

## Installation

```bash
composer require componenta/di
```

## Documentation

- [Getting started](docs/guide/getting-started.md) — configure and build a container.
- [Dependency definitions](docs/guide/definitions.md) — services, factories, aliases, and decorators.
- [Container API](docs/guide/container.md) — lookup, object creation, invocation, and mutations.
- [Attributes and request mapping](docs/guide/attributes.md) · [Attribute reference](docs/guide/attribute-reference.md) · [Lazy objects](docs/guide/lazy-objects.md).
- [Extension points](docs/guide/extensions.md) · [Runtime behavior and exceptions](docs/guide/runtime.md).
- [Migrating definitions](docs/migrations/di6-definitions.md) · [Migrating callbacks and unions](docs/migrations/di6-callbacks.md).

[Full documentation and API reference](docs/index.md) · [Issues](https://github.com/componenta/di/issues)
