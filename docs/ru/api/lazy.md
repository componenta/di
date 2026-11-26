# Интерфейсы отложенного создания объектов

Перечисленные типы относятся к пространству имён `Componenta\DI`. Стандартная реализация использует нативные средства отражения PHP. Для библиотеки требуется PHP 8.5.13 или новее.

| Тип | Открытые методы | Назначение |
| --- | --- | --- |
| `LazyObjectFactoryInterface` | `makeLazy(string $class, callable $initializer): object` | Создание объекта, в котором переданная функция заполнения состояния выполняется позднее и получает экземпляр. |
| `VirtualProxyFactoryInterface` | `makeProxy(string $class, callable $factory): object` | Создание виртуального заместителя; фабрика получает заместитель и возвращает основной объект по мере необходимости. |
| `ProxyFactoryInterface` | Объединяет оба интерфейса | Поддержка двух способов отложенного создания. |
| `ProxyFactory` | `makeLazy()`, `makeProxy()` | Стандартная реализация на основе `ReflectionClass::newLazyGhost()` и `newLazyProxy()`. |
| `LazyServiceFactoryInterface` | `lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object` | Передача прикладной фабрике управления собственным объектом с отложенной инициализацией. |

В следующих примерах используется класс `Catalog` из [руководства](../guide/lazy-objects.md).

```php
$ghost = $container->makeLazy(
    Catalog::class,
    static function (Catalog $instance): void {
        $instance->__construct();
    },
);

$proxy = $container->makeProxy(
    Catalog::class,
    static fn(Catalog $proxy): Catalog => new Catalog(),
);
```

Ни одна из переданных функций не выполняется при выделении объекта. Функция инициализации получает заполняемый экземпляр и не должна возвращать значение. Фабрика заместителя получает заместитель и должна вернуть совместимый неленивый объект, отличный от самого заместителя.

Если у в остальном допустимого класса нет свойств экземпляра с хранилищем, `ProxyFactory` выбрасывает `ResolutionException` до выделения объекта, чтобы не вернуть экземпляр с пропущенной инициализацией. Причина ограничения, условия инициализации и требования к основному объекту описаны в [руководстве по отложенному созданию](../guide/lazy-objects.md). Пользовательские реализации могут использовать иные механизмы, сохраняя отложенную инициализацию.

## Отложенное создание под управлением фабрики

Объект фабрики с интерфейсом `LazyServiceFactoryInterface` не обязан быть вызываемым. Зарегистрируйте его непосредственно в `getFactories()`. Вместо обычного вызова фабрики контейнер вызывает `lazy()` с объектом `ContainerValue` (контейнер PSR-11), фабрикой заместителей и переданными аргументами. Класс в примере должен удовлетворять ограничениям встроенного механизма.

```php
use Componenta\Config\ConfigProvider;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerInterface;

final class CatalogFactory implements LazyServiceFactoryInterface
{
    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        return $proxyFactory->makeProxy(
            Catalog::class,
            static fn(Catalog $proxy): Catalog => new Catalog(),
        );
    }
}

final class CatalogProvider extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [Catalog::class => new CatalogFactory()];
    }
}
```

См. [Отложенное создание объектов](../guide/lazy-objects.md) и [Справочник атрибутов](../guide/attribute-reference.md).
