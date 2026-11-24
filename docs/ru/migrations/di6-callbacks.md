# Миграция callbacks и union-autowiring на DI 6

[English](../../migrations/di6-callbacks.md)

Описан API **ещё не выпущенной** DI 6. Пакет требует PHP 8.4+; для нативных closure-выражений непосредственно в аргументах атрибутов нужен PHP 8.5.

## Transform

`#[Transform]` — повторяемый атрибут параметра/свойства, принимающий один `Closure`. Он преобразует уже полученное значение; преобразования выполняются в порядке объявления.

```php
use Componenta\DI\Attribute\Transform;

// Эти выражения в атрибутах требуют PHP 8.5.
final class EmailInput
{
    public function __construct(
        #[Transform(trim(...))]
        #[Transform(static function (string $value): string {
            return strtolower($value);
        })]
        public string $email,
    ) {}
}

$input = $container->make(EmailInput::class, ['email' => ' Alice@Example.COM ']);
// $input->email === 'alice@example.com'
```

Первый параметр callback получает текущее значение, остальные разрешаются через DI. Обязательному параметру без значения всё равно нужен источник. Первый аргумент callback не может быть ссылочным, вариадическим либо содержать DI-атрибуты источника.

## SetUp и Init

`#[SetUp('initialize')]` по-прежнему вызывает публичный метод экземпляра. В DI 6 `SetUp::$method` дополнительно принимает `Closure`: первый параметр получает создаваемый объект, последующие разрешаются через DI. `SetUp` выполняется после внедрения свойств и не заменяет экземпляр возвращённым значением.

```php
use Componenta\DI\Attribute\SetUp;

// Замыкание в атрибуте требует PHP 8.5.
#[SetUp(method: static function (Worker $instance, Formatter $formatter): void {
    $instance->label = $formatter->format('ready');
})]
final class Worker
{
    public string $label;
}
```

`Init` сохраняет поддержку string/array/invokable callable. Наследники, повторно объявляющие `SetUp::$method` как `string`, должны учитывать новый тип `string|Closure`.

## Autowiring union-типов

Для простых невариадических union-типов выбирается один доступный сервис класса/интерфейса. Если подходящих сервисов нет, действуют обычные default/null правила; если их несколько, выбрасывается `ResolutionException`, даже когда aliases ведут к одному объекту. Неоднозначность снимается явным аргументом либо `#[EntryId]`. Intersection и составные DNF-типы автоматически не выбираются.

## Необязательный callback для HTTP mapping

`MapRequest` получает последний необязательный аргумент `?Closure $afterMap = null`. Callback получает весь подготовленный массив и возвращает массив до создания DTO; остальные аргументы разрешаются через DI. Существующие source-specific атрибуты, переопределения `transform()` и другие способы mapping сохраняются.

```php
use Componenta\DI\Attribute\MapRequest;

// Такая ссылка внутри атрибута требует PHP 8.5.
function normalizeInput(array $data): array
{
    return ['name' => trim((string) ($data['name'] ?? ''))];
}

$handler = static fn(
    #[MapRequest(afterMap: normalizeInput(...))] CreateInput $input
): CreateInput => $input;
```

Нативные выражения внутри атрибутов допускают статические замыкания без захвата и ссылки на объявленные функции/статические методы. Arrow functions, захват переменных и ссылки на методы объектов там не поддерживаются. В некоторых старых patch-версиях PHP 8.5 есть ошибка области видимости trait-callable; целевые проверки ветки включают PHP 8.5.13.

См. [Атрибуты и обработка запросов](../guide/attributes.md).
