# Интерфейсы разрешения параметров

Открытые классы этого раздела находятся в `Componenta\DI\Resolver\Parameter` и `Componenta\DI\Resolver\Target`. Они описывают и разрешают аргументы конструкторов, методов, действий инициализации и вызовов с внедрением зависимостей.

## Интерфейс обработчика параметра

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

`supports()` определяет применимость обработчика без изменения цепочки. `resolveParameter()` возвращает `[позиция параметра, значение]` либо `null` для перехода к следующему обработчику. Предусмотренные ошибки разрешения следует выражать через `ResolutionException`. Для параметра переменной длины передаётся единый массив всех его значений, а не отдельный результат для каждого элемента.

## Разрешение аргументов без вызова обработчика

Контейнер, созданный через `ContainerFactory`, предоставляет защищённый сервис `ParametersResolverInterface`. Метод `resolve(array $parameters, array $providedParameters = []): array` использует настроенную и закрытую для регистрации цепочку разрешения параметров, включая пользовательские разрешатели и обработчики атрибутов, не вызывая целевую функцию. Результат — список аргументов для нативного вызова с развёрнутыми вариативными значениями и пропущенными нативными значениями по умолчанию там, где это необходимо.

```php
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;

$target = static fn(string $label = 'default'): string => $label;
$resolver = $container->get(ParametersResolverInterface::class);
$arguments = $resolver->resolve((new ReflectionFunction($target))->getParameters(), ['label' => 'example']);
$result = $container->call(new PreparedCallable($target), $arguments);
```

Сервис не предоставляет операции регистрации, управления кешем планов или закрытия цепочки. Конкретный `ParametersResolver`, используемый при сборке, остаётся скрытым. Разрешение через этот контракт допустимо после закрытия цепочки для регистрации.

## ParametersResolver

```php
public function __construct(
    AttributePlanBuilder $plans,
    ?ParameterTargetFactory $targets = null,
);
public function add(ParameterResolverInterface $resolver, int $priority = 0): void;
public function seal(): void;
public function resolve(array $parameters, array $providedParameters = []): array;
public function targets(array $parameters): array;
public function resolveTargets(array $targets, array $providedParameters = []): array;
public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array;
public function target(ReflectionParameter $parameter): ParameterTarget;
```

Свойство `$isSealed`, доступное для чтения, показывает, закрыта ли регистрация. Более высокий целочисленный приоритет выполняется раньше; одинаковые приоритеты сохраняют порядок добавления. `add()` расширяет цепочку до вызова `seal()`. `resolve()` принимает список отражённых параметров, `targets()` подготавливает описания, а `resolveTargets()` разрешает их с переданными аргументами. Стандартная `ContainerFactory` самостоятельно собирает и закрывает свою цепочку.

## Встроенные обработчики

Все классы реализуют `ParameterResolverInterface` с методами `supports()` и `resolveParameter()`:

| Класс | Конструктор | Назначение |
| --- | --- | --- |
| `ArrayResolver` | Без аргументов | Получение явно переданного значения сначала по имени, затем по позиции. |
| `ArrayTypedResolver` | Без аргументов | Поиск явно переданного объекта по имени класса или интерфейса, кроме служебных объектов HTTP-запроса. |
| `AttributeParameterResolver` | `__construct(AttributePlanBuilder $plans)` | Выполнение согласованных обработчиков атрибутов параметра. |
| `AttributeMatchingParameterResolver` | `__construct(string $attribute, Closure $resolve)` | Выбор определённого атрибута с вызовом функции, возвращающей значение. |
| `AutowireByTypeResolver` | `__construct(ContainerInterface $container)` | Поиск по классу или интерфейсу; для простого объединения выбирается только единственный доступный кандидат. |
| `DefaultValueResolver` | Без аргументов | Подстановка значения PHP по умолчанию или пустого массива для параметра переменной длины. |
| `NullableResolver` | Без аргументов | Подстановка `null` для допускающего его обычного параметра. |

`ParameterSourceAttributeInterface` — интерфейс-признак атрибутов источника параметра, не содержащий методов.

## Контекст и описание параметров

| Тип | Открытые методы и свойства | Назначение |
| --- | --- | --- |
| `ParameterResolutionContext` | `__construct(array $provided = [], array $resolved = [])`; свойства `$provided`, `$resolved` | Копии переданных аргументов и уже полученных значений. |
| `ParameterAttributeValue` | `static unresolved(): self`, `static resolved(mixed $value): self`; свойства `$resolved`, `$value` | Состояние получения значения в цепочке атрибутов. |
| `ParameterTargetFactory` | `__construct()`, `create(ReflectionParameter $parameter): ParameterTarget` | Создание описания параметра по данным механизма отражения PHP. |
| `ParameterTarget` | `__construct(ReflectionParameter $reflection)`; методы ниже | Неизменяемое описание объявления параметра и его типа. |

`ParameterTarget` предоставляет свойства для чтения: `reflection`, `defaultValue`, `attributeClasses`, `typeNames`, `className`, `name`, `position`, `type`, `allowsNull`, `hasDefault`, `variadic`, `byReference`, `declaringContext`. Открытые методы:

```php
hasAttribute(string $attributeClass): bool;
firstAttribute(string $attributeClass): ?object;
watchAttributeAvailability(string $attributeClass): void;
accepts(mixed $value): bool;
unionCandidates(): array;
```

`watchAttributeAvailability()` сообщает о зависимости пользовательского обработчика от класса атрибута, который может быть загружен позднее. `unionCandidates()` возвращает допустимые классы и интерфейсы простого объединения, а `accepts()` проверяет соответствие полному типу.

## Вспомогательные методы TypeHints

`Componenta\DI\Resolver\TypeHints` содержит статические методы отражения:

```php
classOf(?ReflectionType $type, ?ReflectionClass $declaringClass = null): ?string;
classNames(?ReflectionType $type, ?ReflectionClass $declaringClass = null): array;
simpleUnionClassNames(?ReflectionType $type, ?ReflectionClass $declaringClass = null): array;
matches(?ReflectionType $type, mixed $value, ?ReflectionClass $declaringClass = null): bool;
```

Они нормализуют имена классов и проверяют типы, включая объединения и пересечения. Когда описание параметра уже создано, для расширений удобнее пользоваться `ParameterTarget`.

## Порядок стандартных обработчиков

Контейнер использует следующие приоритеты (большее значение выполняется первым):

| Обработчик | Приоритет |
| --- | --- |
| `AttributeParameterResolver` | 1200 |
| `ArrayResolver` | 1100 |
| `ArrayTypedResolver` | 1000 |
| `AutowireByTypeResolver` | 300 |
| `DefaultValueResolver` | 200 |
| `NullableResolver` | 100 |

Метод поставщика `getParameterResolvers()` возвращает массив с целочисленными ключами, содержащий экземпляры обработчиков, идентификаторы служб, вызываемые фабрики или отложенные пары «служба — метод». Фабрика получает контейнер PSR-11 и должна вернуть `ParameterResolverInterface`. Возврат `true` из `shouldReplaceParameterResolvers()` отключает добавление стандартной цепочки: собственная цепочка должна обеспечить всё необходимое разрешение. Значение `null` сохраняет признак, установленный предыдущим поставщиком. При равном приоритете сохраняется порядок добавления обработчиков.

При клонировании `ParameterTarget` метод `__clone()` отключает наблюдение за доступностью атрибутов, сохраняя сведения об объявлении параметра.

См. [Обработчики атрибутов](attribute-handlers.md), [Вызов функций](callables.md) и [Пользовательские расширения](../guide/extensions.md).
