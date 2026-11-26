# Componenta DI

[Английская версия](README.md)

Контейнер внедрения зависимостей для PHP 8.5.13+, соответствующий стандарту [PSR-11](https://www.php-fig.org/psr/psr-11/).

Использует Componenta Config 4, Reflection 3 и Validation 3.1.

Поддерживает автоматическое разрешение зависимостей, явные определения служб и фабрик, псевдонимы, обработчики результатов, атрибуты PHP, вызов функций и методов с внедрением зависимостей, обработку запросов PSR-7 и отложенное создание объектов.

## Установка

```bash
composer require componenta/di
```

## Документация

- [Начало работы](docs/ru/guide/getting-started.md) — настройка и создание контейнера.
- [Определения зависимостей](docs/ru/guide/definitions.md) — службы, фабрики, псевдонимы, обработчики результатов.
- [Интерфейс контейнера](docs/ru/guide/container.md) — поиск, создание объектов, вызов методов и изменение привязок.
- [Атрибуты и HTTP-запросы](docs/ru/guide/attributes.md) · [Справочник атрибутов](docs/ru/guide/attribute-reference.md) · [Отложенное создание объектов](docs/ru/guide/lazy-objects.md).
- [Расширение контейнера](docs/ru/guide/extensions.md) · [Поведение и исключения](docs/ru/guide/runtime.md).
- [Миграция определений](docs/ru/migrations/di6-definitions.md) · [Миграция функций обратного вызова и объединений типов](docs/ru/migrations/di6-callbacks.md).

[Полная документация и справочник интерфейсов](docs/ru/index.md) · [Сообщить об ошибке](https://github.com/componenta/di/issues)
