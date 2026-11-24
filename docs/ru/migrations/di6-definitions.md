# Миграция определений и расширений на DI 6

[English](../../migrations/di6-definitions.md)

Описан API **ещё не выпущенной** DI 6. Основные операции `ContainerFactory`, `get()`, `make()`, `set()`, фабрики и aliases сохраняются. Изменения в основном затрагивают декларативные определения и низкоуровневые расширения.

## Декларативные определения

`ClassDefinition::call()` заменяет прежний `method()`. Методы `constructor()`, `call()` и `autowire()` возвращают новые неизменяемые определения.

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

$container->set(
    Client::class,
    ClassDefinition::create(Client::class)
        ->constructor(['endpoint' => 'https://api.example.test'])
        ->call('setLogger', [Definition::reference(LoggerInterface::class)]),
);
```

`ClassDefinition` выполняет заданные вызовы конструктора и методов, но **не исполняет DI-атрибуты** класса. Разрешение недостающих аргументов по типам требует `autowire()`. `Definition::reference()` описывает аргумент-ссылку, а не отдельный сервис. Invokables вызывают конструктор без передаваемых аргументов. Фабрика сама управляет созданием результата; его DI-атрибуты после возврата не обрабатываются.

## Пользовательские стратегии

DI 6 хранит регистрации в единой таблице. Стратегии валидируют и исполняют дескрипторы, не создавая собственных карт ID → definition.

| Ранее | DI 6 |
| --- | --- |
| `DefinitionAwareResolverInterface` | `DefinitionResolverInterface` |
| `new FactoryResolver($factories, $container, $proxyFactory)` | `new FactoryResolver($container, $proxyFactory)` |
| `new InvokableResolver($invokables)` | `new InvokableResolver()` |
| Регистрация внутри отдельных встроенных стратегий | `CompositeResolver::setDefinition()` |
| Fallback `resolve(string $id, array $params)` | `resolve(string $id, EntryParameters $params)` |

`DefinitionResolverInterface` содержит `supportsDefinition()`, `validateDefinition()` и `resolveDefinition()`. Валидация возвращает дескриптор для последующего исполнения, а `CompositeResolver` владеет регистрациями. Fallback-резолверы по-прежнему реализуют `EntryResolverInterface`.

`DependencyConfiguration::normalize()` сохраняет объекты определений в секции фабрик. Нормализация сама по себе не гарантирует возможности исполнить дескриптор; он проверяется при регистрации.

## Прямое создание контейнера

Предпочтителен `ContainerFactory::create($config, $dependencies)`. При прямом создании `Container` и внутренних компонентов необходимо проверить изменённую сигнатуру конструктора и правила владения DI 6.

## Пользовательские fallback-резолверы

`EntryResolverInterface::resolve()` получает `EntryParameters`. Аргументы приложения доступны через `$params->values`. Для продолжения той же операции с сохранением происхождения данных используйте `EntryParameters::forwardTo()`. Вызов `make()` с обычным массивом начинает другую операцию создания.

## Ошибки при изменении привязок

`set()`, `alias()`, `delegator()` и `addContainer()` инвалидируют затронутые кеши до освобождения старых значений. `MutationRetirementException` означает, что изменение **уже применено**, хотя очистка завершилась ошибкой. Перехватывайте его до `InvalidConfigurationException` и не повторяйте регистрацию автоматически. Если валидация отклонила изменение до публикации, прежняя привязка сохраняется.

Отсутствующий запрошенный ID даёт ошибку PSR-11 NotFound; отсутствие зависимости внутри существующего сервиса — ошибку разрешения.

Перед обновлением проверьте интерфейсы резолверов, конструкторы стратегий и вызовы `ClassDefinition::method()`. См. [Определения](../guide/definitions.md) и [Поведение](../guide/runtime.md).
