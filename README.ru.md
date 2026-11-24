# Componenta DI

[English](README.md)

DI-контейнер с поддержкой [PSR-11](https://www.php-fig.org/psr/psr-11/) для PHP 8.4+: autowiring, определения сервисов, внедрение через атрибуты, вызов callable и нативные ленивые объекты.

**DI 6 ещё не выпущена.** Ветка `refactor/di6-definitions-callbacks` содержит API в разработке.

## Установка

Опубликованный релиз:

```bash
composer require componenta/di
```

Ветка разработки DI 6:

```bash
composer require 'componenta/di:dev-refactor/di6-definitions-callbacks'
```

Нативные callback-выражения непосредственно в атрибутах требуют PHP 8.5; минимальная версия PHP для пакета — 8.4.

## Документация

- [Начало работы](docs/ru/guide/getting-started.md) — установка, провайдеры и пример.
- [Определения](docs/ru/guide/definitions.md) · [API контейнера](docs/ru/guide/container.md).
- [Ленивые объекты](docs/ru/guide/lazy-objects.md) · [Атрибуты и HTTP-запросы](docs/ru/guide/attributes.md).
- [Расширения](docs/ru/guide/extensions.md) · [Поведение и исключения](docs/ru/guide/runtime.md).
- [Миграция DI 6: определения](docs/ru/migrations/di6-definitions.md) · [Callbacks и union-типы](docs/ru/migrations/di6-callbacks.md).

[Оглавление](docs/ru/index.md) · [Сообщить об ошибке](https://github.com/componenta/di/issues)
