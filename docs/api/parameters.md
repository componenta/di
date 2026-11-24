# Parameter resolution API

The public parameter-resolution types belong to `Componenta\DI\Resolver\Parameter` and `Componenta\DI\Resolver\Target`. They describe and resolve arguments for constructors, methods, setup hooks and DI-aware callable invocation.

## Resolver contract

```php
interface ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool;
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}
```

`supports()` classifies the target without mutating the chain. `resolveParameter()` returns `[parameter position, value]` or `null` to continue the chain. Expected failures should raise `ResolutionException`. For a variadic declaration, a resolver supplies an array representing the entire variadic argument list, not one value per element.

## ParametersResolver

```php
public function __construct(
    AttributePlanBuilder $plans,
    ?ParameterTargetFactory $targets = null,
);
public function add(ParameterResolverInterface $resolver, int $priority = 0): void;
public function seal(): void;
public function resolve(array $parameters, array $providedParameters = []): array;
public function targets(array $parameters): array;
public function resolveTargets(array $targets, array $providedParameters = []): array;
public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array;
public function target(ReflectionParameter $parameter): ParameterTarget;
```

The readable `$isSealed` property indicates whether registration is closed. Higher integer priorities are evaluated first; ties preserve insertion order. `add()` changes the resolver chain before `seal()`. `resolve()` accepts a list of reflected parameters; `targets()` prepares their reusable target descriptions; `resolveTargets()` runs those descriptions with supplied arguments. The standard `ContainerFactory` assembles and seals its own chain.

## Built-in resolvers

Each implements `ParameterResolverInterface` with `supports()` and `resolveParameter()`:

| Class | Constructor | Behavior |
| --- | --- | --- |
| `ArrayResolver` | No arguments | Match explicit arguments by parameter name first, then position. |
| `ArrayTypedResolver` | No arguments | Find an explicitly provided object keyed by its class/interface name, excluding request transport objects. |
| `AttributeParameterResolver` | `__construct(AttributePlanBuilder $plans)` | Execute the composed parameter-attribute handlers. |
| `AttributeMatchingParameterResolver` | `__construct(string $attribute, Closure $resolve)` | Match one attribute and invoke a callback for the value. |
| `AutowireByTypeResolver` | `__construct(ContainerInterface $container)` | Resolve class/interface types; a simple union is resolved only when one candidate is available. |
| `DefaultValueResolver` | No arguments | Supply a PHP default or an empty array for an unresolved variadic. |
| `NullableResolver` | No arguments | Supply `null` for an unresolved nullable, non-variadic parameter. |

`ParameterSourceAttributeInterface` is a marker for attributes that declare a parameter source; it has no methods.

## Context and target metadata

| Type | Public API | Meaning |
| --- | --- | --- |
| `ParameterResolutionContext` | `__construct(array $provided = [], array $resolved = [])`; readable `$provided`, `$resolved` | Snapshots of caller-supplied and already resolved argument values. |
| `ParameterAttributeValue` | `static unresolved(): self`, `static resolved(mixed $value): self`; readonly `$resolved`, `$value` | Whether an attribute-handler chain has supplied a value. |
| `ParameterTargetFactory` | `__construct()`, `create(ReflectionParameter $parameter): ParameterTarget` | Create a target description from PHP reflection. |
| `ParameterTarget` | `__construct(ReflectionParameter $reflection)`; methods listed below | Immutable description of a parameter's declaration and type. |

`ParameterTarget` exposes readable `reflection`, `defaultValue`, `attributeClasses`, `typeNames`, `className`, `name`, `position`, `type`, `allowsNull`, `hasDefault`, `variadic`, `byReference`, `declaringContext` and the methods:

```php
hasAttribute(string $attributeClass): bool;
firstAttribute(string $attributeClass): ?object;
watchAttributeAvailability(string $attributeClass): void;
accepts(mixed $value): bool;
unionCandidates(): array;
```

`watchAttributeAvailability()` lets a custom resolver record that its applicability depends on an attribute class that may be loaded later. `unionCandidates()` lists eligible class/interface candidates of a simple union; `accepts()` checks the full declared parameter type.

## TypeHints utility

`Componenta\DI\Resolver\TypeHints` provides static reflection helpers:

```php
classOf(?ReflectionType $type, ?ReflectionClass $declaringClass = null): ?string;
classNames(?ReflectionType $type, ?ReflectionClass $declaringClass = null): array;
simpleUnionClassNames(?ReflectionType $type, ?ReflectionClass $declaringClass = null): array;
matches(?ReflectionType $type, mixed $value, ?ReflectionClass $declaringClass = null): bool;
```

The helpers support class-name normalization and type checks, including unions and intersection matching. `ParameterTarget` is the preferred extension-facing description when the reflected parameter is already available.

See [Attribute handlers](attribute-handlers.md), [Callable invocation](callables.md), and [Custom extensions](../guide/extensions.md).
