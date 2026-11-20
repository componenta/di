# Архитектурные контракты и планы

[English version](README.md)

Принятый объём рефакторинга реализован в `refactor/di6-definitions-callbacks`. DI 6 ещё не выпущена. Пакет сохраняет PHP `^8.4`; новый нативный синтаксис callback-выражений требует PHP 8.5.

Архитектурные отчёты сведены и сохранены по состоянию на 20 ноября. [Реестр источников и планов](audit-architecture-inventory.ru.md) учитывает исторические предложения, завершённые исправления, заменённые решения и сознательно сохранённые контракты. Открытые отчёты ниже — предложения: они не выбирают изменение runtime и не объявляют каждое ограничение регрессией DI.

Последующая [свежая перепроверка](audit-architecture-recheck-2026-11-20.ru.md) сохраняет исполненные архитектурные примеры, новые проверки поведения, результаты и границы доказательств. [Наблюдения в JSON](audit-architecture-recheck-2026-11-20.json) находятся рядом с отчётами.

## Открытые решения и условные предложения

Восемь отчётов охватывают двенадцать открытых/условных тем: bootstrap finalization и standalone API содержат по два самостоятельных решения, relative callables — три. Проход 23 добавил политику статического типа результата; проход 24 — приоритет владельца instance-shorthand; проход 25 — время уточнения зависимостей delegator, зарегистрированного до загрузки владельца. Прежние перепроверки сохраняют собственные source, executions и исторические counts.

