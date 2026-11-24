# Поиск зависимостей и создание объектов

Перечисленные открытые типы находятся в `Componenta\DI\Resolver\Entry`; классы для параметров инициализации — во вложенном пространстве имён `SetUp`. Это низкоуровневые интерфейсы, на которых работает собранный контейнер.

## Явные определения и дополнительный поиск

| Тип | Открытые сигнатуры | Назначение |
| --- | --- | --- |
| `DefinitionResolverInterface` | `supportsDefinition(DefinitionInterface $definition): bool`; `validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface`; `resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed` | Определение поддерживаемых видов описаний, их проверка и исполнение без собственной таблицы регистраций. |
| `EntryResolverInterface` | `can(string $id): bool`; `resolve(string $id, EntryParameters $params): mixed` | Дополнительный поиск значения по идентификатору. |
| `CompositeResolver` | `__construct(EntryResolverInterface\|DefinitionResolverInterface ...$providers)`; `can(string $id): bool`; `resolve(string $id, array\|EntryParameters $params = []): mixed`; `setDefinition(string $id, DefinitionInterface $definition): void` | Объединение явных определений и последовательных способов поиска; хранение привязок. |
| `FactoryResolver` | `__construct(ContainerInterface $container, ProxyFactoryInterface $proxyFactory)`; методы `DefinitionResolverInterface` | Исполнение фабрик и описаний `ClassDefinition`. |
| `InvokableResolver` | Методы `DefinitionResolverInterface`; конструктор без аргументов | Создание `InvokableDefinition` вызовом конструктора без переданных аргументов. |
| `ReflectionResolver` | `__construct(ObjectPipeline $objects, array $excluded = [])`; `can(string $id): bool`; `resolve(string $id, array\|EntryParameters $params = []): object` | Создание допустимых конкретных классов без явной регистрации. |
| `EntryParametersFactoryInterface` | Наследует `FactoryInterface`; `makeEntry(string $entry, EntryParameters $parameters): object` | Получение аргументов создания вместе со сведениями об их происхождении. |
| `EntryParameters` | Свойство для чтения `$values`; `without(string\|int $key): self`; `forwardTo(FactoryInterface $factory, string $entry): object` | Представление и передача аргументов одной операции создания. |

`CompositeResolver` использует поставщиков в указанном порядке. Стратегия явного определения выбирается по заявленной поддержке, поэтому ошибка её проверки не означает автоматической попытки другой подходящей стратегии. После присоединения составного обработчика к контейнеру новые записи следует добавлять через `Container::set()`.

Объекты `EntryParameters` создаёт механизм DI; его унаследованный конструктор не открыт для прикладного кода. `without()` возвращает набор без указанного ключа, не изменяя исходный объект. `forwardTo()` сохраняет сведения об источниках HTTP-полей для контейнера или реализации `EntryParametersFactoryInterface`; обычная фабрика получает аргументы через `make()`.

## Контекст создания и вспомогательные классы

| Тип | Открытые сигнатуры | Назначение |
| --- | --- | --- |
| `InstanceCreator` | `__construct(ParametersResolver $parameters)`; `create(ReflectionClass $class, array $params = []): object`; `initialize(object $entry, ReflectionClass $class, array $params = []): void` | Создание или инициализация экземпляра через цепочку разрешения параметров. |
| `ObjectCreationContext` | Свойства и методы ниже | Состояние одной операции создания, передаваемое обработчикам атрибутов. Напрямую прикладным кодом не создаётся. |

Контекст `ObjectCreationContext` предоставляет свойства для чтения `$class`, `$constructorEnabled`, `$strategy`, `$entry`, `$parameters`, а также методы:

```php
disableConstructor(): void;
selectStrategy(CreationStrategy $strategy): void;
claimProperty(ReflectionProperty $property, bool $allowPromoted = false): bool;
propertyClaimed(ReflectionProperty $property): bool;
readProperty(ReflectionProperty $property): mixed;
writeProperty(ReflectionProperty $property, mixed $value): void;
```

Контекст принадлежит одной попытке создания; управлять его жизненным циклом должен контейнер. Обработчики обязаны учитывать этап выполнения, владение свойствами и порядок вызова конструктора. Сохранять контекст для последующих операций не следует.

## Преобразование аргументов SetUp

Все классы в `Componenta\DI\Resolver\Entry\SetUp` реализуют `SetUpValueUnwrapperInterface`:

```php
supports(mixed $value): bool;
unwrap(mixed $value, string $key, ?ReflectionParameter $parameter = null): mixed;
```

| Класс | Конструктор | Поддерживаемые значения |
| --- | --- | --- |
| `ConfigUnwrapper` | `__construct(ContainerInterface $container)` | Описания `#[Config]` в аргументах инициализации. |
| `EnvUnwrapper` | `__construct(ContainerInterface $container)` | Описания `#[Env]`. |
| `EntryIdUnwrapper` | `__construct(ContainerInterface $container)` | Описания `#[EntryId]`. |
| `ContainerValueUnwrapper` | `__construct(ContainerValue $container)` | Типы Componenta Config: `ContainerEntry`, `ConfigEntry`, `EnvironmentEntry`, `LazyValue`. |
| `SetUpValueUnwrapperInterface` | Интерфейс без конструктора | Договор на распознавание и преобразование значений инициализации. |

`ContainerValueUnwrapper` при необходимости преобразует значения окружения в поддерживаемый скалярный тип или массив с учётом объявленного параметра. Эти классы обслуживают обычную обработку `SetUp`, но не включают исполнение атрибутов внутри `ClassDefinition`.

См. [Создание объектов](container.md), [Определения](definitions.md) и [Обработчики атрибутов](attribute-handlers.md).
