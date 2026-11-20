# Контекст вызова для устаревших относительных callable

**Статус (2026-11-20): предложено; не реализовано.** Результат аудита **A20-P3** — подтверждённое ограничение совместимости. Нарушение явно обещанной поддержки или регрессия, появившаяся именно в DI 6, **не доказаны**. Архитектурный вариант в этом отчёте обусловлен реальной потребностью в паритете с устаревшими формами PHP. [English version](relative-callable-context.md).

## Перепроверка после сохранения отчёта

Последующая [перепроверка доказательств](audit-architecture-recheck-2026-11-20.ru.md) содержит новые исполнения на PHP 8.4.28 и 8.5.13 после сохранения отчётов и [машиночитаемый вывод](audit-architecture-recheck-2026-11-20.json). Исторические коммиты и указания на отсутствие повторного запуска при первоначальном сохранении остаются историческими; новый отчёт добавляет последующее доказательство. Архитектурный вариант этой проверкой не реализован.

## 1. Наблюдаемое различие

Для унаследованного метода PHP различает лексический класс объявления, фактический called class и receiver при вызове метода экземпляра. Обычная проверка параметров DI знает класс объявления отражённого параметра, но не передаёт все три части текущего вызова в `TypeHints::matchesCallable()`.

Различие существенно для некоторых устаревших контекстно зависимых string/array-значений, переданных параметру `callable`. В минимальных случаях ниже native PHP и вызов с уже разрешёнными аргументами выполняют callback, а обычный `Container::call()` отклоняет аргумент до входа в принимающий метод.

| Цель объявлена в `Owner` | Переданный аргумент | Native PHP | Обычный DI | Контроль `PreparedCallable` |
| --- | --- | --- | --- | --- |
| `[Child::class, 'accept']` | `'static::childPublic'` | `child-public` | `ResolutionException` до тела | `child-public` |
| `[new Child(), 'acceptInstance']` | `['self', 'childPublic']` | `child-public` | `ResolutionException` до тела | `child-public` |

Обе нативные формы вызывают `E_DEPRECATED` на использованных PHP **8.4.28** и **8.5.13**. Диагностика записывает предупреждения, не превращая их в исключения; иначе обработчик deprecation может скрыть различие принятия. Native и prepared проходят проверку параметра принимающего метода и вызывают `call_user_func()` в его теле. Обычный DI останавливается на собственной более ранней проверке параметра.

