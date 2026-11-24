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

## Exact exception signatures


```php
// ResolutionException
public function __construct(
    string $message,
    ?ParameterDiagnostic $parameter = null,
    ?PropertyDiagnostic $property = null,
    ?string $parameterName = null,
    ?int $parameterPosition = null,
    ?string $parameterType = null,
    ?string $parameterContext = null,
    ?string $propertyName = null,
    ?string $propertyClass = null,
    ?string $propertyType = null,
    ?string $serviceId = null,
    array $providedParameterTypes = [],
    array $resolvedParameterTypes = [],
    ?string $actualType = null,
    ?Throwable $previous = null,
);
public static function forParameter(
    ReflectionParameter $parameter,
    ?string $reason = null,
    array $providedParameters = [],
    array $resolvedParameters = [],
    ?Throwable $previous = null,
): self;
public static function forProperty(
    ReflectionProperty $property,
    ?string $reason = null,
    ?Throwable $previous = null,
): self;
public static function forService(string $id, Throwable $previous): self;
public static function forMissingService(string $id): self;
public static function forNonObject(string $id, string $actualType): self;
```


| Type | Constructor |
| --- | --- |
| `NotFoundException` | `__construct(string $id, string $message = '', ?Throwable $previous = null)` |
| `CircularDependencyException` | `__construct(array $chain, string $message = '', ?Throwable $previous = null)` |
| `DelegatorException` | `__construct(string $message, ?string $entryId = null, ?Throwable $previous = null)` |
| `InvalidCallableException` | `__construct(mixed $callable, string $message = '', ?Throwable $previous = null)` |
| `MutationRetirementException` | `__construct(?string $id, array $causes)` |
| `RequestDataConflictException` | `__construct(string\|int $key, string $existingSource, string $incomingSource, ?Throwable $previous = null)` |
| `RequestParameterSourceConflictException` | `__construct(string $dtoClass, string $key, string $source, ?string $parameter = null)` |
| `ParameterDiagnostic` | `__construct(string $name, int $position, ?string $type, string $context)` |
| `PropertyDiagnostic` | `__construct(string $name, string $class, ?string $type)` |

Exception classes without their own constructor inherit PHP's standard constructor. Additional configuration and callable exception factories:

```php
// InvalidConfigurationException
public static function forSelfReferencingAlias(string $alias): self;
public static function forInvalidDefinition(DefinitionInterface $definition): self;
public static function forUnsupportedDefinition(
    DefinitionInterface $definition,
    string $resolverClass,
): self;

// InvalidCallableException
public static function forValue(mixed $callable, ?Throwable $previous = null): self;
public static function forMethod(string $class, string $method): self;
public static function forNonInvokable(string $class): self;
public static function forMissingService(string $id, ?Throwable $previous = null): self;
```

See [Runtime behavior](../guide/runtime.md) and [Request mapping](../guide/request-mapping.md).
