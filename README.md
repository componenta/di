# Componenta DI

[Русский](README.ru.md)

A [PSR-11](https://www.php-fig.org/psr/psr-11/) dependency injection container for PHP 8.4+ with autowiring, dependency definitions, attribute injection, callable execution, and native lazy objects.

**DI 6 is unreleased.** This branch (`refactor/di6-definitions-callbacks`) contains the development API.

## Installation

Published release:

```bash
composer require componenta/di
```

DI 6 development branch:

```bash
composer require 'componenta/di:dev-refactor/di6-definitions-callbacks'
```

Native callback expressions directly in PHP attributes require PHP 8.5; the package itself supports PHP 8.4+.

## Documentation

- [Getting started](docs/guide/getting-started.md) — installation, providers, and an example.
- [Definitions](docs/guide/definitions.md) · [Container API](docs/guide/container.md).
- [Lazy objects](docs/guide/lazy-objects.md) · [Attributes and HTTP mapping](docs/guide/attributes.md).
- [Extensions](docs/guide/extensions.md) · [Runtime behavior and exceptions](docs/guide/runtime.md).
- [DI 6 migration: definitions](docs/migrations/di6-definitions.md) · [Callbacks and unions](docs/migrations/di6-callbacks.md).

[Documentation index](docs/index.md) · [Issues](https://github.com/componenta/di/issues)
