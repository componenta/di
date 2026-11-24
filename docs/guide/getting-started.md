# Getting started

## Requirements and installation

Componenta DI requires PHP 8.5.13+ and installs through Composer:

```bash
composer require componenta/di
```

The package dependencies are listed in the repository's [composer.json](../../composer.json). The PHP 8.5.13 minimum includes the engine fix for scope and visibility of native first-class callable expressions in trait attributes.

## Create a container

Configuration providers are assembled by `ConfigFactory`. The result includes application `Config` and a separate `DependencyDefinitions` object. Pass both to `ContainerFactory`.

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
$context = (new ContainerFactory())->create(
    $composition->config,
    $composition->dependencies,
);
$container = $context->container;

$shared = $container->get(Greeting::class);
$fresh = $container->make(Greeting::class, ['prefix' => 'Welcome']);

assert($shared->message === 'Hello!');
assert($fresh->message === 'Welcome!');
assert($context->config === $composition->config);
assert($container->get(Environment::class) === $environment);
```

`get()` returns a shared result for a local entry; `make()` starts a separate object-creation attempt with optional parameters. The factory itself may still choose to return an existing object.

## Provider composition

`ConfigFactory` invokes providers in the order given, then passes the merged dependency sections to DI. Their merge rules are:

- factory, alias, service and parameter-resolver registries: later values replace earlier values for the same key;
- invokable classes: numeric entries are appended, keyed entries are replaced by key;
- delegator pipelines, attribute definitions and attribute capability policies: appended in provider order;
- resolver/attribute-definition replacement flags: the last explicitly supplied Boolean value wins.

`ContainerFactory::create()` validates and normalizes the resulting `DependencyDefinitions` during assembly. It does not invoke the configuration providers again.

`ConfigProvider` supplies these protected extension methods:

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

For definition types and their behavior, see [Dependency definitions](definitions.md).
