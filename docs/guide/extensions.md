# Extending the container

Register parameter resolvers and attribute definitions through configuration providers before calling `ContainerFactory::create()`. The parameter and attribute pipelines are sealed after assembly.

## Custom parameter resolvers

A `ParameterResolverInterface` implementation provides two methods:

- `supports(ParameterTarget $target): bool` decides whether the resolver can handle the parameter.
- `resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array` returns `[position, value]` or `null` to continue the resolver chain.

For one attribute-based value source, use `AttributeMatchingParameterResolver`:

```php
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class TenantId {}

$resolver = new AttributeMatchingParameterResolver(
    TenantId::class,
    static fn($target, $context): array => [$target->position, 'tenant-1'],
);
```

Register `$resolver` under an integer priority in `ConfigProvider::getParameterResolvers()`. `supports()` must not mutate the resolver chain or retain per-invocation resolution state.

## Attribute definitions and composition

`AttributeDefinition` describes an attribute class and its optional handler. Its `capabilities`, `requires`, `forbids`, `before` and `after` metadata controls compatibility and execution order. `CapabilityPolicy` limits the count of a capability on a target.

Attribute and parameter-resolver factories may obtain their dependencies from the container while it is being assembled. Required extension classes must be available in time for their use; late discovery of incompatible behavior can fail validation rather than replay an already completed operation.

`ContainerFactory::createWithTargetedAttributeDiscovery($config, $dependencies)` is an alternative construction method that restricts autoload probes to attributes declared on the current target. The normal `create()` method remains the default.

## Definition strategies and external containers

A custom explicit-definition strategy implements `DefinitionResolverInterface`: classify, validate, then execute a supplied definition without retaining its own registration map. A fallback entry resolver implements `EntryResolverInterface` and receives `EntryParameters`. These are low-level extension contracts for explicit assembly; the standard `ContainerFactory` does not accept arbitrary entry strategies through a configuration-provider section. See [Entry resolution](../api/entry-resolvers.md) for the contracts.

`Container::addContainer()` registers another PSR-11 container. External entries cannot shadow protected internal services; the external provider owns sharing of its results. If its registered entries subsequently change, `notifyExternalEntriesChanged()` can report affected IDs. See [Container API](container.md).
