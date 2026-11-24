# Componenta DI

DI-контейнер для PHP 8.4+ с поддержкой PSR-11.

**Руководства относятся к ещё не выпущенной ветке DI 6** — `refactor/di6-definitions-callbacks`, а не к опубликованному релизу. Нативные callback-выражения в PHP-атрибутах требуют PHP 8.5; пакет в целом поддерживает PHP 8.4+.

## Начало работы

В разделе [«Начало работы»](./guide/getting-started.md) — установка, провайдеры конфигурации и полный пример.

## Руководство

- [Определения зависимостей](./guide/definitions.md) — сервисы, фабрики, invokables, aliases и delegators.
- [API контейнера](./guide/container.md) — разрешение зависимостей, вызовы, изменения конфигурации.
- [Ленивые объекты и прокси](./guide/lazy-objects.md).
- [Атрибуты и обработка HTTP-запросов](./guide/attributes.md).
- [Расширение контейнера](./guide/extensions.md).
- [Поведение и исключения](./guide/runtime.md).

## Переход на DI 6

- [Изменения определений и расширений](/ru/migrations/di6-definitions)
- [Callbacks, union-автосвязывание и HTTP-mapping](/ru/migrations/di6-callbacks)

[Архитектурные планы и отчёты](https://github.com/componenta/di/tree/refactor/di6-definitions-callbacks/docs/plans) — материалы разработки, не дублирующие руководство пользователя.

[English version](../index.md) · [Репозиторий](https://github.com/componenta/di)
