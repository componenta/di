# Container API

`Container` implements `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface` and `ProxyFactoryInterface`.

## Lookup and object creation

```php
$shared = $container->get(Service::class);
$known = $container->has(Service::class);
$created = $container->make(Service::class, ['id' => 42]);
```

- `get(string $id): mixed` returns the shared result of a local entry, including its delegators; a registered external container controls its own values and sharing.
- `has(string $id): bool` tests whether an entry is known. It can return `true` even if resolution would fail.
- `make(string $entry, array $params = []): object` creates through a local definition or fallback resolver, bypassing shared values, external containers and delegators. Its result must be an object. Factories may deliberately return an existing instance.

Without an explicit binding, a concrete class can be created using reflection and constructor injection. Explicit bindings take precedence.

## Invoking callables

```php
$result = $container->call([$controller, 'show'], ['id' => 42]);
$callable = $container->resolve([Controller::class, 'show']);
```

`call(mixed $callable, array $params = []): mixed` resolves callable dependencies and invokes the target. `resolve(mixed $callable): callable` resolves a callable specification without invoking it. String `Class::method` specifications and callable arrays can use instance `__call()`; magic methods use native argument binding rather than reflected parameter injection. An exact string service ID takes precedence over callable syntax.

For arguments already resolved by a different pipeline, `PreparedCallable` can pass a final argument list to `call()` without another DI-resolution pass. It forwards values rather than by-reference parameters. Native reference arguments require direct use of `CallableInvoker`.

## Variadic parameters

Reflection constructors, `call()` and `SetUp` support variadics:

```php
$join = static fn(string ...$parts): string => implode('-', $parts);

$container->call($join, ['one', 'two']);             // 'one-two'
$container->call($join, ['parts' => ['one', 'two']]); // 'one-two'
$container->call($join);                            // ''
```

The collection supplied under the variadic parameter name overrides positional tail values, even when empty. Otherwise, integer keys from the variadic position onward form the tail. Every element is checked against the declared element type. Non-positional unrelated keys are not appended; unresolved variadics use an empty collection. The elements are not implicitly autowired. By-reference parameter injection is unsupported.

Optional arguments of built-in PHP functions may be omitted rather than replaced with an explicit `null` when native defaults apply.

## Runtime configuration

```php
$container->set('feature.enabled', false);
$container->alias(CacheInterface::class, RedisCache::class);
$container->delegator(Service::class, static fn($service) => $service);
$container->addContainer($externalPsrContainer);
```

`set()`, `alias()` and `delegator()` update local bindings and invalidate affected shared entries without rebuilding the container. `addContainer()` registers another PSR-11 provider; protected core IDs cannot be replaced or shadowed.

After an already-registered external provider changes its entries, it can report affected identifiers with `notifyExternalEntriesChanged($provider, ...$ids)`. This updates external lookup state and affected dependent delegators; the provider publishes its own changes first.

An in-progress resolution may return an earlier value if a binding changes while it is executing. Later lookups observe the new binding; an older resolution cannot restore invalidated cache entries.

See [Runtime behavior and exceptions](runtime.md) for error and lifetime contracts.
