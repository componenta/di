# Componenta DI

[Русский](README.ru.md) · [Documentation](docs/index.md)

A PSR-11 dependency injection container for PHP 8.4+ with autowiring, dependency definitions, attribute-based injection, callable execution, and lazy objects.

**Status:** DI 6 is under development on `refactor/di6-definitions-callbacks` and has not been released. The regular Composer install command selects a published version, not this branch.

## Installation

```bash
composer require componenta/di
```

For the DI 6 development branch, its install command, and a working example, see [Getting started](docs/guide/getting-started.md). Native callback expressions in attribute arguments require PHP 8.5; the package itself supports PHP 8.4+.

## Documentation

- [Getting started](docs/guide/getting-started.md) — installation, provider composition, example.
- [Definitions](docs/guide/definitions.md) — services, factories, aliases, and delegators.
- [Container API](docs/guide/container.md) · [Lazy objects](docs/guide/lazy-objects.md) · [Attributes and request mapping](docs/guide/attributes.md).
- [Extension points](docs/guide/extensions.md) · [Runtime and exceptions](docs/guide/runtime.md).
- [DI 6 definition migration](docs/migrations/di6-definitions.md) · [Callback migration](docs/migrations/di6-callbacks.md).

[Architecture plans and audits](docs/plans/README.md) are engineering records, not user-facing API documentation.

Run the VitePress documentation site with Node.js 22+:

```bash
cd docs
npm install
npm run dev
```

Build the site with `npm run build` inside `docs/`.
