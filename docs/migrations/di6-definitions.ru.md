# Миграция расширений определений на DI 6

**Статус: разработка следующей major-версии, релиза ещё нет.** Руководство описывает API определений и мутаций, реализованный в ветке разработки DI 6. Эти изменения не входят в выпущенный пакет 5.0.6. Соответствующая спецификация — [Владение определениями и освобождение после мутаций](../plans/definition-owner-lifecycle.ru.md). [English version](di6-definitions.md).

## Кому нужно менять код

Приложения, использующие `ContainerFactory`, публичные определения и `Container::set()`, сохраняют эти точки входа. Миграция ниже нужна коду, который напрямую создаёт factory/invokable-резолверы, реализует `DefinitionAwareResolverInterface`, объединяет вложенные резолверы или перехватывает ошибки мутаций.

| Прежнее использование | Использование в DI 6 |
| --- | --- |
| `DefinitionAwareResolverInterface` с картой ID → определение | `DefinitionResolverInterface`, который проверяет и исполняет переданные дескрипторы, не владея регистрациями |
| `new FactoryResolver($factories, $container, $proxyFactory)` | `new FactoryResolver($container, $proxyFactory)`; публичные определения регистрируются в composite либо передаются через прямой протокол проверки и исполнения |
| `new InvokableResolver($invokables)` | `new InvokableResolver()`; объекты `InvokableDefinition` регистрируются в composite либо проверяются и исполняются напрямую |
| Методы `setDefinition()`, `can()` и `resolve()` у этих двух резолверов | Методы `setDefinition()`, `can()` и `resolve()` у composite либо `validateDefinition()` и `resolveDefinition()` у стратегии |
| Вложенные composites, наблюдающие последующие регистрации ребёнка | Независимые снимки текущих привязок и упорядоченных провайдеров ребёнка |
| Прямое изменение или повторное использование composite, уже присоединённого к контейнеру | `Container::set()` для этого корня; отдельная композиция для другого контейнера |
| Любой `InvalidConfigurationException` считается отклонённой регистрацией | Сначала обрабатывается `MutationRetirementException`: мутация уже зафиксирована |
| `DependencyConfiguration::normalize()` используется для проверки ABI фабрики или допустимости класса | Проверка при допуске во время `ContainerFactory::create()`, standalone `setDefinition()` либо прямой проверки стратегией |

Интерфейс изменяемого листового резолвера удалён. Адаптера совместимости, сохраняющего скрытую карту регистраций в листовом резолвере, нет. Обычные fallback-резолверы по-прежнему реализуют [`EntryResolverInterface`](../../src/Resolver/Entry/EntryResolverInterface.php); reflection fallback сохраняет эту роль.

## Владение регистрациями и поведение приложения

[`CompositeResolver`](../../src/Resolver/Entry/CompositeResolver.php) владеет таблицей явных привязок. Каждая привязка фиксирует ID, выбранную стратегию и проверенный дескриптор. Начальные определения и последующие регистрации попадают в одну таблицу. Стратегии фабрик и invokable-классов не хранят конфигурацию в картах по ID.

Это исправляет прежнее удержание фабрики после смены стратегии определения. Кроме того, контейнер получает единую точку публикации перед освобождением старой конфигурации и значений кеша. Поэтому shared- и fresh-разрешение согласованы с зафиксированным определением, даже если освобождение старого значения выбрасывает исключение.

Публичные `Definition::factory()`, `Definition::invokable()`, `ClassDefinition` и `Definition::reference()` сохраняют свои роли. `ReferenceDefinition` остаётся дескриптором аргумента/ссылки; встроенные стратегии не поддерживают его как самостоятельное определение entry. Явное invokable-создание по-прежнему вызывает конструктор без аргументов. `ClassDefinition` использует настроенные аргументы, переопределения времени исполнения и упорядоченные вызовы методов; DI-атрибуты целевого класса не исполняются.

Вызов `set()` с обычным значением по-прежнему заменяет shared-значение, сохраняя определение для `make()`. Передача нового определения заменяет это базовое определение и инвалидирует затронутые shared-результаты. Пример использует только API создания контейнера и мутаций для приложения:

```php
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

final readonly class Message
{
    public function __construct(public string $text) {}
}

$container = (new ContainerFactory())->create(
    new Config([], new Environment([])),
    new DependencyDefinitions([
        'factories' => [
            'message' => Definition::factory(
                static fn(): Message => new Message('factory'),
            ),
        ],
    ]),
)->container;
assert($container instanceof Container);

$shared = new Message('shared override');
$container->set('message', $shared);
$fresh = $container->make('message');
assert($container->get('message') === $shared);
assert($fresh instanceof Message && $fresh->text === 'factory');

$container->set(
    'message',
    ClassDefinition::create(Message::class)->constructor(['text' => 'configured']),
);
$current = $container->get('message');
$fresh = $container->make('message');
assert($current instanceof Message && $current->text === 'configured');
assert($fresh instanceof Message && $fresh->text === 'configured');
assert($current !== $fresh);
```

