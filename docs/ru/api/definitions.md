# Интерфейсы и классы определений зависимостей

Явные определения передаются в `Container::set()` или добавляются через раздел `getFactories()` поставщика конфигурации. Описанные ниже типы принадлежат пространству имён `Componenta\DI\Definition`.

## Фабрики описаний

| Тип | Открытые методы и свойства | Назначение |
| --- | --- | --- |
| `Definition` | `static factory(callable $factory): FactoryDefinition`; `static reference(string $entryId): ReferenceDefinition`; `static invokable(string $className): InvokableDefinition` | Создание описаний различных видов. |
| `DefinitionInterface` | Без методов | Общий признак определений, поддерживаемых зарегистрированными стратегиями. |
| `FactoryDefinition` | `__construct(callable $value)`; свойство `$value` | Описание фабрики для создания значения по запросу. |
| `InvokableDefinition` | `__construct(string $value)`; свойство `$value` | Описание класса, конструктор которого вызывается без явных аргументов. |
| `ReferenceDefinition` | `__construct(string $value)`; свойство `$value` | Ссылка на идентификатор другой службы **внутри настроенного аргумента**. Не поддерживается встроенными стратегиями как самостоятельная запись. |

Фабрика может объявлять первые аргументы последовательности `(ContainerValue $context, array $params)` и сама отвечает за создание значения. Для класса из `InvokableDefinition` не передаются аргументы конструктора и не исполняются его атрибуты внедрения зависимостей.

## ClassDefinition

`ClassDefinition` задаёт аргументы конструктора, последовательность вызовов методов и необязательный поиск недостающих зависимостей по типу.

```php
public function __construct(
    string $value,
    array $constructorParams = [],
    array $methodCalls = [],
    bool $autowire = false,
);
public static function create(string $className): self;
public function constructor(array $params): self;
public function call(string $method, array $params = []): self;
public function autowire(bool $enabled = true): self;
```

Открытые свойства для чтения: `$value`, `$constructorParams`, `$methodCalls`, `$autowire`. Методы изменения настроек возвращают **новые** объекты. `call()` сохраняет повторные вызовы и заменяет прежнее имя `method()`.

```php
use Componenta\DI\Definition\{ClassDefinition, Definition};

$definition = ClassDefinition::create(Client::class)
    ->constructor(['endpoint' => 'https://api.example.test'])
    ->call('setLogger', [Definition::reference(LoggerInterface::class)])
    ->autowire();

$container->set(Client::class, $definition);
```

Для отсутствующих аргументов применяются стандартные значения PHP. `autowire()` дополнительно ищет классы и интерфейсы для недостающих аргументов. Явные значения, включая `null`, имеют приоритет. Ключи с именами типов в аргументах вызова **не считаются** аргументами конструктора. Во вложенных настроенных массивах ссылки на службы разрешаются рекурсивно; значения, переданные непосредственно при вызове, не преобразуются. Неизвестные настроенные имена, лишние позиции и циклические массивы отклоняются.

`ClassDefinition` непосредственно вызывает доступный конструктор и перечисленные методы. Он **не выполняет обработчики атрибутов внедрения зависимостей** класса, свойств, параметров конструктора и методов. Неудачно созданное значение не сохраняется как общее.

## Другие открытые интерфейсы

`Componenta\DI\FactoryInterface` объявляет `make(string $entry, array $params = []): object` и реализуется контейнером. Контракты `DefinitionResolverInterface` и `EntryResolverInterface` описаны в [справочнике расширений](extensions.md).

Дополнительные сведения: [Определения](../guide/definitions.md) и [Переход с версии 5](../migrations/di6-definitions.md).
