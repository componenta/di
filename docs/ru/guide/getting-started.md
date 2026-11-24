# Начало работы

## Установка

Опубликованная версия и ветка разработки DI 6 — разные версии.

Для последнего опубликованного релиза:

```bash
composer require componenta/di
```

Чтобы до релиза использовать API из этих руководств, укажите ветку разработки:

```bash
composer require 'componenta/di:dev-refactor/di6-definitions-callbacks'
```

DI 6 требует PHP 8.4+ и зависимости из [composer.json](https://github.com/componenta/di/blob/refactor/di6-definitions-callbacks/composer.json). Для нативных inline/reference callback-выражений внутри атрибутов нужен PHP 8.5.

## Основной поток

```text
Environment + упорядоченные провайдеры
    -> ConfigFactory::create()
    -> ConfigComposition { Config, DependencyDefinitions }
    -> ContainerFactory::create()
    -> ContainerValue { container, тот же Config }
```

`ContainerFactory` — основной способ создания контейнера в приложении. Она сохраняет переданные экземпляры `Config` и `Environment` без копирования. Прямой `new Container(...)` также поддерживается через [контракт самостоятельной сборки](../../migrations/di6-definitions.ru.md#прямое-создание-контейнера).

## Быстрый старт

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
$containerValue = (new ContainerFactory())->create(
    $composition->config,
    $composition->dependencies,
);
$container = $containerValue->container;

$shared = $container->get(Greeting::class);
$fresh = $container->make(Greeting::class, ['prefix' => 'Добро пожаловать']);

assert($shared->message === 'Привет!');
assert($fresh->message === 'Добро пожаловать!');
assert($containerValue->config === $composition->config);
assert($container->get(Environment::class) === $environment);
```

## Слияние провайдеров

`ConfigFactory` вызывает провайдеры в порядке аргументов и объединяет секции зависимостей до передачи результата в DI:

- factories, aliases, services и parameter resolvers являются реестрами по ключу; позднее значение заменяет раннее с тем же ключом;
- numeric invokables дописываются, а keyed invokables заменяются по ключу;
- цепочки delegators, attribute definitions и attribute capabilities дописываются в порядке провайдеров;
- флаги замены resolver-ов и определений атрибутов используют последнее явно переданное логическое значение.

DI один раз проверяет и нормализует объединённый `DependencyDefinitions` внутри `ContainerFactory::create()`. Пакет не вызывает провайдеры и не копирует определения зависимостей в `Config`.

`ConfigProvider` предоставляет защищённые методы:

```text
getConfig()
getFactories()
getInvokables()
getAliases()
getDelegators()
getServices()
getParameterResolvers()
shouldReplaceParameterResolvers()
getAttributeDefinitions()
shouldReplaceAttributeDefinitions()
getAttributeCapabilities()
```
