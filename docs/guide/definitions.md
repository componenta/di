# Dependency definitions

The container accepts ready-to-use values, factories, class definitions and invokable classes. When no explicit binding exists, it can construct a concrete class using reflection.

## Services

`ConfigProvider::getServices()` supplies existing values. Objects keep their identity; closures, resources, `null` and `false` are also valid.

```php
protected function getServices(): array
{
    return [
        ClockInterface::class => new SystemClock(),
        'feature.enabled' => false,
    ];
}
```

A local `get()` result is shared under the requested identifier. Use `has()` to check whether an entry is known, not whether its construction is guaranteed to succeed.

## Factories

Factory functions receive a compatible prefix of `(ContainerValue $context, array $params)`; zero, one or two declared arguments are supported. The context provides the container and configuration. Additional arguments supplied to `make()` are in `$params`.

```php
use Componenta\Config\ContainerValue;

protected function getFactories(): array
{
    return [
        Client::class => static fn(
            ContainerValue $context,
            array $params,
        ): Client => new Client(
            $context->config->string('api.endpoint'),
            $params['timeout'] ?? 10,
        ),
    ];
}
```

A factory is responsible for creating its result. The container does not subsequently apply DI attributes to it. In `getFactories()`, you can also specify a service identifier whose value is a callable, a deferred pair `['factory.service', 'create']`, or an object implementing `LazyServiceFactoryInterface`. See the [factory contracts](../api/definitions.md) and [factory-owned laziness](../api/lazy.md). `Definition::factory()` specifically accepts a valid PHP callable; use the configuration section for unresolved service specifications.

## Declarative class definitions

`ClassDefinition` calls the public constructor with configured arguments, then executes methods in declaration order. It supports nested service references and optional type-based fallback.

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

$definition = ClassDefinition::create(Client::class)
    ->constructor(['endpoint' => 'https://api.example.test'])
    ->call('setLogger', [Definition::reference(LoggerInterface::class)])
    ->autowire();
```

`constructor()`, `call()` and `autowire()` return new definitions, leaving the original unchanged. Repeated `call()` instructions are preserved.

By default, only explicitly configured arguments, runtime constructor overrides and PHP defaults are used. `autowire()` enables resolution by class/interface type for *missing* arguments; explicit `null` still takes precedence. It does not enable parameter attributes or custom parameter resolvers. Neither the class, constructor parameters nor its properties/methods have their DI attributes executed by `ClassDefinition`.

`Definition::reference()` performs a container lookup when its configured argument is resolved; nested configured arrays are processed recursively. Runtime overrides remain literal. Unknown configured arguments in fixed constructor/method signatures are rejected. Cyclic configured arrays are rejected with `InvalidConfigurationException`.

Explicit definitions may also be registered at runtime:

```php
use Componenta\DI\Definition\Definition;

$container->set('answer', 42);
$container->set(Service::class, Definition::factory(static fn(): Service => new Service()));
$container->set(Utility::class, Definition::invokable(Utility::class));
```

`Definition::factory()` creates a factory descriptor; `Definition::invokable()` creates a descriptor for zero-argument construction. Registration is performed by `set()` or a configuration provider.

## Invokable classes and aliases

```php
protected function getInvokables(): array
{
    return [
        Logger::class,
        LoggerInterface::class => Logger::class,
    ];
}

protected function getAliases(): array
{
    return [CacheInterface::class => RedisCache::class];
}
```

An invokable class is created using a constructor with no supplied arguments (PHP defaults still apply). It does not use attribute injection. A keyed invokable adds an alias for its concrete class; `getAliases()` creates aliases independently. Failed construction is not cached as a successful service.

## Delegators

Delegators transform a resolved local entry. They receive the current value and can optionally accept the container. Their order follows configuration-provider and registration order.

```php
protected function getDelegators(): array
{
    return [
        Client::class => [
            static fn(Client $client): Client => $client->withMetrics(),
            ['tracing.decorator', 'decorate'],
        ],
    ];
}
```

A deferred `[service ID, method]` callable must be nested inside the delegator list. This differs from a list containing two independent string callables. Delegators affect shared local `get()` results; `make()` does not apply them.

See [Container API](container.md) for lookup and mutation semantics.
