# Extension points

Custom resolvers and attribute handlers are registered through configuration providers **before** `ContainerFactory::create()`. The parameter-resolver and attribute pipelines are not mutable after assembly.

## Parameter resolvers

Implement `ParameterResolverInterface`:

- `supports(ParameterTarget $target): bool` selects applicable parameters.
- `resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array` returns `[position, value]` or `null` to continue the chain.

For attributes that only need a value provider, use the built-in `AttributeMatchingParameterResolver`:

```php
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class TenantId {}

$resolver = new AttributeMatchingParameterResolver(
    TenantId::class,
    static fn($target, $context): array => [$target->position, 'tenant-1'],
);
```

Register `$resolver` with an integer priority in the provider's `getParameterResolvers()` hook. Extensions must not mutate the resolver chain during `supports()`.

## Attribute handlers

Register custom attribute behavior with `AttributeDefinition`. Its capabilities, `requires`, `forbids`, `before` and `after` selectors define composition constraints and handler order. `CapabilityPolicy` limits the number of attributes with a capability on one target.

Register required attribute definitions before the container is built. Their extension factories can resolve dependencies from the container, but a newly applicable extension discovered after a resolution has completed may be rejected to preserve the completed result's semantics.

## Definitions and external containers

Custom explicit-definition strategies implement `DefinitionResolverInterface`; fallback entry resolvers implement `EntryResolverInterface` and accept `EntryParameters`. Consult the [DI 6 migration guide](../migrations/di6-definitions.md) before updating their implementations.

`Container::addContainer()` adds an external PSR-11 container. It cannot override protected internal DI entries; external providers control the sharing of the values they return. See [Runtime behavior](runtime.md).
