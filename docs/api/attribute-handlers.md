# Attribute handler contracts and implementations

Attribute handlers belong to `Componenta\DI\Resolver\Attribute` and its `Handler` subnamespace. They connect a declared attribute's semantic `AttributeDefinition` to object construction or parameter resolution.

## Handler contracts

`AttributeHandlerInterface` provides:

```php
handle(
    object $attribute,
    Reflector $target,
    ObjectCreationContext $context,
): void;
```

It executes for supported object, property or lifecycle targets in the configured attribute phase. It may use the current `ObjectCreationContext` to control constructor policy, select an object creation strategy, or claim and write properties.

`ParameterAttributeHandlerInterface` provides:

```php
resolveParameter(
    object $attribute,
    ParameterTarget $target,
    ParameterResolutionContext $context,
    AttributePlan $plan,
    ParameterAttributeValue $value,
): ParameterAttributeValue;
```

It receives the current value state and returns a new resolved/unresolved state. A handler must not assume that a value has already been supplied. The first parameter of semantic transformation/setup callbacks has separate restrictions; consult [Callbacks](../migrations/di6-callbacks.md).

## Built-in handler classes

| Class in `Handler` | Constructor | Implements | Role |
| --- | --- | --- | --- |
| `CastHandler` | `__construct(ContainerInterface $container)` | Both | Apply the named `Cast` converter to a parameter or property. |
| `ConfigHandler` | `__construct(ContainerInterface $container)` | Both | Read `Config` values. |
| `EntryIdHandler` | `__construct(ContainerInterface $container)` | Both | Resolve an explicit service `EntryId`. |
| `EnvHandler` | `__construct(ContainerInterface $container)` | Both | Obtain `Env` values with supported type conversion. |
| `InitHandler` | `__construct(CallableInvokerInterface $callableInvoker)` | Object | Initialize a property via `Init`. |
| `InjectHandler` | `__construct(ContainerInterface $container)` | Object | Inject a property using `Inject`. |
| `LazyHandler` | No arguments | Object | Select lazy object creation when `Lazy` is present. |
| `MakeHandler` | `__construct(FactoryInterface $factory, ProxyFactoryInterface $proxyFactory, AttributePlanBuilder $plans)` | Both | Handle `Make` and supported proxy injection paths. |
| `NoConstructorHandler` | No arguments | Object | Disable the constructor when `NoConstructor` is present. |
| `RequestAttributeHandler` | Constructor below | Parameter | Resolve PSR-7 request sources, map data and construct DTOs. |

"Both" means implementing both `AttributeHandlerInterface` and `ParameterAttributeHandlerInterface`. All parameter implementations expose `resolveParameter()`; object implementations expose `handle()`. `CastHandler::resolveParameter()` has an additional optional `?Closure $beforeConsumption = null` argument, supporting validation of late input-policy changes before consuming a value.

`RequestAttributeHandler` constructor:

```php
public function __construct(
    FactoryInterface $factory,
    CasterProviderInterface $casterProvider,
    ?ValidationProviderInterface $validationProvider = null,
    ?Closure $callbacks = null,
);
```

The optional callback binder is required when a custom standalone setup uses `MapRequest(afterMap: ...)`; `ContainerFactory` supplies the standard binder. A custom handler must preserve the request-mapping source rules and should be registered through an `AttributeDefinition`.

## Processing and composition

`AttributeProcessor` (in `Resolver\Attribute`) has `__construct(AttributePlanBuilder $plans)`, `prepare(ReflectionClass $class): void`, and `hasHandlers(ReflectionClass $class): bool`. Composition is governed by the registry, phases and capabilities described in [Attribute composition](attribute-composition.md).

For a custom handler, declare its attribute's `AttributeDefinition` in a configuration provider before constructing the container. Late-discovered incompatible metadata can fail resolution rather than replay completed handlers. See [Parameter API](parameters.md) and [Object context](entry-resolvers.md).
