# Начало работы

## Требования и установка

Для работы библиотеки нужен PHP 8.4 или новее. Установка выполняется через Composer:

```bash
composer require componenta/di
```

Зависимости перечислены в файле [composer.json](../../../composer.json). PHP 8.5 необходим только для записи нативных замыканий и ссылок на функции непосредственно в аргументах атрибутов.

## Создание контейнера

`ConfigFactory` объединяет данные поставщиков конфигурации. Результат содержит настройки приложения в `Config` и отдельные определения зависимостей в `DependencyDefinitions`. Оба объекта передаются в `ContainerFactory`.

```php
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigProvider;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;

final readonly class Greeting
{
    public function __construct(public string $message) {}
}

final class AppConfigProvider extends ConfigProvider
{
    protected function getConfig(): array
    {
        return ['greeting.prefix' => 'Привет'];
    }

    protected function getFactories(): array
    {
        return [
            Greeting::class => static fn(
                ContainerValue $context,
                array $params,
            ): Greeting => new Greeting(
                ($params['prefix'] ?? $context->config->string('greeting.prefix')) . '!',
            ),
        ];
    }
}

$environment = new Environment([]);
$composition = (new ConfigFactory())->create(
    $environment,
    new AppConfigProvider(),
);
$context = (new ContainerFactory())->create(
    $composition->config,
    $composition->dependencies,
);
$container = $context->container;

$shared = $container->get(Greeting::class);
$fresh = $container->make(Greeting::class, ['prefix' => 'Добро пожаловать']);

assert($shared->message === 'Привет!');
assert($fresh->message === 'Добро пожаловать!');
assert($context->config === $composition->config);
assert($container->get(Environment::class) === $environment);
```

Метод `get()` возвращает общий результат для собственной записи контейнера. Метод `make()` начинает отдельную попытку создания объекта и позволяет передать дополнительные аргументы. Сама фабрика при этом вправе вернуть ранее созданный объект.

## Объединение конфигурации

`ConfigFactory` вызывает поставщиков в указанном порядке. При объединении определений действуют следующие правила:

- фабрики, псевдонимы, готовые службы и обработчики параметров объединяются по ключам; более поздняя запись заменяет предыдущую;
- классы с созданием без внедрения: элементы с числовыми ключами добавляются, а с именованными ключами заменяются;
- цепочки обработчиков результата, определения атрибутов и ограничения их сочетаний добавляются в порядке поставщиков;
- для признаков полной замены обработчиков параметров и определений атрибутов действует последнее явно заданное логическое значение.

`ContainerFactory::create()` проверяет и нормализует объединённый `DependencyDefinitions` при сборке контейнера. Повторного вызова поставщиков конфигурации не происходит.

Класс `ConfigProvider` предоставляет защищённые методы для настройки:

```text
getConfig()
getFactories()
getServices()
getInvokables()
getAliases()
getDelegators()
getParameterResolvers()
shouldReplaceParameterResolvers()
getAttributeDefinitions()
shouldReplaceAttributeDefinitions()
getAttributeCapabilities()
```

Способы объявления зависимостей описаны в разделе [«Определения зависимостей»](definitions.md).
