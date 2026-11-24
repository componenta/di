# Интерфейсы и реализации обработчиков атрибутов

Обработчики располагаются в `Componenta\DI\Resolver\Attribute` и его вложенном пространстве имён `Handler`. Они связывают смысловое определение `AttributeDefinition` с созданием объекта или разрешением параметров.

## Интерфейсы обработчиков

`AttributeHandlerInterface` предоставляет:

```php
handle(
    object $attribute,
    Reflector $target,
    ObjectCreationContext $context,
): void;
```

Метод выполняется на предусмотренных этапах создания объекта, обработки свойства или действия инициализации. Через `ObjectCreationContext` можно управлять вызовом конструктора, выбирать способ создания и получать права записи в свойства.

`ParameterAttributeHandlerInterface` предоставляет:

```php
resolveParameter(
    object $attribute,
    ParameterTarget $target,
    ParameterResolutionContext $context,
    AttributePlan $plan,
    ParameterAttributeValue $value,
): ParameterAttributeValue;
```

Метод получает состояние значения и возвращает обновлённое состояние. Нельзя предполагать, что значение уже было предоставлено. Первые параметры функций преобразования и инициализации имеют отдельные ограничения, описанные в [руководстве по функциям обратного вызова](../migrations/di6-callbacks.md).

## Встроенные классы обработчиков

| Класс в `Handler` | Конструктор | Интерфейс | Назначение |
| --- | --- | --- | --- |
| `CastHandler` | `__construct(ContainerInterface $container)` | Оба | Применение преобразователя `Cast` к параметру или свойству. |
| `ConfigHandler` | `__construct(ContainerInterface $container)` | Оба | Чтение значений `Config`. |
| `EntryIdHandler` | `__construct(ContainerInterface $container)` | Оба | Получение службы по явно заданному `EntryId`. |
| `EnvHandler` | `__construct(ContainerInterface $container)` | Оба | Получение `Env` со связанным преобразованием типа. |
| `InitHandler` | `__construct(CallableInvokerInterface $callableInvoker)` | Объект | Инициализация свойства через `Init`. |
| `InjectHandler` | `__construct(ContainerInterface $container)` | Объект | Внедрение свойства через `Inject`. |
| `LazyHandler` | Без аргументов | Объект | Выбор отложенного создания для `Lazy`. |
| `MakeHandler` | `__construct(FactoryInterface $factory, ProxyFactoryInterface $proxyFactory, AttributePlanBuilder $plans)` | Оба | Обработка `Make` и поддерживаемых вариантов внедрения виртуального заместителя. |
| `NoConstructorHandler` | Без аргументов | Объект | Отключение конструктора при `NoConstructor`. |
| `RequestAttributeHandler` | Сигнатура ниже | Параметр | Получение данных PSR-7, преобразование полей и создание объектов передачи данных. |

Пометка «Оба» означает реализацию `AttributeHandlerInterface` и `ParameterAttributeHandlerInterface`. Обработчики параметров предоставляют `resolveParameter()`, а обработчики объектов — `handle()`. В `CastHandler::resolveParameter()` есть дополнительный необязательный аргумент `?Closure $beforeConsumption = null`: он позволяет проверить изменения правил источников перед использованием значения.

Конструктор `RequestAttributeHandler`:

```php
public function __construct(
    FactoryInterface $factory,
    CasterProviderInterface $casterProvider,
    ?ValidationProviderInterface $validationProvider = null,
    ?Closure $callbacks = null,
);
```

Дополнительное средство вызова функций требуется при самостоятельной настройке `MapRequest(afterMap: ...)`. Стандартная `ContainerFactory` подключает его сама. Пользовательская реализация должна соблюдать правила источников HTTP-данных и регистрироваться через `AttributeDefinition`.

## Порядок выполнения и сочетания

`AttributeProcessor` (в `Resolver\Attribute`) имеет методы `__construct(AttributePlanBuilder $plans)`, `prepare(ReflectionClass $class): void` и `hasHandlers(ReflectionClass $class): bool`. Регистрация, этапы и возможности атрибутов описаны в [справочнике сочетаний](attribute-composition.md).

Собственный обработчик следует передать через `AttributeDefinition` в поставщике конфигурации до создания контейнера. Позднее обнаружение несовместимых объявлений может приводить к ошибке вместо повторного исполнения уже завершённой операции. См. [Параметры](parameters.md) и [Контекст создания](entry-resolvers.md).
