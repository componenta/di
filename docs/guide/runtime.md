# Runtime behavior and exceptions

## Runtime behavior

For local entries, `get()` caches a shared result, including the result of the requested ID's delegator pipeline. An external container that owns the requested ID supplies each `get()` result directly and controls its sharing; local result caches and delegators are not applied to that result.

`make()` resolves the local definition or fallback strategy on every call and accepts runtime parameters. It bypasses shared values, external containers and delegators, and requires an object result. A factory still controls the identity of its product and may return an existing object.

Reflection metadata, prepared parameter plans and alias paths are cached only in memory inside one container. Resolver selection for entries without an explicit definition checks current availability on each lookup, including the current state of composition rules.

Development and production use the same `ContainerFactory`, normalization, validation, and resolver chain. Runtime dependency values are never exported, so non-serializable values have the same behavior in both environments.

## Exceptions

Package-owned failures implement `Componenta\DI\Exception\ExceptionInterface`, which also extends the PSR-11 container exception contract. Common concrete exceptions include `InvalidConfigurationException`, `NotFoundException`, `ResolutionException`, `CircularDependencyException`, `ConcurrentResolutionException`, `DelegatorException`, and `AttributeCompositionException`.

If definition or delegator registration fails during validation or autoloading, `set()` and `delegator()` preserve an existing Componenta exception or wrap a foreign failure in `InvalidConfigurationException`, with the original throwable in `getPrevious()`. A rejection before publication leaves the previous binding and delegator pipeline intact; independently completed mutations from application code are not rolled back. After publication, all affected caches and callable chains are invalidated before retired roots are released. Synchronously delivered retirement failures become `MutationRetirementException`, whose readonly `id` and ordered `causes` identify a committed mutation; `getPrevious()` is the first cause. A propagated exception may originate from an independently committed nested mutation. See the [migration guide](../migrations/di6-definitions.md) for recovery and lifetime boundaries.

After DI resolves an explicitly invoked callable and enters its body, exceptions thrown by that callable propagate unchanged.
