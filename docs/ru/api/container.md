# Интерфейс контейнера и настройка

Если не указано иное, перечисленные типы принадлежат пространству имён `Componenta\DI`. Полный пример создания контейнера приведён в разделе [«Начало работы»](../guide/getting-started.md).

## ContainerFactory

```php
public function create(Config $config, DependencyDefinitions $dependencies): ContainerValue;
public function createWithTargetedAttributeDiscovery(Config $config, DependencyDefinitions $dependencies): ContainerValue;
```

Оба метода собирают контейнер и возвращают `Componenta\Config\ContainerValue`, содержащий контейнер и переданную конфигурацию. Второй метод ограничивает проверку загрузки классов атрибутов объявлениями текущей цели. Это альтернативный способ обнаружения атрибутов.

## Container

`Container` реализует интерфейсы `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface` и `ProxyFactoryInterface`.

| Метод | Назначение |
| --- | --- |
| `get(string $id): mixed` | Получение общего значения собственной записи либо значения внешнего контейнера. |
| `has(string $id): bool` | Проверка существования идентификатора, не гарантирующая успешного создания. |
| `make(string $entry, array $params = []): object` | Создание объекта через собственное определение или дополнительный способ поиска, без повторного использования общих значений и обработки результата. |
| `call(mixed $callable, array $params = []): mixed` | Разрешение аргументов и вызов функции или метода. |
| `resolve(mixed $callable): callable` | Получение вызываемого значения без его выполнения. |
| `makeLazy(string $class, callable $initializer): object` | Создание объекта с отложенным заполнением состояния. |
| `makeProxy(string $class, callable $factory): object` | Создание виртуального заместителя. |
| `set(string $id, mixed $entry): void` | Замена готового значения или регистрация определения со сбросом затронутых сохранённых значений. |
| `alias(string $alias, string $target): void` | Создание или изменение псевдонима с проверкой циклов и защищённых идентификаторов. |
| `delegator(string $id, callable\|string\|array $delegator): void` | Добавление обработчика результата собственной записи. |
| `addContainer(ContainerInterface $container): void` | Подключение внешнего контейнера стандарта PSR-11. |
| `notifyExternalEntriesChanged(ContainerInterface $provider, string ...$ids): void` | Уведомление об изменениях указанных записей подключённого контейнера. |

Прямое создание также допускается:

```php
public function __construct(
    EntryResolverInterface $resolver,
    CallableExecutorInterface $callableExecutor,
    array $aliases = [],
    array $services = [],
    ?ProxyFactoryInterface $proxyFactory = null,
);
```

При прямом создании нужно самостоятельно подготовить взаимодействующие компоненты. В приложениях обычно используется `ContainerFactory`, настраивающий встроенные службы.

Методы `set()`, `alias()`, `delegator()` и `addContainer()` не позволяют заменить защищённые службы ядра. При изменении привязок сбрасываются затронутые сохранённые значения. `MutationRetirementException` означает, что изменение уже применено, но освобождение заменённых объектов завершилось ошибкой. См. [«Поведение и исключения»](../guide/runtime.md).

## Вспомогательные типы конфигурации

| Тип | Открытые методы и свойства | Назначение |
| --- | --- | --- |
| `Componenta\DI\ConfigKey` | Наследует константы `Componenta\Config\ConfigKey` | Имена разделов конфигурации и зависимостей. |
| `Componenta\DI\Configuration\DependencyConfiguration` | `static normalize(array $dependencies, array $defaultAliases = []): array` | Нормализация определений без создания служб. |
| `DependencyConfiguration` | `static assertShape(array &$dependencies): void` | Проверка структуры и замена исходного массива нормализованными данными после успешной проверки. |
| `FactoryInterface` | `make(string $entry, array $params = []): object` | Узкий интерфейс создания объектов с необязательными аргументами. |

Одна лишь нормализация не доказывает, что фабрика может создать объект. Типы `Config`, `DependencyDefinitions`, `Environment` и `ConfigProvider` предоставляет отдельный пакет `componenta/config`.

## Низкоуровневое создание объектов

`Componenta\DI\Object\ObjectPipeline` содержит:

```php
public function __construct(
    AttributeProcessor $attributes,
    ParametersResolver $parameters,
    ProxyFactoryInterface $proxies,
);
public function prepare(string|ReflectionClass $class): void;
public function canCreate(string|ReflectionClass $class): bool;
public function create(
    string|ReflectionClass $class,
    array|EntryParameters $params = [],
    ?Closure $configure = null,
    ?Closure $resolveParameters = null,
): object;
```

Класс создаёт объекты с разрешением аргументов конструктора и обработкой атрибутов. `prepare()` подготавливает сведения, `canCreate()` проверяет возможность создания, а `create()` принимает готовые параметры или функцию их отложенного предоставления и необязательное действие после инициализации.

Перечисление `Componenta\DI\Object\CreationStrategy` содержит значения `Eager`, `Lazy`, `Proxy`. Способ создания выбирают зарегистрированные обработчики через контекст создания объекта.

## Другие разделы

[Вызов функций](callables.md) · [Определения](definitions.md) · [Отложенное создание](lazy.md) · [Расширения](../guide/extensions.md).
