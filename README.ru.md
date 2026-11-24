# Componenta DI

[English](README.md) · [Документация](docs/ru/index.md)

DI-контейнер для PHP 8.4+ с поддержкой PSR-11, autowiring, определений зависимостей, внедрения через атрибуты, вызова callable и ленивых объектов.

**Статус:** DI 6 разрабатывается в ветке `refactor/di6-definitions-callbacks` и ещё не выпущена. Обычная установка через Composer загружает опубликованный релиз, а не эту ветку.

## Установка

```bash
composer require componenta/di
```

Для установки ветки DI 6 и полного примера см. [начало работы](docs/ru/guide/getting-started.md). Нативные callback-выражения в аргументах атрибутов требуют PHP 8.5; минимальная версия PHP пакета — 8.4.

## Документация

- [Начало работы](docs/ru/guide/getting-started.md) — установка, конфигурация, пример.
- [Определения](docs/ru/guide/definitions.md) — сервисы, фабрики, aliases и delegators.
- [API контейнера](docs/ru/guide/container.md) · [Ленивые объекты](docs/ru/guide/lazy-objects.md) · [Атрибуты и HTTP-запросы](docs/ru/guide/attributes.md).
- [Расширение контейнера](docs/ru/guide/extensions.md) · [Поведение и исключения](docs/ru/guide/runtime.md).
- [Миграция определений DI 6](docs/migrations/di6-definitions.ru.md) · [Миграция callbacks](docs/migrations/di6-callbacks.ru.md).

[Планы архитектуры и отчёты аудитов](docs/plans/README.ru.md) хранятся отдельно от пользовательского руководства.

Локальный запуск VitePress (Node.js 22+):

```bash
cd docs
npm install
npm run dev
```

Сборка сайта: `npm run build` в каталоге `docs/`.