Речь о **значениях** callable, а не о новом синтаксисе выражений, создающем `Closure`. [Руководство миграции PHP 8.2](https://www.php.net/manual/en/migration82.deprecated.php) перечисляет относительные формы callable как устаревшие. First-class references PHP 8.5 в аргументах атрибутов, например `self::method(...)`, создают нативные замыкания и относятся к реализованному [плану callbacks](php85-callbacks.ru.md). Его платформенная проверка E1 — отдельный вопрос; выполнение E1 не добавляет контекст вызова старым string/array-формам.

## 2. Доказательства и их границы

Исходный поиск выполнен на [`983871d4fc75fb607a2a92186cf4f64428f290e2`](https://github.com/componenta/di/commit/983871d4fc75fb607a2a92186cf4f64428f290e2). Дифференциальная матрица записала **48 различий принятия из 1 551 сценария на каждой версии PHP**; различия совпали на обеих версиях. Каждый принимаемый native и отклоняемый обычным DI вариант использовал deprecated-форму. В широкой матрице часть union-сценариев проверяла только принятие: нельзя описывать все 1 551 случая как выполненные тела callback. Два минимальных случая ниже вызывают `call_user_func()` в принимающем теле и независимо перепроверены с пятью controls в аудите, завершившемся на [`e11d69619fd808da1935b100c752fef7cf8bbf69`](https://github.com/componenta/di/commit/e11d69619fd808da1935b100c752fef7cf8bbf69).

Соответствующие исходники `TypeHints`, `ParameterTarget`, `CallableExecutor` и `PreparedCallable` не изменились между тем аудитом и [`43c464187f80c95802fc36191350ae68b70a469e`](https://github.com/componenta/di/commit/43c464187f80c95802fc36191350ae68b70a469e). Ссылки ниже закрепляют последнюю версию. Сохранение плана не объявляет новое выполнение исторической матрицы или подтверждённый результат на каждой patch-версии PHP.

| Код на `43c4641` | Ответственность и следствие |
| --- | --- |
| [`TypeHints::matchesCallable()`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Resolver/TypeHints.php#L170-L183) | Принимает существующее `Closure` напрямую; остальные формы проверяет статическим замыканием, привязанным к пользовательскому классу объявления. Сигнатура не получает actual called class или receiver. |
| [`ParameterTarget`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Resolver/Target/ParameterTarget.php) | Хранит отражённые metadata объявления. `accepts()` передаёт класс объявления в `TypeHints`; переиспользуемые metadata не предназначены для receiver отдельного вызова. |
| [`CallableExecutor`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/CallableExecutor.php) | Готовит обычные аргументы через `ParametersResolver`. Планы именованных функций/методов могут кешироваться; замыкания подготавливаются на каждый вызов, чтобы не удерживать захваченные значения. |
| [`PreparedParameterPlan`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Internal/Resolver/Parameter/PreparedParameterPlan.php) | Хранит переиспользуемые цели и структуру разрешения, а не receiver конкретного вызова. |
| [`PreparedCallable`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/PreparedCallable.php) | Обозначает уже разрешённые аргументы. Не выполняет ещё один проход DI по параметрам и вызывает исходную цель нативным способом. |

Существующие документы сохраняют runtime-представления callable и нативную область замыканий; отдельного обещания полного паритета каждого deprecated relative value не найдено. Поэтому наблюдение не обозначается доказанной регрессией DI 6 или общей неисправностью обычных callable.

## 3. Самодостаточное публичное воспроизведение

Сохранить PHP-блок как `relative-callable-reproducer.php` вне checkout с development-зависимостями и выполнить:

```sh
php relative-callable-reproducer.php /absolute/path/to/di
```

Fixture использует [`tests/bootstrap.php`](../../tests/bootstrap.php) и [`ContainerBuilder`](../../tests/Support/ContainerBuilder.php) только для обычного создания контейнера. Три наблюдения — нативный вызов и публичный `Container::call()` с prepared-адаптером или без него. Исходная диагностика из семи случаев приведена ниже с аргументом пути checkout; файлы внешнего workspace не нужны.

```php
<?php
declare(strict_types=1);
$repository = realpath($argv[1] ?? getcwd());
if ($repository === false) {
    throw new LogicException('Pass the checked-out DI repository as the first argument.');
}
require $repository . '/tests/bootstrap.php';

class Audit20CallableOwner
{
    private static function lexicalPrivate(): string { return 'owner-private'; }
    public static function accept(callable $callback): string { return call_user_func($callback); }
    public function acceptInstance(callable $callback): string { return call_user_func($callback); }
}
class Audit20CallableChild extends Audit20CallableOwner
{
    public static function childPublic(): string { return 'child-public'; }
    protected static function childProtected(): string { return 'child-protected'; }
    private static function childPrivate(): string { return 'child-private'; }
}
function observation(Closure $operation): array
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = ['level' => $level, 'message' => $message];
        return true;
    });
    try { return ['value' => $operation(), 'warnings' => $warnings]; }
    catch (Throwable $error) { return ['error' => $error::class, 'message' => $error->getMessage(), 'warnings' => $warnings]; }
    finally { restore_error_handler(); }
}
$container = (new Componenta\DI\Tests\Support\ContainerBuilder())->build();
$child = new Audit20CallableChild();
$cases = [
    'static runtime class' => [[Audit20CallableChild::class, 'accept'], 'static::childPublic'],
    'instance runtime receiver' => [[$child, 'acceptInstance'], ['self', 'childPublic']],
    'lexical private control' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableOwner::class, 'lexicalPrivate']],
    'protected control' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableChild::class, 'childProtected']],
    'child private rejection control' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableChild::class, 'childPrivate']],
    'fully qualified workaround' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableChild::class, 'childPublic']],
    'closure workaround' => [[Audit20CallableChild::class, 'accept'], Audit20CallableChild::childPublic(...)],
];
$results = [];
foreach ($cases as $name => [$target, $value]) {
    $results[$name] = [
        'native' => observation(static fn() => $target($value)),
        'di' => observation(static fn() => $container->call($target, ['callback' => $value])),
        'prepared' => observation(static fn() => $container->call(new Componenta\DI\PreparedCallable($target), [$value])),
    ];
}
echo json_encode(['php' => PHP_VERSION, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
```

Записанные результаты двух затронутых случаев приведены в §1. Пять controls показывают, почему замена лексического scope дочерним классом не является корректным исправлением:

| Контрольный аргумент для `Child::accept` | Результат native / обычного DI / prepared |
| --- | --- |
| `[Owner::class, 'lexicalPrivate']` | Все возвращают `owner-private` без deprecation. Лексический private-доступ должен сохраниться. |
| `[Child::class, 'childProtected']` | Все возвращают `child-protected` без deprecation. |
| `[Child::class, 'childPrivate']` | Native/prepared бросают `TypeError`, обычный DI — параметрический `ResolutionException`. Все отклоняют доступ. |
| `[Child::class, 'childPublic']` | Все возвращают `child-public` без deprecation. |
| `Child::childPublic(...)` | Все возвращают `child-public` без deprecation. |

В fixture имена классов имеют префикс для предотвращения случайных конфликтов; `Owner` и `Child` в таблицах — их сокращения. `PreparedCallable` используется как диагностический контроль с **полностью разрешённым нативным списком аргументов**. Он намеренно обходит внедрение аргументов, атрибуты и преобразования DI. Это не универсальная замена вызова, которому ещё нужны эти операции; нативный отказ остаётся возможным.

## 4. Почему локальная замена scope опасна

| Часть вызова | Унаследованный static-пример | Унаследованный instance-пример | Владелец |
| --- | --- | --- | --- |
| Лексическая область объявления | `Owner` | `Owner` | Reflection metadata объявления. |
| Фактический called class | `Child` | `Child` | Текущий вызов. |
| Receiver | Отсутствует | Текущий дочерний объект | Только текущий вызов. |

Привязка проверяющего замыкания к `Child` вместо `Owner` не сохраняет controls лексической private/protected видимости. Сохранение дочернего объекта в `ParameterTarget`, prepared plan или общем кеше рискует удерживать его прикладной/request-граф и смешивать вызовы с одними metadata метода. Привязка статического замыкания также не задаёт автоматически независимые lexical и late-static классы.

Полный паритет требует дизайна передачи фактического контекста без изменения лексического доступа. Он затронет обычные вызовы, унаследованные static/instance методы, конструкторы, method/callback-варианты `SetUp`, callback binding, variadic-параметры и прямые single-parameter API. У точки входа, получающей только `ReflectionParameter`, нет receiver для неявного восстановления: доступный контекст и fallback должны быть определены явно.

Нельзя создавать другой receiver только для проверки значения, перепривязывать пользовательский callback, глобально ослаблять проверку callable или синтезировать исполняемый код для имитации вызова PHP. Эти варианты добавляют побочные эффекты или меняют сохраняемую семантику видимости/времени жизни. Выигрыша безопасности или производительности от такого расширения не доказано.

## 5. Варианты и предлагаемая рекомендация

| Вариант | Выигрыш | Стоимость, совместимость и ограничения |
| --- | --- | --- |
| **A. Документировать текущую границу поддержки** | Явно описывает ограничение deprecated-контекста и показывает переносимые входы, сохраняя поведение. | EN/RU документация и примеры; нет нового состояния на горячем пути или публичных сигнатур. Не запрещать уже работающие relative forms и не сужать `Init`/общие runtime callable API. |
| **B. Контекст конкретного вызова для выбранного legacy-паритета** | Может поддержать затронутые формы, которым нужны actual called class/receiver. | Native-oracle prototype, новая передача контекста, проверка public/internal API, lifetime и concurrency. Объём и версии поддержки выбираются явно; паритет не предполагается заранее. |

**Предлагаемая рекомендация:** выбрать A, если нет конкретного потребителя B. Рекомендовать полный function/class callable либо first-class `Closure`, созданную в области, где нужный метод доступен. Для публичного helper в fixture оба варианта по-прежнему используют обычное разрешение DI:

```php
$container->call([Audit20CallableChild::class, 'accept'], [
    'callback' => [Audit20CallableChild::class, 'childPublic'],
]);
$container->call([Audit20CallableChild::class, 'accept'], [
    'callback' => Audit20CallableChild::childPublic(...),
]);
```

Выражения используют контейнер и класс с префиксом из fixture. Замыкание private helper следует создавать в допустимой нативной области; полное имя вне этой области не предоставляет private-доступ.

A уточняет совместимость, но не исправляет два relative-примера. Сама публикация предложения не выбирает новый нормативный API-контракт. Если B потребуется, до добавления контекста явно указать поддерживаемые legacy-формы и принимающие маршруты, сохранив рекомендованные современные входы из A.

## 6. Предлагаемый план работы

### Срез 1 — Граница и потребность потребителя

Обновить публичную EN/RU документацию callable и [руководство миграции callbacks](../migrations/di6-callbacks.ru.md): два примера, нативные deprecation и поддержанные альтернативы. Добавить ссылку на этот отчёт из плана callbacks; сохранить существующий статус PHP 8.5/E1. Записать, есть ли реальный потребитель точного legacy-паритета и какие точки входа ему нужны.

**Готово, когда:** перечисление callable-представлений нельзя принять за универсальное обещание late-static-context parity; не запрещён ни один работающий вход; различие обычного DI и заранее разрешённых аргументов явно описано. Для A runtime-изменение не требуется.

### Срез 2 — Native-oracle prototype, только если выбран B

Сравнить фактический вызов PHP с предлагаемой проверкой, используя все три компонента контекста. Начать с двух воспроизведений и пяти controls. Расширить набор унаследованными целями, parent/self/static-формами, static/instance вызовами, замыканиями, разной видимостью методов, callable unions и variadic. Разделять принятие типа и выполнение тела callback; записывать deprecation отдельно от исключений.

**Готово, когда:** поддерживаемый PHP подход доказывает выбранный паритет без изменения лексической видимости, создания другого receiver, повторения callbacks или генерации исполняемого кода. Если static-маршрут не выполняет контракт, явно сузить предлагаемую поддержку либо сохранить A; не сливать частичную реализацию под обещанием полного паритета.

### Срез 3 — Определить и передать контекст конкретного вызова

До реализации определить владельцев: `CallableExecutor` для обычных целей, `ParametersResolver` для выполнения разрешения аргументов, создание объектов и `SetUpRunner` для lifecycle-целей, `AttributeCallbackInvoker` для binder. Использовать существующий цикл разрешения. Оставить metadata объявления в `ParameterTarget` и общих планах; actual called class/receiver передавать только в структуре, принадлежащей вызову.

Задать прямое single-parameter поведение без переданного receiver, default/optional-контекст, ответственность за исключения и совместимость расширений. Изменение публичных сигнатур `accepts()` или разрешения оценивать как изменение public API. Не передавать приватные служебные ключи в пользовательских массивах параметров. Сохранить снимки фактической цели, чтобы проверка и выполнение не могли выбрать разные callable-маршруты.

**Готово, когда:** каждый выбранный маршрут имеет явный источник контекста и владельца очистки; вложенные/Fiber вызовы не перезаписывают контекст друг друга; общие metadata не удерживают объект вызова.

### Срез 4 — Доказать совместимость, lifetime и стоимость

Реализовать выбранный подход с публичными регрессиями и нативными controls. Сохранить смысловой первый аргумент binder, обычные приоритеты разрешения, короткое замыкание union-проверок, границы deprecation/ошибок и контракт prepared-адаптера. Проверять неудачные и приостановленные вызовы наряду с успешными. Обновить обе языковые версии и миграцию по фактически доказанному поведению.

Измерить обычный прогретый/холодный вызов до и после на закреплённых версиях PHP и отдельно включённый legacy-путь. Записать дополнительные autoload, reflection и выделения. Сохранить применимые обычные проверки статического анализа, стиля, suites поддерживаемых платформ и прицельные mutation-проверки изменённого поведения.

**Готово, когда:** матрица приёмки проходит для выбранных форм/маршрутов, обычные пути не регрессируют, а стоимость оправдана указанной потребностью совместимости. Единственный доказанный выигрыш B, который следует заявлять, — выбранная legacy-совместимость.

## 7. Матрица приёмки

Матрица задаёт будущую работу. Историческая диагностика из семи случаев — доказательство, а не новый закоммиченный regression suite.

| Граница | Необходимое наблюдаемое доказательство |
| --- | --- |
| Два затронутых примера | A документирует отличие; B совпадает с выбранными нативными результатами принятия и тела callback на каждой квалифицируемой версии PHP. |
| Controls видимости | Private `Owner` и protected дочернего класса принимаются; private дочернего класса отклоняется. Полные public-значения и first-class closures продолжают работать. |
| Обычные формы | Функции, static/object-методы, invokable, magic dispatch и замыкания сохраняют поведение и identity callable. Нет общего сужения API. |
| Источники контекста | Унаследованные static/instance вызовы, конструкторы, обе setup-ветви, суффиксы binder, variadic и прямые single-parameter API следуют явно описанной политике контекста. |
| Union и autoload | Непосредственно подходящая не-callable ветвь union принимает значение до callable inspection. Нет лишней автозагрузки, исполнения цели или создания receiver. |
| Диагностика | Deprecation записываются, не скрывая принятие; ошибки входа/значения сохраняют своего DI-владельца, явные исключения тела цели — прежнюю границу. Не обещать одинаковые классы исключений native и DI. |
| Паритет кеша | Холодные/прогретые и кешированные/немемоизированные планы дают одинаковое поддержанное поведение без общего receiver-состояния. |
| Повторный вход и Fiber | Перекрывающиеся вызовы с разными дочерними классами/receiver сохраняют собственный контекст при suspend, исключениях и восстановлении. |
| Время жизни | `WeakReference` доказывает освобождение receiver, captures и графов аргументов после исчезновения ссылок вызывающего кода, exception traces и живых владельцев вызова. Общие планы receiver не удерживают. |
| Существующий синтаксис PHP 8.5 | Нативные expression/scope/lifetime controls и отдельно квалифицированное E1-поведение сохраняются; работа legacy string/array не перепривязывает нативные closures. |
| Производительность | Сравнить неизменённые обычные вызовы и выбранные legacy-случаи на одинаковых настройках; сообщать измеренную стоимость без необоснованного обещания ускорения. |

План не зависит от динамического внешнего владения или общего рефакторинга времени жизни объектов. Решение состоит в том, оправдывает ли конкретная потребность устаревшей совместимости передачу дополнительного контекста вызова. До такого решения и доказательства исходной точкой остаются обычная реализация и её текущие границы scope/lifetime.

## 8. Отдельная граница политики: aliases с разделителем в строках методов экземпляра

### Статус и отличие от предыдущего ограничения callable-значений

**Добавлено в проходе 24, 2026-11-20: предложено; изменение маршрутизации экземпляров не выбрано.** PHP 8.4.28 и 8.5.13 позволяют `class_alias()` создавать имя класса с `::` внутри. Это создаёт второй вопрос совместимости для удобной DI-формы `Class::instanceMethod`. Здесь рассматривается **строка цели, которую разрешает `CallableResolver`**; разделы 1–7 относятся к значению, передаваемому параметру `callable` другого объявления. Решение одного вопроса не выбирает политику другого.

Уже допустимая native-строка **статического** метода — более узкий независимо исправимый случай: PHP считает разделителем метода последнее `::`. Независимое исправление опубликовано в [`45eb1ab`](https://github.com/componenta/di/commit/45eb1ab1400aba19a0662e0cd8ee4559ea0e85d8). Executor сохраняет metadata параметров такого метода: разбирает последний разделитель и адаптирует только вход reflection, когда сам alias содержит `::`. В этом исправлении фактическая строка вызова и существующий ключ кеша остаются прежними. [`CallableAliasedClassSeparatorContractTest`](../../tests/Contract/CallableAliasedClassSeparatorContractTest.php) проверяет native static controls, внедрение зависимости, прогретые вызовы, magic-аргументы и приоритет точного service ID. Исправление не меняет `CallableResolver` и не выбирает новую политику для сокращённой строки метода экземпляра.

Второй независимый дефект исправлен в [`e85289b`](https://github.com/componenta/di/commit/e85289b21e6bcc894a6fa54cd9b327fdca3d6840): склейка владельца и метода через `::` позволяла разным массивным callable после прогрева использовать один parameter plan и менять переданные значения аргументов. Теперь часть класса имеет префикс длины; [`CallableQualifiedCacheBoundaryContractTest`](../../tests/Contract/CallableQualifiedCacheBoundaryContractTest.php) проверяет оба порядка прогрева и class/object owners относительно native-результатов. Правка меняет только приватное кодирование ключа и сохраняет маршрут вызова. Политика приоритета instance-строк ею не выбирается.

Строка с классом и нестатическим методом сама по себе не является native callable. DI может получить владельца из контейнера и создать пару объект/метод. Текущий удобный разбор использует **первый** разделитель. Это поддерживает уже работающий legacy-маршрут `Child::Parent::run`, превращающийся в native-пару `[$child, 'Parent::run']`. На проверенных версиях она допустима с `E_DEPRECATED`. Произвольная `[$object, 'missing::tail']` без допустимого квалификатора отвергается PHP; это не пример поддерживаемого magic-вызова.

Выбор разделителя становится неоднозначным, когда реальный alias `Child::Parent` указывает на посторонний класс с нестатическим `run()`. В контейнере могут быть зарегистрированы оба владельца. Выбор последнего разделителя только потому, что его префикс — загруженный класс, перенаправляет работающий вызов с родительского метода child-сервиса на посторонний alias-сервис. Полная строка в этом примере по-прежнему недопустима как native callable: посторонний метод нестатический. Приоритет допустимого native static callable эту неоднозначность не решает.

### Воспроизводимый публичный пример

Сохраните блок как `instance-alias-routing.php` вне checkout с dev-зависимостями и выполните `php instance-alias-routing.php /absolute/path/to/di`. Пример использует публичные методы контейнера и записывает native deprecations, не превращая их в исключения. В успешных наблюдениях тела методов действительно исполняются. Alias создаётся механизмом PHP, а не таблицей aliases сервисов контейнера.

```php
<?php
declare(strict_types=1);
require $argv[1] . '/tests/bootstrap.php';

class Audit24RoutingBase
{
    public function run(string $named = 'base'): array { return ['base', $named]; }
}
class Audit24RoutingChild extends Audit24RoutingBase {}
class Audit24RoutingOther
{
    public function run(string $named = 'other'): array { return ['other', $named]; }
}
class Audit24RoutingTarget
{
    public function run(string $named = 'target'): array { return ['target', $named]; }
}
function routingObservation(Closure $operation): array
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = ['level' => $level, 'message' => $message];
        return true;
    });
    try { return ['value' => $operation(), 'warnings' => $warnings]; }
    catch (Throwable $error) { return ['error' => $error::class, 'message' => $error->getMessage(), 'warnings' => $warnings]; }
    finally { restore_error_handler(); }
}

$container = (new Componenta\DI\Tests\Support\ContainerBuilder())->build();
$child = new Audit24RoutingChild();
$container->set(Audit24RoutingChild::class, $child);
$qualifiedMethod = Audit24RoutingBase::class . '::run';
$target = Audit24RoutingChild::class . '::' . $qualifiedMethod;
$arguments = ['named' => 'supplied'];
$before = [
    'native_object_pair' => routingObservation(static fn() => call_user_func_array([$child, $qualifiedMethod], $arguments)),
    'native_string' => routingObservation(static fn() => call_user_func_array($target, $arguments)),
    'di_string' => routingObservation(static fn() => $container->call($target, $arguments)),
];

$longerAlias = Audit24RoutingChild::class . '::' . Audit24RoutingBase::class;
class_alias(Audit24RoutingOther::class, $longerAlias);
$container->set($longerAlias, new Audit24RoutingOther());
$arrayControl = (new Componenta\DI\Tests\Support\ContainerBuilder())->build();
$arrayControl->set($longerAlias, new Audit24RoutingOther());
$after = [
    'native_object_pair' => routingObservation(static fn() => call_user_func_array([$child, $qualifiedMethod], $arguments)),
    'native_string' => routingObservation(static fn() => call_user_func_array($target, $arguments)),
    'di_string' => routingObservation(static fn() => $container->call($target, $arguments)),
    'explicit_alias_pair' => routingObservation(static fn() => $arrayControl->call([$longerAlias, 'run'], $arguments)),
];

$plainAlias = Audit24RoutingTarget::class . '::Alias';
class_alias(Audit24RoutingTarget::class, $plainAlias);
$instance = new Audit24RoutingTarget();
$unambiguousControl = (new Componenta\DI\Tests\Support\ContainerBuilder())->build();
$unambiguousControl->set($plainAlias, $instance);
$unambiguous = [
    'native_object_pair' => routingObservation(static fn() => call_user_func_array([$instance, 'run'], $arguments)),
    'native_string' => routingObservation(static fn() => call_user_func_array($plainAlias . '::run', $arguments)),
    'di_alias_pair' => routingObservation(static fn() => $unambiguousControl->call([$plainAlias, 'run'], $arguments)),
    'di_alias_string' => routingObservation(static fn() => $unambiguousControl->call($plainAlias . '::run', $arguments)),
];
echo json_encode(['php' => PHP_VERSION, 'before_alias' => $before, 'after_alias' => $after, 'unambiguous_alias' => $unambiguous], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
```

Свежие исполнения на обеих указанных версиях дают следующую матрицу. Явные array controls используют отдельные контейнеры: исследуется выбор владельца instance-строки, а не независимая корректность общего кодирования cache keys.

| Маршрут | До создания длинного alias | После создания длинного alias |
| --- | --- | --- |
| Native `[$child, 'Base::run']` | `['base', 'supplied']` с deprecation | Те же результат и deprecation. |
| Native полная строка `Child::Base::run` | `TypeError`: строка не является native callable | `TypeError`: метод alias нестатический. |
| DI полная строка | `['base', 'supplied']` с deprecation | Тот же существующий маршрут через child-сервис. |
| DI явная `[$longerAlias, 'run']` | На этой стадии не зарегистрирована | `['other', 'supplied']` через явно выбранного alias-владельца. |

В отдельном примере `Target::Alias` native-пара с объектом и DI-пара с alias обе возвращают `['target', 'supplied']`. Его прямая native-строка вызывает `TypeError`; DI-строка с alias сейчас вызывает `InvalidCallableException`. Последнее — подтверждённое ограничение удобной строковой формы. Оно не оправдывает незаметную смену владельца в неоднозначном qualified-маршруте выше. Кандидат, всегда предпочитающий загруженный префикс последнего разделителя, воспроизвёл именно такую смену владельца и был отклонён до публикации.

### Варианты совместимости и предлагаемая рекомендация

| Вариант | Выигрыш | Цена совместимости и необходимое доказательство |
| --- | --- | --- |
| **A. Сохранить трактовку строк экземпляров; описать явные пары** | Маршрут, число callback-вызовов и политика инвалидации не меняются. `Container::call([$alias, 'run'], $arguments)` уже однозначно задаёт зарегистрированного владельца. | Строка alias с разделителем остаётся неподдерживаемой в однозначном примере. Документация должна различать native static строку и удобную DI-форму экземпляра. |
| **B. Поддержать выбранные дополнительные alias-строки, сохраняя существующие qualified-маршруты** | Для определённого подмножества необычных aliases можно сблизить строковую и массивную формы. | Выбор владельца надо доказать до запуска его фабрики. Наличие загруженного класса недостаточно; исходники репозитория не описывают все объекты, которые могут возвращаться по service ID. Нужен контракт коллизий и недоступных классов. |
| **C. Выбрать новый явный приоритет строковых маршрутов** | Даёт одно определённое правило для расширенной формы. | Приоритет alias может заменить существующий receiver; приоритет legacy-маршрута сохраняет неоднозначность. Оба варианта требуют inventory потребителей, миграции и решения о совместимости релиза. Это не косметическое исправление парсинга. |

**Рекомендация:** сохранить A до появления реального потребителя, которому требуется строковой паритет для instance aliases с разделителем. Использовать явную `[$alias, 'method']` либо пару с receiver, если объект уже принадлежит приложению. Сохранить приоритет точного service ID полной строки и обработку уже допустимого native callable. B/C — условная архитектурная работа, не часть исправления metadata статического вызова.

Пробный `get($firstOwner)` ради проверки qualified-метода не является чистой metadata-операцией. Он может создать постороннего владельца, запустить фабрику/delegator/provider, завершиться ошибкой, приостановиться или изменить регистрации до перехода к alias. Создание дополнительного receiver без конструктора тоже не доказывает маршрут фактического сервиса и исключается. Нельзя повторять фабрику владельца или целевой callback для сравнения трактовок. Рассматриваемый выигрыш — совместимость выбранной формы; улучшение безопасности или производительности не продемонстрировано.

### Последовательность реализации при выборе B или C

1. **Собрать потребителей и выбрать приоритет.** Различать точные opaque service IDs, native static строки, обычные instance строки, qualified ancestor-методы и пары с alias-владельцем. Неизвестное downstream-использование оставить неизвестным. Учесть сервисы, продукт которых не соответствует классовому написанию ID.
2. **Определить маршрут до побочных эффектов.** Описать коллизии, visibility, лексический/called scope, регистронезависимые aliases, доступность при autoload и владельца ошибок. Указать, каких сведений достаточно без создания любого из кандидатов. Если существующее поведение так сохранить нельзя, требовать явную пару вместо пробных lookup.
3. **Согласовать выполнение и отслеживание зависимостей.** `CallableResolver::resolveClassMethod()` выбирает владельца, а [`DelegatorRegistry::dependencyIds()`](../../src/Internal/DelegatorRegistry.php) сейчас консервативно записывает владельца до первого разделителя. Изменение только resolver может оставить устаревший закешированный delegator при `set($longerAlias, $replacement)`. Оба пути должны учитывать одного фактического владельца и зависимость от полного строкового ID. Не менять static native-маршруты без контейнерного экземпляра-владельца.
4. **Сохранить идентичность вызова и lifetime.** Удержать выбранные receiver/method, native visibility/deprecation и владение аргументами отдельного вызова. Нормализация metadata не должна перепривязывать callbacks, сохранять receiver в shared plan, повторять фабрики или смешивать разные пары owner/method в одном cache key. Проверить cold/warm, замену владельца, вложенные/Fiber-переходы там, где владелец разрешается отложенно.
5. **Добавить потребительские регрессии и миграционные примеры.** Начать с приведённого witness и явных array controls. Доказать одно получение владельца и одно исполнение цели для выбранного маршрута, восстановление после ошибок без повторных эффектов и инвалидацию delegator после замены владельца. Намеренно изменённое legacy-поведение описать в EN/RU release notes.
6. **Квалифицировать выбранную реализацию.** Выполнить поддерживаемые PHP 8.4/8.5 suites, отдельные native controls, максимальный статический анализ, style и целевые mutation checks. Прежде чем заявлять улучшение производительности, измерить дополнительные lookup/autoload/allocation относительно неизменённого обычного пути.

Критерии готовности: сохранение qualified-маршрута, если документированная миграция явно не меняет его; поддержка выбранного alias-маршрута; сохранение приоритета opaque IDs и native static поведения; отсутствие пробного создания владельцев и повторов callbacks; наблюдение замены фактического владельца закешированным delegator-вызовом. До выбора потребительской политики и её доказательства `CallableResolver` и ownership зависимостей delegator остаются прежними.
