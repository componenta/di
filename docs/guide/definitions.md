# Dependency definitions

## Services

Services are runtime values and retain their state and identity. Objects, closures, resources, `null`, and `false` are valid values.

```php
protected function getServices(): array
{
    return [
        ClockInterface::class => new SystemClock(),
        'feature.enabled' => false,
    ];
}
```

## Factories

The complete runtime factory ABI is `(ContainerValue $context, array $params)`. A callable may declare a compatible prefix of these arguments. Known direct non-string callables are checked when their definitions are registered, including during container construction. Strings and unresolved service-method specifications are resolved and validated when the factory is requested; registration does not instantiate a deferred factory owner.

```php
protected function getFactories(): array
{
    return [
        Client::class => static fn(ContainerValue $context, array $params): Client =>
            new Client(
                $context->config->string('api.endpoint'),
                $params['timeout'] ?? 10,
            ),
    ];
}
```

A factory owns the complete creation of its result. The container does not apply that result's DI attributes after the factory returns it.

`ClassDefinition` is a declarative factory. It invokes a public constructor immediately and then executes explicit `call()` instructions in registration order, including repeated calls to the same method:

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

$definition = ClassDefinition::create(Client::class)
    ->constructor(['endpoint' => 'https://api.example.test', 'timeout' => 10])
    ->call('setLogger', [Definition::reference(LoggerInterface::class)]);
```

By default, arguments come only from the definition, constructor overrides supplied to `make()`, and PHP parameter defaults. `make()` overrides match constructor parameter names or positions; a name takes precedence over a position. Type-name keys are not constructor arguments. Replaced references are not resolved, and runtime values remain literal. `ReferenceDefinition` looks up a dependency through the existing container; that dependency follows its own registration and lifecycle.

Nested configured argument arrays resolve `ReferenceDefinition` values recursively. A cyclic array in `constructor()` or `call()` throws `InvalidConfigurationException`. Reusing the same non-cyclic array in separate arguments is supported. Runtime overrides and values returned by references remain literal.

Call `autowire()` or `autowire(true)` to enable type-based DI fallback for missing constructor and method arguments. Explicit arguments, including `null`, take precedence. If no service is available for the declared class/interface type, PHP defaults still apply; a required argument without a value fails. `autowire(false)` disables this fallback. The flag does not enable parameter attributes or custom parameter resolvers.

`ClassDefinition` never executes DI attributes on its target class, properties, constructor parameters, or configured methods. This includes `SetUp`, `Inject`, `Lazy`, `Proxy`, and `NoConstructor`, even when autowiring fallback is enabled. A failed constructor or explicit method call aborts creation; the failed result is not shared. A later `get()` or `make()` starts a new creation attempt.

Unused arguments explicitly configured in `constructor()` or a reflected non-variadic `call()` throw `InvalidConfigurationException` when resolved, including unknown names and extra positions. Unrelated runtime keys passed to `make()` remain ignored for fixed signatures. Explicit variadic definitions and magic method calls retain native argument binding.

`constructor()`, `call()`, and `autowire()` return new immutable definitions. `call()` replaces the former `method()` API. `DefinitionInterface` is a marker; it imposes no shared `value` property on concrete definitions.

## Invokables and aliases

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
    return [
        CacheInterface::class => RedisCache::class,
    ];
}
```

A keyed invokable registers both the concrete class and an alias. Invokables use a direct zero-argument constructor call, including PHP defaults, without processing DI attributes. A constructor failure preserves an existing Componenta exception, including the original circular-dependency diagnostic; a foreign failure becomes `ResolutionException` with the original throwable as its previous exception. Failed creation is not shared, and a later resolution can retry.

## Delegators

Delegators receive the current entry and may optionally accept the container. They run in provider and registration order.

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

A deferred service-method pair such as `['tracing.decorator', 'decorate']` must be nested inside the pipeline list. This distinguishes it from a pipeline containing two string delegators.
