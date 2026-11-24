# Runtime behavior and exceptions

## Sharing and cache boundaries

`get()` caches a local entry's resolved result, including its delegator chain, under the requested ID. Local cache entries can contain objects, scalars, `null` or `false`. External PSR-11 containers control their own sharing; the local cache and local delegators do not modify their results.

`make()` always follows a local creation path and requires an object result. It does not apply delegators or consume a shared `get()` result. Explicit application factories can still return the same object on multiple calls.

Reflection metadata, parameter plans and alias paths are cached in container memory. Binding mutations invalidate affected entries rather than rebuilding the entire container. The same construction and validation pipeline is used in development and production.

## Exceptions

Componenta-owned exceptions implement `Componenta\DI\Exception\ExceptionInterface`, which extends the PSR-11 container exception contract. Common types:

| Exception | Meaning |
| --- | --- |
| `NotFoundException` | The requested entry does not exist. |
| `ResolutionException` | Construction or dependency resolution failed. |
| `CircularDependencyException` | A circular resolution was detected. |
| `ConcurrentResolutionException` | Resolution conflicts with another active execution. |
| `InvalidConfigurationException` | Invalid registration or configuration. |
| `AttributeCompositionException` | Incompatible attribute composition. |
| `DelegatorException` | Delegator resolution or invocation failed. |
| `MutationRetirementException` | A mutation committed, but releasing replaced values failed. |

Errors from a selected factory or constructor are reported under the owning resolution boundary and may preserve the original exception in `getPrevious()`. An exception thrown by the body of an explicitly invoked callable propagates unchanged once invocation begins.

A failed registration that was rejected before publication leaves its previous binding intact. If releasing replaced values fails *after* a mutation commits, `MutationRetirementException` exposes the affected `id` and the ordered `causes` array; do not blindly retry the registration. Independently completed nested mutations are not rolled back.

See [Definition migration](../migrations/di6-definitions.md) for error-handling changes.
