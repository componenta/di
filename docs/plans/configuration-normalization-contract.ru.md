# Канонизация конфигурации: выходные типы и состояние после ошибки

[English version](configuration-normalization-contract.md)

## Перепроверка после сохранения отчёта

Последующая [перепроверка доказательств](audit-architecture-recheck-2026-11-20.ru.md) содержит новые исполнения на PHP 8.4.28 и 8.5.13 после сохранения отчётов и [машиночитаемый вывод](audit-architecture-recheck-2026-11-20.json). Исторические коммиты и указания на отсутствие повторного запуска при первоначальном сохранении остаются историческими; новый отчёт добавляет последующее доказательство. Архитектурный вариант этой проверкой не реализован.

**Статус на 20 ноября 2026 года:** условный архитектурный план; runtime-редизайн не выбран. Сохраняется совместимый API: `assertShape()` канонизирует по ссылке, `normalize()` возвращает типизированный `DependencyShape`. Ложная narrowing-аннотация уже исправлена. Владение caller-owned section slots при value normalization также исправлено позже и не является открытым дефектом этого плана. Точка исходников: [`43c4641`](https://github.com/componenta/di/tree/43c464187f80c95802fc36191350ae68b70a469e).

## Проблема и уже выполненные исправления

[`DependencyConfiguration`](../../src/Configuration/DependencyConfiguration.php) проверяет форму секций и канонизирует invokables, aliases и delegators. Прямой API `assertShape(array &$dependencies): void` изменяет переданный массив на месте. Он может завершить раннюю секцию и затем выбросить исключение на поздней. Представление всей операции как чистого assertion либо обещание атомарной публикации не соответствует этому поведению.

[План третьего аудита](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round3.ru.md#6-решения-по-архитектуре-и-план-действий) разделял два вопроса:

- Ложная аннотация `@phpstan-assert DependencyShape` вызывала некорректное статическое сужение допустимых literal inputs. Она удалена в [`cc8ee82`](https://github.com/componenta/di/commit/cc8ee82b65d3b066e3d02af148d832f848d2bfdc); документированный типизированный результат предоставляет `normalize()`.
- Более точный выходной тип при передаче по ссылке требует корректно типизированных промежуточных результатов и явной политики состояния после ошибки. Простая замена на `@param-out` либо объявление промежуточного массива конечной формой такой проверки не дали. Этот более широкий редизайн оставлен открытым.

Позднейшее исправление [`c4ca540`](https://github.com/componenta/di/commit/c4ca54028a6341efe14cc2405cc31eee081de23e) отделило top-level reference slots секций перед value normalization. [`DependencyNormalizationOwnershipContractTest`](../../tests/Contract/DependencyNormalizationOwnershipContractTest.php) уже проверяет caller sections при успехе и ошибке, повторное использование definitions и маршруты, зафиксированные до изменения входных references callback-расширением. Это завершённое исправление нельзя выдавать за открытую причину редизайна API.

## Текущая граница поведения

| API | Текущая ответственность | Чего вызов не доказывает |
|---|---|---|
| `assertShape(&$dependencies)` | Проверяет допустимые ключи и типы секций, затем по порядку канонизирует и валидирует секции в массиве caller. [`DependencyConfigurationContractTest`](../../tests/Contract/DependencyConfigurationContractTest.php) покрывает прямую успешную канонизацию и malformed inputs. | Атомарный rollback после поздней ошибки; статически объявленный конечный выходной `DependencyShape`; полную проверку factory ABI или допустимости класса. |
| `normalize($dependencies, $defaultAliases)` | Владеет входными section slots, канонизирует локальный рабочий массив, объединяет/проверяет default aliases и возвращает типизированный результат. | Глубокое клонирование объектов приложения, отмену пользовательских эффектов validation или допустимость каждого factory descriptor. |
| Допуск definitions при construction/registration | Проверяет factory ABI и допустимость класса выбранными стратегиями. | Эти обязанности нельзя молча переносить в shape normalization. См. [миграцию definitions DI6](../migrations/di6-definitions.ru.md). |

Минимальный сценарий прямого API — ранний keyed invokable и поздний недопустимый service ID. Invokable и его alias могут быть канонизированы до ошибки services. Пример следует текущему порядку исходников и не обещает новой транзакционности:

```php
<?php

require getcwd() . '/vendor/autoload.php';

use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\InvalidConfigurationException;

$dependencies = [
    'invokables' => ['product' => stdClass::class],
    'services' => ['' => new stdClass()],
];

try {
    DependencyConfiguration::assertShape($dependencies);
} catch (InvalidConfigurationException $error) {
    // The caller can observe this state after the exception.
    var_export($dependencies['invokables']); // [0 => 'stdClass']
    var_export($dependencies['aliases']);   // ['product' => 'stdClass']
}
```

Вызов `normalize()` не публикует эти переписанные section slots в массив caller. Обычные объекты и вложенные значения приложения сохраняют требуемую identity. Одно копирование внешнего PHP-массива не доказывает отделение всех вложенных reference cells; более сильное обещание владения требует тестов именно тех cells, в которые реализация будет писать.

## Варианты и рекомендация

| Вариант | Выигрыш | Цена и совместимость |
|---|---|---|
| A. Сохранить in-place validation и использовать типизированный `normalize()` | Существующие callers сохраняют поведение; для типизированного результата уже есть API. | Прямой `assertShape()` не получает точную аннотацию формы после вызова. |
| B. Типизированные результаты секций с определённым частичным состоянием после ошибки | Static analysis может доказать форму секций и результата без ложных аннотаций. | Нужно сохранить выбранный наблюдаемый порядок записи и состояние после ошибки, точно определить helpers и владение. |
| C. Типизированные результаты с публикацией только после успеха | API может обещать неизменность caller-owned configuration slots при ошибке до публикации. | Меняет failure-поведение прямого метода; references, стоимость allocation и callbacks во время validation усложняют гарантию. Требуется решение совместимости и миграции. |

**Рекомендация:** сохранять A, пока downstream-интеграции не требуется точный by-reference output type либо явно атомарный API редактирования конфигурации. При таком требовании сначала подготовить prototype типизированных результатов секций и осознанно выбрать B или C. Отличие контракта прямого in-place API не доказывает поломку существующего `normalize()`.

Атомарность относится к публикации конфигурации. Ни один вариант не может в общем случае отменить произвольный autoloader или callback приложения, исполненный при validation. Сохранение ссылки на объект также не обещает отмену мутаций самого объекта.

## План при выборе более сильного typing или atomic publication

1. **Зафиксировать потребителей и нужную гарантию.** Различить typed return value, сведения о типе после by-reference вызова и неизменность входа после ошибки. Первую потребность уже закрывает `normalize()`.
2. **Определить failure-state и reference ownership.** Указать записываемые top-level и nested routing slots, наблюдаемость записи внешними reference aliases и остаточное состояние после каждой validation-ошибки. Сохранить object/callable identity, если отдельное требование владения не выбирает иное.
3. **Спроектировать типизированные функции секций.** Возвращать точные формы invokables, aliases, delegators и extension lists. Сохранить integer priorities resolver без reindex, правила string IDs и numeric-key rejection, списки и replace flags. Не объявлять частично обработанный массив готовым `DependencyShape`.
4. **Собирать и публиковать по выбранной политике.** B сохраняет документированную последовательность partial state. C полностью проверяет выбранный private snapshot до присваивания; отделения внешних slots недостаточно, если helpers пишут через nested references. Default aliases и cycle detection остаются явными этапами.
5. **Сохранить admission и provenance.** Сохранить существующую канонизацию: invokable descriptors преобразуются в class strings, сохраняемые factory/ClassDefinition descriptors удерживают identity. ABI и допустимость класса проверяются при допуске; source/owner identity сохраняется; shape checks не должны дополнительно создавать объект или разрешать deferred owner.
6. **Доказать поведение и статические типы.** Добавить публичные input/output и failure-state тесты, а также PHPStan consumer fixture с допустимыми literal invokables и scalar delegators. Для сознательно изменённого обещания зафиксировать baseline witness и новый результат. Не скрывать ошибки ignore-аннотацией или заведомо ложным типом.
7. **Измерять только выбранный редизайн.** Сравнить представительные большие конфигурации и небольшие обычные входы с одинаковыми runtime/dependencies. Записать время normalization, метод измерения peak memory, доступные allocation/copy данные, успех и ошибку. Бюджеты приёмки задавать нагрузкой потребителя, а не произвольным процентом.
8. **Описать миграцию.** При изменении failure-поведения исходного in-place метода объяснить наблюдаемую разницу. Не поддерживать две новые реализации normalization с неочевидными различиями без ясного владельца.

## Критерии приёмки

| Группа | Обязательные случаи |
|---|---|
| Успешная канонизация | String/invokable descriptors, scalar/list delegators, пустые секции, flags, стабильные priorities без reindex, default и configured aliases. |
| Отказ | Numeric/empty IDs там, где запрещены, неверные типы секций, поздняя invalid section после ранней канонизации, alias cycles и конфликты invokable aliases. |
| Публикация при ошибке | Проверить массив caller после каждой ошибки: состояние соответствует выбранной partial/atomic-политике. Проверить внешние references на переписываемые секции и nested routing cells. |
| Владение | Два контейнера используют одни definitions; ранний build сохраняет захваченные маршруты; services, callable receivers и nested application objects сохраняют требуемую identity. Существующие ownership-тесты проходят. |
| Static analysis | Допустимые literal inputs принимаются; конечная выходная форма доказана реализацией; malformed input не получает ложное сужение. |
| Эффекты и ошибки | Normalization metadata не вызывает factories/constructors повторно; владелец native/DI-диагностики определён. Rollback произвольных пользовательских эффектов не обещается. |
| Производительность | Одинаковые workload/runtime baseline, сохранённые время и память; улучшение синтетического копирования не выдаётся за latency HTTP-приложения. |

План завершён, когда требуемая гарантия API, политика совместимости и доказательство реализации согласованы. Возврат вводящей в заблуждение type-аннотации его не закрывает.
