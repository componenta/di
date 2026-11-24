# Архитектурные планы и отчёты

[English](README.md) · [Руководство пользователя](../ru/index.md)

Решения, альтернативы и аудиты **ещё не выпущенной** DI 6. Предложения не являются готовыми функциями или подтверждёнными дефектами.

## Реализованные контракты DI 6

- [Владение определениями и освобождение после мутаций](definition-owner-lifecycle.ru.md) ([EN](definition-owner-lifecycle.md)) — [миграция](../migrations/di6-definitions.ru.md).
- [Callbacks и autowiring union-типов](php85-callbacks.ru.md) ([EN](php85-callbacks.md)) — [миграция](../migrations/di6-callbacks.ru.md).
- [Примеры обработки запросов](request-mapping-examples.ru.md) ([EN](request-mapping-examples.md)) — необязательный `afterMap` и прежние API.
- [Ограничения нативных ленивых объектов](propertyless-lazy-lifecycle.ru.md) ([EN](propertyless-lazy-lifecycle.md)) — требования стандартного адаптера.

## Предложения, требующие отдельного решения

- [Завершение bootstrap](bootstrap-finalization-boundaries.ru.md) · [Владение внешними провайдерами](external-provider-ownership.ru.md).
- [Совместимость relative callable](relative-callable-context.ru.md) · [Низкоуровневые API и типы результата](standalone-api-surface.ru.md).
- [Нормализация конфигурации](configuration-normalization-contract.ru.md) · [Большие графы алиасов](large-alias-graphs.ru.md).
- [Ленивые объекты в Fibers](native-lazy-fiber.ru.md) · [Проверка доступности атрибутов](attribute-availability-performance.ru.md).

Некоторые проблемы из старых отчётов уже исправлены; проверяйте более поздние аудиты.

## История проверок

- [Реестр архитектурных отчётов](audit-architecture-inventory.ru.md) · [Перепроверка 20 ноября](audit-architecture-recheck-2026-11-20.ru.md)
- [Аудит DI 6 от 21 ноября](di6-audit-2026-11-21.ru.md) · [Финальная перепроверка релиза](di6-release-recheck-2026-11-21.ru.md)

Актуальный пользовательский API описан в [руководствах](../ru/index.md). Команды проверки: `composer check`, `composer test-coverage`, `composer test-mutation`.