Сохраняйте существующие правила защищённых ID и aliases. При регистрации определения через alias метод `set()` фиксирует каноническую цель до проверки. Если проверка выполняет вложенную мутацию или приостанавливается, внешняя операция сохраняет эту зафиксированную цель, а для инвалидации использует текущий граф зависимостей. Успешные вложенные мутации не откатываются при отклонении внешней проверки.

## Нормализация bootstrap-конфигурации и момент проверки

[`DependencyConfiguration`](../../src/Configuration/DependencyConfiguration.php) проверяет форму конфигурации и идентификаторы фабрик, нормализует aliases/invokables и проверяет остальные секции. Значения секции фабрик сохраняются, включая публичные объекты определений. Нормализация больше не разворачивает `FactoryDefinition` в callable и не проверяет ABI фабрики или допустимость `ClassDefinition`.

```php
use Componenta\DI\Configuration\DependencyConfiguration;

$definition = Definition::factory(static fn(): Message => new Message('normalized'));
$normalized = DependencyConfiguration::normalize([
    'factories' => ['message' => $definition],
]);
assert($normalized['factories']['message'] === $definition);
```

Потребители нормализованного массива должны принимать сохранённый дескриптор, а не предполагать, что каждое значение фабрики уже является callable. Успешный вызов `normalize()` сам по себе не доказывает корректность фабрик или целевых классов. `ContainerFactory::create()` допускает нормализованные определения в корень до присоединения; `CompositeResolver::setDefinition()` и `Container::set()` используют тот же протокол стратегий. Неподдерживаемые виды определений отклоняются при допуске.

Существующие необёрнутые спецификации фабрик в `DependencyDefinitions` остаются поддерживаемыми. Bootstrap преобразует их во внутренний дескриптор допуска. Этот внутренний тип не является API регистрации для приложения. Известные прямые callable, не являющиеся строками, проверяются на совместимость с ABI фабрики `(ContainerValue, array)` при допуске. Строки и ещё не разрешённые спецификации методов сервисов сохраняют отложенный поиск владельца и проверку во время исполнения. Допуск не создаёт отложенных владельцев фабрик и не исполняет их фабрики. Последующий bootstrap расширений может запрашивать сервисы согласно своему существующему поведению.

Если интеграция раньше использовала `normalize()` как полную семантическую проверку, перенесите эту проверку в создание контейнера или в метод `validateDefinition()` выбранной стратегии. Не разрешайте отложенного владельца только ради проверки нормализованного массива.

## Прямое использование встроенных стратегий

[`FactoryResolver`](../../src/Resolver/Entry/FactoryResolver.php) теперь принимает только контейнер и proxy factory. В контейнере должен быть обычный bootstrap-сервис `ContainerValue`. [`InvokableResolver`](../../src/Resolver/Entry/InvokableResolver.php) создаётся без аргументов конструктора. Оба реализуют `DefinitionResolverInterface`, а не `EntryResolverInterface`.

Прямой потребитель владеет проверенным дескриптором и передаёт его той же стратегии с тем же ID. Считайте результат проверки непрозрачной конфигурацией: его вид определения может отличаться от входного. Не передавайте внутренний подготовленный дескриптор повторно на вход новой регистрации. Следующий фрагмент продолжает пример приложения выше:

```php
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;

$factoryStrategy = new FactoryResolver($container, new ProxyFactory());
$validated = $factoryStrategy->validateDefinition(
    'direct-message',
    Definition::factory(static fn(): Message => new Message('direct')),
);
$direct = $factoryStrategy->resolveDefinition('direct-message', $validated);
assert($direct instanceof Message && $direct->text === 'direct');

$invokableStrategy = new InvokableResolver();
$validatedInvokable = $invokableStrategy->validateDefinition(
    'empty-object',
    Definition::invokable(stdClass::class),
);
assert($invokableStrategy->resolveDefinition('empty-object', $validatedInvokable) instanceof stdClass);
```

Прямой вызов не имеет общего кеша entry. Используйте standalone composite, когда ID должны регистрироваться и разрешаться через упорядоченную композицию провайдеров. Используйте контейнер, когда регистрация также должна инвалидировать shared-значения и результаты делегаторов.

## Пользовательские стратегии определений

Реализуйте три метода [`DefinitionResolverInterface`](../../src/Resolver/Entry/DefinitionResolverInterface.php):

