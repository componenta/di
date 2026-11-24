# Интерфейсы сочетания атрибутов

Типы этого раздела принадлежат пространству имён `Componenta\DI\Attribute\Composition`, кроме `AttributePhase` и `AttributeProcessor` из `Componenta\DI\Resolver\Attribute`. Они предназначены для собственных обработчиков атрибутов и правил их сочетания.

## Определение и регистрация

| Тип | Открытые методы и свойства | Назначение |
| --- | --- | --- |
| `AttributeDefinition` | Конструктор ниже; свойства `attribute`, `handler`, `capabilities`, `requires`, `forbids`, `before`, `after`, `rules`, `phase` | Неизменяемое описание поведения одного класса атрибутов. |
| `AttributeDefinitionRegistry` | `__construct(bool $targetedDiscovery = false)`, `register(AttributeDefinition $definition): void`, `defineCapability(CapabilityPolicy $policy): void`, `seal(): void`, `definition(string $attribute): ?AttributeDefinition`, `policy(string $capability): CapabilityPolicy`, `definitions(): array`, `policies(): array` | Регистрация и последующее закрытие набора правил атрибутов. |
| `CapabilityPolicy` | `__construct(string $capability, ?int $maxPerTarget = null)` | Ограничение количества атрибутов с одной возможностью у цели; `null` означает отсутствие ограничения. |
| `AttributeCapabilityInterface` | Без методов | Общий признак одной смысловой возможности атрибута. |
| `AttributeCompositionRuleInterface` | `validate(AttributeUsage $attribute, AttributeSet $set): void` | Проверка собственного правила сочетания атрибутов с исключением при нарушении. |

```php
new AttributeDefinition(
    attribute: TenantId::class,
    handler: $handler,
    capabilities: [ValueProvider::class],
    requires: [],
    forbids: [],
    before: [],
    after: [],
    rules: [],
    phase: AttributePhase::AfterInstantiation,
);
```

Обработчик реализует `AttributeHandlerInterface` для объектов, `ParameterAttributeHandlerInterface` для параметров либо оба интерфейса. В `requires`, `forbids`, `before` и `after` допускаются имена классов атрибутов или интерфейсов возможностей. Массивы при создании определения копируются; объекты обработчиков и правил сохраняют свою идентичность.

`AttributePhase` — перечисление со значениями `BeforeInstantiation = 100`, `AfterInstantiation = 200`, `Both = 300`. Обработка параметров имеет отдельный договор и не заменяет обработку этапов создания объекта.

## Планы выполнения и сведения об атрибутах

| Тип | Открытые методы и свойства | Назначение |
| --- | --- | --- |
| `AttributePlanBuilder` | `__construct(AttributeDefinitionRegistry $registry)`, `build(Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target): AttributePlan` | Построение последовательности обработки объявлений у цели. |
| `AttributePlan` | `__construct($target, array $usages)`, `all(string $capability): array`, `one(string $capability): ?AttributeUsage`, `has(string $capability): bool`, `attributes(string $attributeClass): array`; `$usages` | Получение записей по возможности или классу с сохранением порядка плана. |
| `AttributeSet` | `__construct(array $attributes)`, `all(string $selector): array`, `one(string $selector): ?AttributeUsage`, `has(string $selector): bool`; `$attributes` | Представление набора атрибутов для проверки правил сочетания. |
| `AttributeUsage` | `__construct(ReflectionAttribute $reflection, AttributeDefinition $definition, $target, int $declarationOrder)`, `newInstance(): object`, `is(string $attribute): bool`, `hasCapability(string $capability): bool`, `matches(string $selector): bool`; `$attributeClass`, `$arguments`, `$definition`, `$declarationOrder` | Одно объявление атрибута и его смысловые характеристики. |
| `AttributeProcessor` | `__construct(AttributePlanBuilder $plans)`, `prepare(ReflectionClass $class): void`, `hasHandlers(ReflectionClass $class): bool` | Подготовка и проверка обработки атрибутов объекта. |

Обращение к `AttributeUsage::$arguments` вычисляет аргументы объявления заново; выражения, создающие объекты, могут возвращать различные экземпляры. `newInstance()` создаёт экземпляр атрибута для текущего выполнения. Правила сочетания получают сведения об объявлении, а не общий изменяемый экземпляр атрибута.

## Встроенные возможности и правило

Следующие открытые интерфейсы-признаки находятся в `Attribute\Composition\Capability`:

| Интерфейс | Назначение |
| --- | --- |
| `ValueProvider` | Предоставление значения параметра или свойства. |
| `AuthoritativeValueProvider` | Источник, имеющий исключительное право определять значение цели. |
| `InvocationOnlyValueProvider` | Источник, допустимый только во время вызова функции или метода. |
| `ValueTransformer` | Преобразование уже полученного значения. |
| `ConstructorPolicy` | Изменение порядка вызова конструктора. |
| `CreationStrategy` | Выбор способа создания объекта. |
| `LifecycleHook` | Действие при инициализации объекта. |

Встроенное правило `Attribute\Composition\Rule\ProxyCompositionRule` реализует `AttributeCompositionRuleInterface` и метод `validate(AttributeUsage $attribute, AttributeSet $set): void`. Оно проверяет допустимость сочетаний, связанных с виртуальными заместителями.

## Время регистрации

Передавайте определения через `ConfigProvider::getAttributeDefinitions()`, а ограничения возможностей — через `getAttributeCapabilities()` до сборки контейнера. Уже завершённые этапы создания объектов и разрешения параметров не повторяются молча при появлении ранее неизвестных классов атрибутов; несовместимое позднее обнаружение может привести к `AttributeCompositionException`.

См. [Интерфейсы расширения](extensions.md), [Справочник всех атрибутов](../guide/attribute-reference.md) и [Обработчики](attribute-handlers.md).