| Область | Отчёт | Решение и ожидаемый выигрыш |
|---|---|---|
| Завершение bootstrap | [Русский](bootstrap-finalization-boundaries.ru.md) / [English](bootstrap-finalization-boundaries.md) | Две отдельные границы: скрытая готовность loader и изменение metadata при освобождении proof-ссылок. До реализации выбрать preload/добровольное уведомление и failure policy завершающей очистки; сохранить identity и отсутствие replay. |
| Динамическое владение внешних providers | [Русский](external-provider-ownership.ru.md) / [English](external-provider-ownership.md) | Выбрать явный контракт стабильности либо добровольный ownership signal. Адресная инвалидация требует наблюдаемых изменений; повтор непрозрачных `has()` отозван как неполное исправление. |
| Контекст legacy relative callable | [Русский](relative-callable-context.ru.md) / [English](relative-callable-context.md) | Документировать границу совместимости; переносить lexical/called/receiver context на уровне вызова только при необходимости deprecated-форм для потребителя. Это отдельный вопрос от native closure expressions PHP 8.5. |
| Приоритет владельца строкового instance-callable | [Русский](relative-callable-context.ru.md) / [English](relative-callable-context.md) | Проход 24: class alias с `::` может конфликтовать с уже допустимым qualified ancestor method. До изменения строковой формы определить общий контракт владельца, обнаружения и инвалидации зависимостей; явная method-array форма доступна. Отдельный дефект parameter plan корректной native-строки исправлен в `45eb1ab`. |
| Зависимости поздно загруженных делегаторов | [Русский](relative-callable-context.ru.md#9-отдельная-граница-политики-регистрация-delegator-до-загрузки-владельца) / [English](relative-callable-context.md#9-separate-policy-boundary-delegators-registered-before-their-owner-loads) | Проход 25: первоначальная консервативная зависимость от владельца может сохраниться после native static разрешения и вызвать лишний повтор. Сохранять текущую политику до требования потребителя; точные зависимости требуют сведений о маршруте и атомарной публикации кеша/индекса. Дефект уже загруженного native alias исправлен в `f4869bc`. |
| Низкоуровневые standalone API | [Русский](standalone-api-surface.ru.md) / [English](standalone-api-surface.md) | Последующие тесты закрыли часть исходных пробелов. Для оставшихся support-, preparation- и callback-timing обещаний нужны consumer inventory и явная политика API. |
| Статический тип результата для class-name ID | [Русский](standalone-api-surface.ru.md#дополнительная-граница-политики-условный-тип-результата-для-class-name-id) / [English](standalone-api-surface.md#additional-policy-scope-conditional-return-types-for-class-name-ids) | Проход 23, baseline `748357f`: generic inference `get()`/`make()` сильнее множества допустимых продуктов под class-name ID. После consumer review выбрать точные unrestricted types, проверяемую typed operation либо явное допущение приложения. Runtime-дефект не установлен; новая политика API не выбрана. |
| Нормализация конфигурации | [Русский](configuration-normalization-contract.ru.md) / [English](configuration-normalization-contract.md) | Сохранить `assertShape()` и типизированный `normalize()`. Более сильные by-reference typing или atomic failure publication условны; прежние ошибки аннотации и владения value normalization исправлены. |
| Большие холодные alias-графы | [Русский](large-alias-graphs.ru.md) / [English](large-alias-graphs.md) | Подтвердить реальную startup-нагрузку до замены повторных обходов графовым алгоритмом. Сохранить порядок диагностики, cycles и mutation semantics; ускорение warm lookup не предполагается. |
| Общие native lazy-объекты и Fibers | [Русский](native-lazy-fiber.ru.md) / [English](native-lazy-fiber.md) | Сохранить нативную границу конкуренции. Инициализация/синхронизация приложения требует отдельного контракта; scheduler или locking subsystem не выбран. |
| Стоимость доступности атрибутов | [Русский](attribute-availability-performance.ru.md) / [English](attribute-availability-performance.md) | Сохраняется исправленный опрос. Текущие DI6-измерения дают 24/192 missing-name calls на затронутое создание. B2 требует обоснования затрат приложения и нового synchronization-контракта; C не выбран. |

Каждый отчёт содержит проблему, границы доказательств, варианты, рекомендацию, выигрыш, цену и совместимость, последовательность реализации и критерии приёмки. Контрпример на неподдерживаемом входе или ограничение PHP сами по себе не являются локальным багом DI.

## Реализованные контракты и существующие способы использования

| Область | Спецификация | Статус |
|---|---|---|
| Владение определениями и освобождение после мутаций | [Русский](definition-owner-lifecycle.ru.md) / [English](definition-owner-lifecycle.md) | Реализованы центральные регистрации, стратегии без карт, снимки, правила присоединения и retirement после полной инвалидации. [Миграция](../migrations/di6-definitions.ru.md). |
| Callbacks и union-типы | [Русский](php85-callbacks.ru.md) / [English](php85-callbacks.md) | Реализованы общий binder, Transform, callback SetUp, Init и простые unions. E1 проходит на стабильном PHP 8.5.13 — минимальной проверенной patch-версии затронутых native-форм; `afterMap` добавляет необязательный вариант mapping. [Миграция](../migrations/di6-callbacks.ru.md). |
| Удобство request mapping | [Существующий и callback-варианты](request-mapping-examples.ru.md) / [English](request-mapping-examples.md) | Необязательный `afterMap` реализован по §11 плана callbacks. Существующие mapping API сохраняются; сравниваются варианты DTO, переиспользование, custom source и ограничения. |
| Нативная ленивость | [Русский](propertyless-lazy-lifecycle.ru.md) / [English](propertyless-lazy-lifecycle.md) | D1/A реализовано: стандартный адаптер отклоняет неподдерживаемые классы до выделения. Контракты отложенных callbacks и пользовательских адаптеров сохранены. |

Спецификации содержат причины решений, контракты, ограничения совместимости и критерии приёмки. При изменении контракта обновляются обе языковые версии и соответствующие тесты.

Проверки выполняются командами `composer check`, `composer test-coverage` и `composer test-mutation`. [CI](../../.github/workflows/ci.yml) выполняет полные проверки PHP 8.4/8.5 на Linux и Windows и проверяет потребителей с минимальными production-зависимостями на PHP 8.4/8.5/8.6. Проверка потребителя не подтверждает весь граф инструментов разработки на PHP 8.6. Логи и coverage хранятся в результатах workflow. Нативная регрессия E1 выполняется отдельно на точном PHP 8.5.13 командой, лежащей в основе `composer test-platform`.