1. `supportsDefinition()` классифицирует входной вид без загрузки сервисов, исполнения фабрик и вызовов callback-функций приложения.
2. `validateDefinition()` возвращает стабильную конфигурацию для последующего исполнения. Метод не публикует регистрацию и не хранит карту ID → определение. Часто удобен отдельный неизменяемый нормализованный дескриптор.
3. `resolveDefinition()` исполняет именно тот проверенный дескриптор, который передан данному вызову. Храните повторно используемые метаданные отдельно от значений вызова; не удерживайте старые дескрипторы или разрешённые экземпляры сервисов в кеше метаданных.

Выбирается первая поддерживающая стратегия. Если она отклоняет проверку, следующая подходящая стратегия не пробуется. Явные привязки имеют приоритет над fallback-резолверами. Провайдер может реализовать оба контракта, стратегии и fallback, но каждая роль сохраняет собственную ответственность. Composite удаляет приватные DI-метаданные параметров перед исполнением пользовательской стратегии или пользовательского fallback.

```php
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;

final readonly class TextDefinition implements DefinitionInterface
{
    public function __construct(public string $text) {}
}

final readonly class ValidatedText implements DefinitionInterface
{
    public function __construct(public string $text) {}
}

final class TextStrategy implements DefinitionResolverInterface
{
    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof TextDefinition;
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        if (!$definition instanceof TextDefinition || trim($definition->text) === '') {
            throw new InvalidConfigurationException('Text definitions require non-empty text.');
        }

        return new ValidatedText(trim($definition->text));
    }

    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed
    {
        if (!$validated instanceof ValidatedText) {
            throw new InvalidConfigurationException('Expected a validated text descriptor.');
        }

        return $validated->text;
    }
}
```

Преобразуйте пользовательские определения из изменяемых контейнеров конфигурации в стабильные снимки. Если объект в дескрипторе принадлежит приложению и изменяем, стратегия отвечает за определение стабильности своей конфигурации; DI не выполняет глубокое клонирование пользовательских графов. Уже начавшийся вызов может завершиться с захваченным дескриптором после замены. Существующие ревизии кеша не позволяют его старому результату повторно заполнить кеш, инвалидированный этой мутацией.

## Standalone-композиции, снимки и присоединение

Standalone composite сохраняет `setDefinition(string $id, DefinitionInterface $definition): void`. Метод выполняет проверку, публикацию и освобождение для собственной таблицы. Кешами контейнера он не владеет. Продолжение примера пользовательской стратегии:

```php
use Componenta\DI\Resolver\Entry\CompositeResolver;

$strategy = new TextStrategy();
$child = new CompositeResolver($strategy);
$child->setDefinition('caption', new TextDefinition(' original '));
$snapshot = new CompositeResolver($child);

$child->setDefinition('caption', new TextDefinition('child update'));
assert($snapshot->resolve('caption') === 'original');

$snapshot->setDefinition('caption', new TextDefinition('snapshot update'));
assert($child->resolve('caption') === 'child update');
assert($snapshot->resolve('caption') === 'snapshot update');
```

Правила снимков опираются на идентичность объектов:

- Дочерние composites разворачиваются при создании. Родитель импортирует текущие записи привязок и провайдеры; сам дочерний composite не удерживается, последующие регистрации ребёнка не отслеживаются.
- Общий объект стратегии присутствует в порядке стратегий определений один раз, в своей первой позиции. Каждое вхождение fallback сохраняется в порядке провайдеров, даже если один объект fallback встречается в разных детях.
- Импорт одной и той же записи привязки через несколько снимков идемпотентен. Разные записи для одного ID конфликтуют, даже если содержат тот же объект определения или эквивалентные значения.
- Передача одного объекта провайдера дважды напрямую в один конструктор отклоняется.
- Импортированные дескрипторы и стратегии разделяются по ссылке, без клонирования. Для каждой композиции создавайте провайдеры с подходящими зависимостями от контейнера.

После присоединения composite к `Container` этот контейнер становится единственным публичным владельцем мутаций корня. Прямой `setDefinition()`, присоединение ко второму контейнеру и импорт присоединённого корня как ребёнка выбрасывают `InvalidConfigurationException`. Присоединение действует до конца жизни composite. Для другого контейнера создавайте отдельную композицию, а для изменений во время работы используйте `$container->set($id, $definition)`.

Внутренние методы `prepareDefinition()`, `publishDefinition()` и `attach()` не являются API мутаций для расширений. Подготовленные записи связаны с создавшим их composite. Публикация в присоединённый корень требует приватного объекта полномочий соответствующего контейнера; публичного accessor для этого объекта нет. Приложения не создают такие объекты полномочий, не публикуют записи, не освобождают retirement holders и не вызывают вспомогательные методы инвалидации кеша.

