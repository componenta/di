# Getting started

## Installation

The published release and the DI 6 development branch are different versions.

For the latest published version:

```bash
composer require componenta/di
```

To use the API documented here before the DI 6 release, select the development branch:

```bash
composer require 'componenta/di:dev-refactor/di6-definitions-callbacks'
```

DI 6 requires PHP 8.4+ and the dependencies declared in [composer.json](https://github.com/componenta/di/blob/refactor/di6-definitions-callbacks/composer.json). Native inline/reference callback expressions inside attributes require PHP 8.5.

## Core flow

```text
Environment + ordered providers
    -> ConfigFactory::create()
    -> ConfigComposition { Config, DependencyDefinitions }
    -> ContainerFactory::create()
    -> ContainerValue { container, the same Config }
```

`ContainerFactory` is the standard construction API for applications. It preserves the exact `Config` and `Environment` instances supplied by the application. Direct `new Container(...)` is also supported under the [standalone construction contract](../migrations/di6-definitions.md#direct-container-construction).

## Quick start

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
        return ['greeting.prefix' => 'Hello'];
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
$fresh = $container->make(Greeting::class, ['prefix' => 'Welcome']);

assert($shared->message === 'Hello!');
assert($fresh->message === 'Welcome!');
assert($containerValue->config === $composition->config);
assert($container->get(Environment::class) === $environment);
```

## Provider composition

`ConfigFactory` calls providers in argument order and merges their dependency sections before DI sees them. The relevant rules are:

- factories, aliases, services, and parameter resolvers are keyed registries; a later value replaces an earlier value with the same key;
- numeric invokables are appended, while keyed invokables are replaced by key;
- delegator pipelines, attribute definitions, and attribute capabilities are appended in provider order;
- resolver and attribute-definition replacement flags use the last explicitly supplied Boolean value.

DI validates and normalizes the merged `DependencyDefinitions` once during `ContainerFactory::create()`. It does not invoke providers and does not copy definitions into `Config`.

`ConfigProvider` exposes these protected hooks:

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
