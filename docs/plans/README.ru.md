# Архитектурные контракты и планы

[English version](README.md)

Принятый объём рефакторинга реализован в `refactor/di6-definitions-callbacks`. DI 6 ещё не выпущена. Пакет сохраняет PHP `^8.4`; новый нативный синтаксис callback-выражений требует PHP 8.5.

| Область | Спецификация | Статус |
|---|---|---|
| Владение определениями и освобождение после мутаций | [Русский](definition-owner-lifecycle.ru.md) / [English](definition-owner-lifecycle.md) | Реализованы центральные регистрации, стратегии без карт, снимки, правила присоединения и retirement после полной инвалидации. [Миграция](../migrations/di6-definitions.ru.md). |
| Callbacks и union-типы | [Русский](php85-callbacks.ru.md) / [English](php85-callbacks.md) | Реализованы общий binder, Transform, callback SetUp, Init и простые unions. E1 ограничивает затронутые native-формы; request-to-DTO callbacks отложены. [Миграция](../migrations/di6-callbacks.ru.md). |
| Нативная ленивость | [Русский](propertyless-lazy-lifecycle.ru.md) / [English](propertyless-lazy-lifecycle.md) | D1/A реализовано: стандартный адаптер отклоняет неподдерживаемые классы до выделения. Контракты отложенных callbacks и пользовательских адаптеров сохранены. |
| Доступность атрибутов | [Русский](attribute-availability-performance.ru.md) / [English](attribute-availability-performance.md) | Сохраняется исправленный опрос. B2 требует обоснования затрат приложения и пересмотра контракта до G2/G3; реализация не выбрана. C не выбран. |

Спецификации содержат причины решений, контракты, ограничения совместимости и критерии приёмки. При изменении контракта обновляются обе языковые версии и соответствующие тесты.

Проверки выполняются командами `composer check`, `composer test-coverage` и `composer test-mutation`. [CI](../../.github/workflows/ci.yml) проверяет PHP 8.4/8.5 на Linux и Windows; логи и coverage хранятся в результатах workflow. Нативные ограничения PHP проверяются отдельно командой `composer test-platform`.