## Ошибки освобождения после зафиксированной мутации

Все четыре публичные мутации регистраций выполняют проверку, публикацию, полную инвалидацию затронутого состояния, затем нативное освобождение удержанных корней: `set()`, `alias()`, `delegator()` и `addContainer()`. Standalone composite применяет соответствующую последовательность к собственной таблице привязок. После начала окончательного освобождения мутация больше не записывает состояние.

[`MutationRetirementException`](../../src/Exception/MutationRetirementException.php) расширяет `InvalidConfigurationException` и означает, что регистрация уже зафиксирована. Обрабатывайте этот подтип раньше общего перехвата некорректной конфигурации:

```php
use Componenta\DI\Exception\MutationRetirementException;

try {
    $container->set('message', Definition::factory(
        static fn(): Message => new Message('replacement'),
    ));
} catch (MutationRetirementException $failure) {
    // Мутация, породившая эту ошибку, уже зафиксирована.
    // Сообщите об ошибках освобождения; не повторяйте внешнюю регистрацию вслепую.
    foreach ($failure->causes as $cause) {
        error_log($cause->getMessage());
    }
} catch (InvalidConfigurationException $failure) {
    // Эта внешняя регистрация отклонена до публикации.
    // Более ранние успешные вложенные мутации, если были, сохраняются.
    throw $failure;
}
```

Исключение предоставляет:

| Поле или метод | Значение |
| --- | --- |
| readonly `?string $id` | Зафиксированный канонический ID для `set()`; запрошенный ID alias/делегатора для `alias()`/`delegator()`; `null` для `addContainer()`; регистрируемый ID для standalone `setDefinition()` |
| readonly `array $causes` | Непустой упорядоченный список всех `Throwable`, синхронно доставленных на границах освобождения непосредственно удержанных корней; причины не дедуплицируются |
| `getPrevious()` | Первый элемент `causes` |
| Сообщение | Явно сообщает, что мутация зафиксирована |

Зафиксированный результат относится к операции, при освобождении которой возникло исключение. Существующее распространение Componenta-исключений сохраняется: callback проверки или анализа владения может сам выполнить вложенную мутацию и распространить ошибку её освобождения до публикации внешней операции. Тогда внешняя мутация остаётся неопубликованной, а вложенное зафиксированное изменение сохраняется. Учитывайте контекст ошибки и причины; сам тип исключения не превращает произвольное повторное вхождение через callback в атомарную внешнюю операцию.

Освобождение пытается отпустить каждый непосредственно удержанный корень в порядке добавления, продолжает работу после доставленных ошибок и снимает владение через нативное освобождение ссылок. Оно не вызывает `__destruct()` напрямую и не принуждает сборку мусора. Порядок разрушения внутри вложенных пользовательских графов не гарантируется. Ссылки приложения, активные вызовы, циклы, воскресшие объекты и трассировки исключений могут сохранять объект живым. Более поздний GC, другой Fiber деструктора и ошибки завершения процесса находятся за пределами синхронного сбора исключений.

Деструктор, повторно запрашивающий сервис, наблюдает завершённые публикацию и инвалидацию. Если он выполняет ещё одну успешную мутацию, эта новая мутация остаётся текущей, даже когда внешнее освобождение позднее выбрасывает исключение. Приостановленный деструктор также не приводит к последующим внешним записям поверх более новой мутации. Это контракт зафиксированного результата; откат callback-функций приложения или внешних побочных эффектов не обещается.

## Проверки при миграции

Найдите в расширениях удалённый интерфейс, прежние аргументы конструкторов резолверов, вызовы листовых `setDefinition()`/`resolve()` и предположения, что нормализованная фабрика уже является callable. Перенесите регистрации в корень и храните конфигурацию в дескрипторах. Проверьте вложенные composites на ожидание живого ребёнка и совместное использование присоединённых корней. Обновите обработку ошибок мутаций до включения повторов на основании `InvalidConfigurationException`.

Руководство сопровождают исполняемые контрактные проверки:

- [Допуск пользовательских стратегий и дескрипторы активных вызовов](../../tests/Contract/CentralDefinitionStrategyContractTest.php).
- [Прямое использование встроенных стратегий и отложенные владельцы](../../tests/Contract/DefinitionStrategyDirectUseContractTest.php).
- [Композиция снимков и полномочия присоединённого корня](../../tests/Contract/DefinitionSnapshotCompositionContractTest.php).
- [Освобождение после публичных мутаций](../../tests/Contract/MutationRetirementContractTest.php) и [границы нативного освобождения](../../tests/Contract/MutationRetirementBoundaryContractTest.php).
- [Момент проверки при нормализации](../../tests/Contract/DependencyNormalizationContractTest.php).
