# Container API

`Container` implements `Psr\Container\ContainerInterface`, `FactoryInterface`, `CallableExecutorInterface`, and `ProxyFactoryInterface`.

```php
$shared = $container->get(Service::class);                 // shared result
$exists = $container->has(Service::class);                 // known entry
$fresh = $container->make(Service::class, ['id' => 42]);   // object from the construction pipeline
$result = $container->call([$controller, 'show'], ['id' => 42]);
```

## Callable execution

The standard `CallableExecutor` captures the top-level values used for DI arguments after selecting the callable, before classifying and resolving its parameters. Changes to referenced variables during callable selection, such as autoloading the class or resolving a callable service, are visible to that invocation.

When a pipeline has already resolved arguments, pass a PreparedCallable adapter to call() with the final native argument list. This skips further DI injection and attribute processing for that invocation. Named arguments and PHP defaults keep their native behavior; variadic values must already be expanded. The adapter exposes the original callable for intercepting executors. Executor decorators should forward it intact, along with the arguments. Ordinary subsequent calls resolve their arguments normally.

The prepared adapter forwards arguments by value and does not support by-reference parameters. For a native callable that requires references, pass the original callable directly to `CallableInvoker` with an argument array containing those references.

Reflection autowiring is used for a concrete class when no explicit binding exists. An explicit factory takes precedence.

`has()` returns `true` for a known entry whose configuration would fail during resolution. `get()` reports that failure. If a provider fails while checking whether it owns an identifier, both methods report the failure as a PSR-11 container exception. Autowiring can still use an optional parameter's default when the container cannot resolve its type.

Reflection-based constructor injection, `call()`, and `SetUp` support variadic parameters without a separate context argument:

```php
$join = static fn(string ...$parts): string => implode('-', $parts);

$container->call($join, ['one', 'two']);              // 'one-two'
$container->call($join, ['parts' => ['one', 'two']]); // 'one-two'
$container->call($join);                            // ''
```

An array under the variadic parameter name supplies the entire collection and takes precedence over positional values, including when empty. Otherwise, integer keys at or after the variadic declaration position supply the tail in numeric position order. Earlier parameters keep their usual name/position binding, autowiring, and defaults. Other string keys, including request transport and objects keyed by type, are not appended to the tail. No implicit type autowiring occurs for variadic elements.

Each element must satisfy the declared type; for `array ...$items`, each element is itself an array. String keys inside an explicitly supplied collection become named variadic arguments. They cannot overwrite preceding parameters, and positional elements must precede named ones. Invalid collections throw `ResolutionException`. By-reference parameters remain unsupported.

Parameter resolvers and attribute sources may supply a variadic collection. Attribute handlers and casters run once on the whole collection; the final elements are validated before invocation. An unresolved variadic uses an empty collection. The native invocation expands the collection while resolver state retains one value for the declaration.

For internal PHP functions and methods, unsupplied optional arguments are left to PHP when resolution reaches the built-in defaults. Omission is distinct from an explicit `null`: `call('array_keys', ['array' => $data])` returns all keys. Explicit values and higher-priority custom resolvers still take precedence.

`#[Env]` on a variadic parameter reads an array for the whole collection, then validates its elements against the declared type. The same applies to environment descriptors in `SetUp`; element types are not inferred by converting the collection to one scalar.

String `Class::method` specifications and `[Class::class, 'method']` both support instance `__call()`. Magic methods receive the supplied arguments using native binding, without reflection-based parameter injection. An exact string service ID retains precedence over callable syntax.

The runtime mutation methods `set()`, `alias()`, `delegator()`, and `addContainer()` remain available. They invalidate affected shared entries and delegator results without rebuilding the whole container. Core DI services, `Config`, `Environment`, `ContainerValue`, and `DependencyDefinitions` cannot be replaced or shadowed.

If a factory or delegator changes a binding while an earlier `get()` is running (including a suspended Fiber), that call may finish with its earlier value. Later lookups observe the changed binding; the in-flight result cannot restore invalidated cache entries.
