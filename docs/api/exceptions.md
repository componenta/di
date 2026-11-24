# Exceptions and diagnostics

Exception classes belong to `Componenta\DI\Exception`. `ExceptionInterface` extends both `Throwable` and PSR-11 `ContainerExceptionInterface`. Where DI wraps a foreign failure, the original is available via `getPrevious()`. Once the target of an explicit `call()` begins executing, the target's own exceptions pass through unchanged.

| Type | Meaning and public details |
| --- | --- |
| `ExceptionInterface` | Common marker contract. |
| `NotFoundException` | Requested container ID has no entry; `$id`, `forService(string $id, ?Throwable $previous = null)`. Implements PSR-11 `NotFoundExceptionInterface`. |
| `ResolutionException` | Service/parameter/property creation failed; `forService()`, `forMissingService()`, `forNonObject()`, `forParameter()`, `forProperty()` factory methods. |
| `CircularDependencyException` | Cyclic service or alias chain; readable `$chain`, `forService(array $chain)`, `forAlias(array $chain)`. |
| `ConcurrentResolutionException` | Another active execution is constructing an incompatible shared entry; `forService(string $id)`. |
| `InvalidConfigurationException` | Invalid definition or configuration; `forSelfReferencingAlias()`, `forInvalidDefinition()`, `forUnsupportedDefinition()`. |
| `AttributeCompositionException` | Incompatible attribute composition, subclass of `InvalidConfigurationException`. |
| `DelegatorException` | Delegator failure; readable `$entryId`, `forEntry(string $id, Throwable $previous)`. |
| `InvalidCallableException` | Invalid callable; `$callableType`, `$callableDescription`; `forValue()`, `forMethod()`, `forNonInvokable()`, `forMissingService()`. |
| `MutationRetirementException` | Mutation already committed but releasing replaced values failed; `$id` and ordered, nonempty `$causes`. Subclass of `InvalidConfigurationException`. |
| `RequestDataConflictException` | Different values for one request key from separate sources; `$key`, `$existingSource`, `$incomingSource`. |
| `RequestParameterSourceConflictException` | HTTP-mapped input conflicts with an explicitly sourced DTO parameter; `$dtoClass`, `$key`, `$source`, `$parameter`. |
| `ParameterDiagnostic` | Readonly snapshot of `$name`, `$position`, `$type`, `$context`. |
| `PropertyDiagnostic` | Readonly snapshot of `$name`, `$class`, `$type`. |

## ResolutionException fields

The public constructor accepts `$message`, optional `ParameterDiagnostic $parameter`, `PropertyDiagnostic $property`, and a `$previous` throwable. It also exposes detached scalar metadata: `parameterName`, `parameterPosition`, `parameterType`, `parameterContext`, `propertyName`, `propertyClass`, `propertyType`, `serviceId`, `providedParameterTypes`, `resolvedParameterTypes`, and `actualType`. These values do not retain the live request or target object.

```php
use Componenta\DI\Exception\{MutationRetirementException, ResolutionException};

try {
    $container->make(Service::class);
} catch (ResolutionException $error) {
    // Inspect $error->serviceId, $error->parameter, or getPrevious().
}

try {
    $container->set('service', new Service());
} catch (MutationRetirementException $error) {
    // The change is already committed; inspect $error->id and $error->causes.
}
```

Handle `MutationRetirementException` before a general `InvalidConfigurationException` catch. Failed validation before publication does not replace the old binding; independently committed nested changes are not rolled back.

See [Runtime behavior](../guide/runtime.md) and [Request mapping](../guide/request-mapping.md).
