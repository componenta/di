# Entry resolution and object construction

These public types belong to `Componenta\DI\Resolver\Entry` (with setup unwrappers in its `SetUp` subnamespace). They make up the lower-level API used by the assembled container.

## Explicit definitions and fallback resolution

| Type | Public signatures | Purpose |
| --- | --- | --- |
| `DefinitionResolverInterface` | `supportsDefinition(DefinitionInterface $definition): bool`; `validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface`; `resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed` | Classify, validate and execute a supplied definition without storing registrations. |
| `EntryResolverInterface` | `can(string $id): bool`; `resolve(string $id, EntryParameters $params): mixed` | Fallback resolution of an entry identifier. |
| `CompositeResolver` | `__construct(EntryResolverInterface\|DefinitionResolverInterface ...$providers)`; `can(string $id): bool`; `resolve(string $id, array\|EntryParameters $params = []): mixed`; `setDefinition(string $id, DefinitionInterface $definition): void` | Combine explicit definitions with ordered fallbacks; own binding registrations. |
| `FactoryResolver` | `__construct(ContainerInterface $container, ProxyFactoryInterface $proxyFactory)`; methods of `DefinitionResolverInterface` | Execute factory and `ClassDefinition` descriptors. |
| `InvokableResolver` | Methods of `DefinitionResolverInterface`; no constructor arguments | Execute `InvokableDefinition` by a constructor call without supplied arguments. |
| `ReflectionResolver` | `__construct(ObjectPipeline $objects, array $excluded = [])`; `can(string $id): bool`; `resolve(string $id, array\|EntryParameters $params = []): object` | Construct eligible concrete classes not explicitly registered. |
| `EntryParametersFactoryInterface` | Extends `FactoryInterface`; `makeEntry(string $entry, EntryParameters $parameters): object` | Receive construction parameters with their source information. |
| `EntryParameters` | Readable `$values`; `without(string\|int $key): self`; `forwardTo(FactoryInterface $factory, string $entry): object` | Snapshot and forward arguments for one construction. |

`CompositeResolver` accepts providers in order. Explicit definition strategies are chosen according to their advertised support; rejecting a selected definition does not automatically retry a later supporting strategy. For attached container roots, register new entries through `Container::set()`, not by modifying an independently created composite.

`EntryParameters` is created through the DI construction pipeline; its inherited constructor is not a public application API. `without()` removes a key without changing the original object. `forwardTo()` preserves request-field provenance for a native container or a receiver implementing `EntryParametersFactoryInterface`; an ordinary factory receives application arguments through `make()`.

## Object creation context and helpers

| Type | Public signatures | Purpose |
| --- | --- | --- |
| `InstanceCreator` | `__construct(ParametersResolver $parameters)`; `create(ReflectionClass $class, array $params = []): object`; `initialize(object $entry, ReflectionClass $class, array $params = []): void` | Construct or initialize an object using the parameter resolver chain. |
| `ObjectCreationContext` | Properties and methods listed below | Per-attempt state passed to object attribute handlers; not directly constructible by application code. |

`ObjectCreationContext` provides readable `$class`, `$constructorEnabled`, `$strategy`, `$entry` and `$parameters`, plus these mutation/inspection operations:

```php
disableConstructor(): void;
selectStrategy(CreationStrategy $strategy): void;
claimProperty(ReflectionProperty $property, bool $allowPromoted = false): bool;
propertyClaimed(ReflectionProperty $property): bool;
readProperty(ReflectionProperty $property): mixed;
writeProperty(ReflectionProperty $property, mixed $value): void;
```

The context represents one construction attempt; the container controls its lifecycle. Attribute handlers must respect the selected phase, property ownership and constructor policy rather than retaining the context across resolutions.

## SetUp parameter unwrapping

All classes in `Componenta\DI\Resolver\Entry\SetUp` implement `SetUpValueUnwrapperInterface`:

```php
supports(mixed $value): bool;
unwrap(mixed $value, string $key, ?ReflectionParameter $parameter = null): mixed;
```

| Class | Constructor | Recognized input |
| --- | --- | --- |
| `ConfigUnwrapper` | `__construct(ContainerInterface $container)` | `#[Config]` descriptors in setup argument values. |
| `EnvUnwrapper` | `__construct(ContainerInterface $container)` | `#[Env]` descriptors. |
| `EntryIdUnwrapper` | `__construct(ContainerInterface $container)` | `#[EntryId]` descriptors. |
| `ContainerValueUnwrapper` | `__construct(ContainerValue $container)` | Componenta Config `ContainerEntry`, `ConfigEntry`, `EnvironmentEntry`, `LazyValue`. |
| `SetUpValueUnwrapperInterface` | Interface, no constructor | Common contract for recognizing and unwrapping setup values. |

`ContainerValueUnwrapper` also converts supported environment entries to the requested scalar or array type where the reflected parameter permits it. Unwrappers are used by the setup pipeline; they do not turn a `ClassDefinition` into an attribute-driven construction path.

See [Object pipeline](container.md), [Definitions](definitions.md) and [Attribute handlers](attribute-handlers.md).
