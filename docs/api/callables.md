# Callable resolution and invocation

The classes below are in `Componenta\DI`. The container exposes the high-level methods as `Container::call()` and `Container::resolve()`.

## Public contracts and implementations

| Type | Public API | Responsibilities |
| --- | --- | --- |
| `CallableResolverInterface` | `resolve(mixed $callable): callable` | Convert a callable specification into an invocable PHP value without executing it. |
| `CallableResolver` | `__construct(ContainerInterface $container)`; `resolve(mixed $callable): callable` | Resolve closures, functions, static/instance methods, service IDs and invokable objects. |
| `CallableInvokerInterface` | `call(mixed $callable, array $params = []): mixed` | Invoke a fully prepared callable with PHP's argument rules. |
| `CallableInvoker` | `call(mixed $callable, array $params = []): mixed` | Invoke without further dependency resolution, using native call semantics. |
| `CallableExecutorInterface` | Extends both interfaces above | Combined resolution and invocation contract. |
| `CallableExecutor` | `__construct(CallableResolverInterface $callableResolver, ParametersResolver $parameters)`; `resolve()`; `call()` | Resolve callable parameters through DI, then invoke the target. |
| `PreparedCallable` | `__construct(callable|array $callable)`; `__invoke(mixed ...$arguments): mixed`; public `$callable` | Adapt an already prepared callable so `CallableExecutor::call()` does not repeat parameter injection. |

## Supported callable inputs

`CallableResolver` accepts a native `Closure`, a function name, a `Class::method` string, an array `[$objectOrClass, 'method']`, a container service ID containing a callable, or an invokable object. For string input, an exact registered service ID is resolved **before** parsing the string as callable syntax.

Instance methods may be resolved through the container; already-valid non-string PHP callables retain native precedence. Methods provided through `__call()` receive native arguments and are not reflected for parameter injection. Invalid callable specifications cause `InvalidCallableException`.

```php
$result = $container->call(
    [Controller::class, 'show'],
    ['id' => 10],
);

$callable = $container->resolve('controller.handle');
```

A resolved callable is not invoked until application code calls it. `call()` can resolve missing typed arguments and DI parameter attributes, including simple union types with exactly one available candidate.

## Native invocation and pre-resolved parameters

`CallableInvoker` deliberately **does not autowire**. It accepts a complete argument list and can pass reference-bearing arrays to native PHP callables. By contrast, `PreparedCallable` forwards arguments by value and is unsuitable for by-reference parameters.

```php
use Componenta\DI\PreparedCallable;

$handler = static fn(int $id): int => $id * 2;
$prepared = new PreparedCallable($handler);
$result = $container->call($prepared, ['id' => 5]); // 10; no DI pass
```

For a `PreparedCallable`, expand variadic values before invoking it. Named arguments and PHP defaults otherwise follow native rules. An explicitly invoked target's own exception propagates unchanged once its body starts; errors in resolution and invalid callable specifications follow Componenta DI's exception contracts.

See [Container API](container.md), [Exceptions](exceptions.md), and [Attribute reference](../guide/attribute-reference.md).
