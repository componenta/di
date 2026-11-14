# DI 6: PHP 8.5 callbacks and deterministic union autowiring

[Русская версия](php85-callbacks.ru.md)

Created: 2026-11-12. Rechecked: 2026-11-13 against `71a843082e7850672bc1fef87401e5697d433f66`, after a fresh review of the released DI 5.0.5 baseline (`a56f3d70dd56bdd62947b6c322aa878e8a76ef4c`). The earlier review and [November 13 correctness fixes](../audit/2026-11-13-di-audit.md) remain historical evidence. The new [native evidence](../audit/2026-11-13-php85-native-evidence.json) records the exact source revision, binary versions, fixture sources and results.

**Implementation status (2026-11-14):** native `Init` controls, the shared callback binder, `Transform`, callback `SetUp` and simple-union autowiring are implemented and locally verified on `refactor/di6-definitions-callbacks`. See the [migration guide](../migrations/di6-callbacks.md) and [implementation evidence](../audit/2026-11-14-di6-refactoring.md). The final DI 6 release, affected native trait-callable support promise and minimum PHP patch remain blocked by E1 in §8. Request-to-DTO callbacks remain separately deferred by §11.

The compatible v5.0.6 work stays on the 5.x line with PHP `^8.4`. This major branch does not change that release. Its source remains parseable on PHP 8.4 while platform packaging is pending; this does not settle the final DI 6 support floor. Existing `Init` keeps its public API and accepts supported native expressions on PHP 8.5. Baseline descriptions below refer to the audited 5.x implementation.

## 1. Goal and recovered intent

Allow local, typed behavior to be written directly in PHP attributes while retaining one parameter-resolution pipeline, one object lifecycle, and native PHP callback scope. Add deterministic autowiring of simple union types as an independent DI 6 feature.

The recovered design discussions from August 13 and September 24–25, 2026, and the document `componenta-php85-callbacks-version-plan.md` establish this intent:

- `SetUp` may receive a callback whose first argument is the constructed instance; DI supplies further dependencies.
- A repeatable `Transform` attribute handles value transformation without adding a callback option to every source attribute.
- Attribute-facing callbacks use `Closure`; existing runtime callable representations remain supported.
- Existing public attribute constructor argument names and ordering remain compatible. Optional attribute arguments, if needed elsewhere, go last; callers must not supply placeholder `null` arguments. Infrastructure constructor migrations in this major are specified separately.
- DI 6 moves to PHP 8.5. Union resolution must choose exactly one available service type and reject ambiguity.
- `Init` already accepts a callable. Request-to-DTO transformation is a separate possible follow-up.

This specification updates those notes against the current repository. The old notes contain `static fn` attribute examples that PHP 8.5 cannot compile. They also predate removal of the compiled-factory/persistent-graph design. Neither those examples nor that architecture should be carried forward.

### Scope

In scope: `Transform`, callback `SetUp`, PHP 8.5 regression coverage for existing `Init`, simple union autowiring, diagnostics, lifecycle and memory contracts, documentation, and migration guidance.

Out of scope: compiled containers, closure serialization, source-code extraction, new factory registries, global resolver-priority changes, automatic intersection/DNF service discovery, a callback parameter on every existing attribute, and changes to unrelated Componenta packages. Attribute availability polling has its own [performance plan](attribute-availability-performance.md).

### Necessity and compatibility by feature

The recovered DI 6 bundle remains the intended delivery target. It does not make every feature technically dependent on PHP 8.5 or a major version:

| Feature | Actual compatibility boundary | Chosen disposition |
| --- | --- | --- |
| Existing `Init` | No public API change. PHP 8.5 inline/static-reference expressions already work through the current callable path; existing string/array/invokable inputs remain valid. | Add native syntax, scope and lifetime proof and examples without redesigning `Init`. These additions can be compatible with 5.x. |
| New `Transform` | An additive attribute and handler can use PHP 8.4-compatible implementation syntax. `Closure` as an attribute value does not itself require PHP 8.5; the new inline/reference expressions do. | Keep it in the planned DI 6 bundle, but recognize that it is independently implementable and potentially suitable for a minor release if delivery policy changes explicitly. |
| Callback `SetUp` | Widening a non-final readonly attribute's public `$method` property breaks subclasses that redeclare `string $method`, because PHP properties are invariant. | A real major-version change. Prefer the existing `SetUp` lifecycle owner and preserved named argument over another public setup attribute. |
| Simple-union autowiring | PHP 8.5 adds no required language capability here. Selecting a service where the old path used a default/null, or introducing ambiguity errors, changes resolution behavior. | Keep this independent behavioral change in DI 6. It is not required by the callback binder. |
| Request-to-DTO callback | Its input/output and provenance contract is not settled. | Defer independently under §11; it does not block the other features. |
| PHP `^8.5` target | A recovered packaging decision, distinct from implementation syntax and from the minimum fixed engine patch. | Retain DI 6 intent; settle the actual supported patch floor at E1 before release. |

A separate `SetUpCallback` attribute could avoid changing the existing property in a minor release, but it would duplicate the public representation of the same lifecycle operation. An extra callback argument requiring a placeholder method would also violate the recovered API intent. With a major release already intended, widening `SetUp` is the smaller coherent public API. Do not narrow `Init` or general runtime callable APIs merely to make their signatures look uniform.

The native fixture `runtime_constant_on_php84` proves that an already-created closure in a runtime constant can be passed to a `Closure`-typed attribute constructor on PHP 8.4.23 and 8.5.8. This is evidence about API/platform separation, not a recommendation to introduce global constants into application configuration.

## 2. Existing ownership and the required extension

| Responsibility | Current owner | DI 6 decision |
| --- | --- | --- |
| Build and seal the container | `ContainerFactory` | Wire the new handler and narrow internal callback helper here. |
| Compose attribute capabilities and order | `AttributePlanBuilder`, `AttributeDefinitionRegistry` | Reuse; register `Transform` as `ValueTransformer` after providers. |
| Run parameter handlers | `AttributeParameterResolver` | Reuse the existing ordered chain. |
| Resolve and validate parameters | `ParametersResolver` | Add an internal fixed-first-argument entry point sharing the existing resolution loop. |
| Guard mapped request sources | `ParameterResolutionBoundary`, `MappedRequestParameterSourceGuard` | Carry an internal setup owner for classless callback parameters; retain the ordinary call path. |
| Invoke already resolved arguments | `PreparedCallable`, `CallableInvokerInterface`, `CallableExecutor` | Reuse the prepared-call contract, including decorators. |
| Stage property values | `ObjectCreationContext::resolveProperty/readProperty/writeProperty` | Reuse; no separate write or type-validation engine. |
| Execute lifecycle hooks | `SetUpRunner` | Add a closure branch; retain the instance-method branch. |
| Initialize properties from a callable | `Init`, `InitHandler` | Keep the API and execution path. Add PHP 8.5 contracts and examples. |
| Autowire a type | `AutowireByTypeResolver` | Extend simple-union candidate selection in this owner. |

The present priority order is attributes → provided name/position → provided type → autowire → PHP default → nullable. A handler cannot ask for “whatever the remaining pipeline would have returned” without introducing recursion or duplicate work. `Transform` therefore uses the source contract in §4, rather than recursively invoking the resolver.

## 3. Public API and PHP syntax

### 3.1 `Transform`

Proposed new declaration:

```php
#[Attribute(
    Attribute::TARGET_PARAMETER
    | Attribute::TARGET_PROPERTY
    | Attribute::IS_REPEATABLE
)]
final readonly class Transform
{
    public function __construct(public Closure $callback) {}
}
```

There is no separate `default`, `params`, service ID, factory, or mapper argument. Source attributes own value acquisition. The callback receives the current value as one semantic argument, followed by dependencies resolved through DI (§5).

### 3.2 `SetUp`

Widen the existing property and constructor parameter, retaining its name:

```php
public function __construct(
    public string|Closure $method,
    public array $params = [],
) {}
```

The class remains readonly, non-final, and repeatable. `method:` remains valid for named arguments. A string continues to mean an instance method name, with the current public/concrete/non-static validation. A string never becomes a global function name or service ID. A `Closure` receives the constructed instance as its semantic first argument. Its return value is ignored and cannot replace the object.

### 3.3 `Init`

Retain `Init(mixed $callable, array $params = [])` and its existing callable representations. Do not narrow this established API to `Closure`. Its callable produces the property value; it does not receive the property owner as an implicit first argument.

### 3.4 Correct examples

These are proposed DI 6 examples; `Transform` and callback `SetUp` are not part of DI 5:

```php
final class CustomerInput
{
    public function __construct(
        #[Transform(trim(...))]
        #[Transform(static function (string $value): string {
            return strtolower($value);
        })]
        public string $email,
    ) {}
}

#[SetUp(static function (Application $application, Logger $logger): void {
    $application->setLogger($logger);
})]
final class Application
{
    private Logger $logger;

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }
}
```

PHP 8.5 supports capture-free `static function (...) { ... }` in attribute arguments and first-class references such as `trim(...)` or `Formatter::normalize(...)`. Arrow closures, including `static fn`, non-static closure literals, and closures with `use (...)` are not valid there. Named references retain their lexical visibility. Do not bind a static callback to the object or change its declaring scope. See the official implemented [closure RFC](https://wiki.php.net/rfc/closures_in_const_expr) and [first-class callable RFC](https://wiki.php.net/rfc/fcc_in_const_expr).

The constant-expression first-class-callable syntax supports named functions and declared static methods only. Dynamic reference expressions such as `[Formatter::class, 'normalize'](...)`, object-method references, and methods supplied solely by `__callStatic()` are not supported in attribute arguments. This language restriction does not remove the established runtime string/array/invokable forms accepted by `Init` or ordinary callable execution. Their existing native dispatch contract remains separate from attribute-expression syntax.

The rejection phase is native PHP behavior too. The recheck on PHP 8.5.8 confirmed that a dynamic reference expression fails compilation, whereas a magic-static attribute reference parses and throws `Error` when `ReflectionAttribute::newInstance()` materializes it. Syntax fixtures must reach materialization when compilation succeeds; `php -l` alone is not sufficient.

## 4. Transformation contract

### 4.1 Composition and ordering

Register `Transform` with capability `ValueTransformer`, after `ValueProvider`, in the property after-instantiation phase. Repeated `Transform` usages execute in declaration order. `Cast` and `Transform` preserve their mutual declaration order unless an explicit registered composition constraint requires otherwise. Existing composition conflict and cycle diagnostics remain authoritative.

Each usage runs at most once during an attempt. Late attribute loading may recompose the remaining work; it must not replay a completed transformer or reconstruct a pending attribute instance. An incompatible late change must fail using the existing completion guards.

### 4.2 Parameter input

| State when a transformer runs | Callback input |
| --- | --- |
| `ParameterAttributeValue` is resolved | That exact value, including explicit `null`. |
| Unresolved variadic parameter | One empty array. |
| Unresolved parameter with a PHP default | The value obtained from the reflected default for this attempt. |
| Unresolved nullable parameter | `null`. |
| Otherwise | Throw `ResolutionException` before invoking the callback. |

A resolved value may come from an allowed explicit argument, a provider, or an earlier transformer. Authoritative provider rules remain unchanged. There is no fallback from a supplied but invalid value to a default.

The handler does not autowire the target parameter itself. To transform a freshly created object, declare `Make`; to transform a shared service, declare `EntryId`. The callback's additional dependencies can still be autowired. Example:

```php
#[Make(Service::class)]
#[Transform(static function (Service $value, Formatter $formatter): Service {
    return $formatter->configure($value);
})]
Service $service
```

For a variadic target, the callback transforms the entire collection once. The existing final validation checks every resulting element and native named/positional ordering. No implicit enumeration of all matching services is introduced.

### 4.3 Property input and assignment

Follow the current `CastHandler` source model, without its optional attribute-specific default:

1. If a provider/transformer already claimed the property, read the pending value through `ObjectCreationContext::readProperty()`.
2. Otherwise, call `claimProperty()`. If it returns false, do not invoke the callback.
3. For a newly claimed property, require a public creation-context key exactly matching its name; use `array_key_exists()` so `null` remains a value.
4. If the key is absent, throw a property `ResolutionException` before calling the callback.
5. Stage the result through `writeProperty()`.

A declared mutable property default is not an implicit source for standalone `Transform`. Use a provider such as `Config`, `Env`, `EntryId`, `Make`, `Init`, or an explicit context value. Existing initialized-readonly and promoted-property skipping remains unchanged. Static properties and get-only virtual properties retain the existing rejection.

Intermediate values remain outside the PHP property. After the whole property composition succeeds, the current staging owner writes the final value once. This supports readonly initialization, write-only virtual properties with a pending provider value, and one setter-hook invocation.

**Do not validate property output against `ReflectionProperty::getType()` before assignment.** A setter hook can accept a wider input type than the getter/property type and perform its own conversion. Native assignment through the existing `writeProperty()` remains responsible for PHP assignment semantics. Parameter output, in contrast, continues to use the existing parameter validation after the entire attribute chain.

Staging prevents intermediate assignment to this property. It does not undo callback side effects on external services, objects passed by identity, earlier properties, or the surrounding application.

## 5. Callback invocation contract

### 5.1 Semantic argument and DI suffix

For `Transform`, the semantic argument is the current value. For callback `SetUp`, it is the constructed object. Its array shape never implies argument expansion.

- A callback with zero declared parameters is allowed and is invoked with zero native arguments. A zero-parameter transformer still requires a source under §4; it does not become an initializer.
- Otherwise, the first parameter receives the semantic argument regardless of its name or default. It cannot be by-reference or variadic.
- Validate that argument using the current strict parameter type-acceptance rules. The input type can differ from the final target type.
- Parameters after the first use the existing DI chain, including custom resolvers, parameter attributes, defaults, nullable fallback, omission of native internal defaults, and existing variadic semantics.
- By-reference parameters remain unsupported throughout.
- The suffix resolution context includes the real semantic value at `resolved[0]`.
- A callback result, including `null`, becomes the new transformer value. Native declared return types are enforced by PHP. A `void` return is handled as its actual `null` result, without special interpretation.

### 5.2 Protecting the first argument

Calling `$container->call($callback, [0 => $value])` is insufficient: an authoritative attribute or a higher-priority custom resolver can replace that argument. Supplying the first parameter's name as well does not protect it from the whole resolver chain.

Introduce one internal helper, `Internal\AttributeCallbackInvoker`, that owns callback reflection, first-argument ABI/value validation and the prepared final invocation. DI metadata validation stays in the parameter resolver's guarded boundary. The helper must:

1. Reflect the actual `Closure` without rebinding or reconstructing it.
2. Handle the empty-signature case with an empty native argument vector, still making one prepared call through the existing invoker/decorators.
3. Check the first parameter's ABI and value. Pass its real `ParameterTarget` to the suffix entry point for metadata-only validation. Registered DI attribute usages on this reserved parameter are invalid; ordinary non-DI metadata such as `SensitiveParameter` remains allowed.
4. Prepare only the suffix for parameter resolution, preserving original parameter positions. The first parameter must not enter custom resolver `supports()` or `resolveParameter()` calls or attribute handlers.
5. Resolve the suffix through a narrow internal entry point in `ParametersResolver`, using its existing loop with an initial native argument prefix and `resolved[0]` state.
6. Keep the reserved first target and completed suffix targets in the same `CompletedAttributeGuard`. Any newly visible DI semantics on the first target, or incompatible change to a completed suffix, must fail before invocation. Do not perform a separate first-target discovery probe after the guarded suffix operation returns.
7. Call the existing invoker with `new PreparedCallable($callback)` and the complete native argument vector.

The ordinary `resolvePrepared()` entry point must use the same loop with an empty prefix. Do not copy the parameter-resolution loop, use hidden special keys in the public context, or synthesize a wrapper closure with copied attributes. `ParameterResolutionContext` already supports initial resolved state. `PreparedCallable` already prevents a second DI pass and exposes the original callable to compliant decorators.

The shared internal helper is constructed as `AttributeCallbackInvoker(ParametersResolver $parameters, CallableInvokerInterface $invoker)` and exposes `invoke(Closure $callback, mixed $argument, array $providedParameters = [], ?string $diagnosticOwner = null): mixed`. `ContainerFactory` creates one helper for `TransformHandler` and `SetUpRunner`, using the container as its invoker so existing decorators remain effective. The helper is not a new public service registration and stores no invocation closures or values.

The new seam is an internal operation on `ParametersResolver`, not a new extension interface:

```php
/**
 * @internal
 * @param array<string|int, mixed> $providedParameters
 * @param class-string|null $diagnosticOwner
 * @return array<string|int, mixed>
 */
public function resolvePreparedSuffix(
    PreparedParameterPlan $suffix,
    ParameterTarget $firstTarget,
    mixed $firstArgument,
    array $providedParameters = [],
    ?string $diagnosticOwner = null,
): array;
```

`$suffix` contains only the callback parameters after its first declaration and retains their original positions. `$firstTarget` is the actual first declaration, at position `0`; the binder has already validated its ABI and value. The suffix entry point builds its metadata plan, rejects registered DI usages before suffix execution, and records the accepted empty DI plan as reserved-argument proof in the same invocation-local completed guard used for suffix execution. It never classifies or executes a resolver for this target.

This operation seeds the native argument vector with `[$firstArgument]` and resolution state with `[0 => $firstArgument]`, then runs the same refresh, boundary, omission, variadic and completed-work logic as `resolvePrepared()`. Its final guard stabilizes both directions: first-target discovery can reveal a change to an already completed suffix, and suffix discovery can reveal forbidden first-target metadata. It records the plan actually consumed by each attribute bridge, without replacing that proof with a later discovery result or replaying handlers. Use the existing guard/revision machinery; do not add a second completion algorithm or mutable last-invocation state.

The operation returns the complete native vector, including the first value. An empty suffix is valid and still performs reserved-target validation; the zero-declaration callback branch bypasses this seam and supplies no arguments. Neither the prefix, reserved proof nor diagnostic owner becomes shared plan state.

### 5.3 Supplied context

`Transform` supplies an empty public parameter bag to the suffix. Its dependencies come from DI, their own attributes/resolvers, or their defaults. The outer DTO/constructor/request parameter bag is not inherited by name, so a field that happens to match a dependency parameter cannot replace that dependency. No current HTTP request is implicitly forwarded.

`SetUp` retains its existing context: obtain provenance through `ObjectResolutionParameterStore`; unwrap explicit attribute values; merge them over surrounding public parameters with `ResolutionMetadata::mergePublicPreservingInternal()`; enforce the existing parameter boundary on the suffix.

In the callback branch, remove ambient position `0` and the first parameter's name from the supplied binding bag. `SetUp::$params` retains its existing string-key parameter bag and provided-parameter matching rules. Reject any integer key, including reserved position `0`, and any explicit attempt to replace the first parameter's name with `InvalidConfigurationException` before a value unwrapper executes. The existing ambient public bag may still provide suffix positions `1` and above; do not renumber them. The semantic object remains available in resolved state. Do not expose private request provenance to custom resolvers or handlers.

This keeps `SetUpValueUnwrapperInterface::unwrap(mixed $value, string $key, ?ReflectionParameter $parameter)` unchanged. A key matching a suffix parameter's name is unwrapped with that parameter's actual reflection metadata. The new callback branch does not add positional-key support to the unwrapper API or alter the existing string-method branch. A zero-declaration callback has no reserved parameter name, still rejects integer explicit keys, and receives zero native arguments as specified in §5.1.

Other public string keys, including unused names and class type-keys, remain accepted. As in the current `SetUpRunner::unwrapParams()`, their values reach a matching unwrapper with `null` reflection metadata, and their keys are preserved during the merge. The suffix then applies the existing provided-parameter rules, including type-key matching and source-boundary checks; unconsumed keys do not become extra native arguments. The existing filtering of DI-private keys remains intact. For a zero-declaration callback, string-key validation and unwrapping still occur with `null` metadata before invoking it with an empty argument vector. Do not introduce a whitelist of declared callback parameter names or skip those unwrappers merely because no suffix parameter consumes the value.

There is one necessary extension to the source boundary: `MappedRequestParameterSourceGuard` currently skips a target whose `ReflectionParameter::getDeclaringClass()` is null. Named-function first-class callbacks and unbound closures can have no declaring class. For callback `SetUp`, carry the constructed class from `ObjectCreationContext` as an internal diagnostic-owner argument through the suffix entry point and `ParameterResolutionBoundary` into the source guard. A missing declaring class must not suppress source-conflict checks when this owner is supplied. Preserve that owner across initial and refreshed-plan checks. Do not encode it in public parameter keys or infer ownership from a callback's first parameter type. The ordinary `resolvePrepared()` path retains its existing contract when no such owner is supplied.

The string-method branch has no reserved first argument and retains its current mapping. Generalize `SetUpRunner::providedParameters()` and `unwrapParams()` reflection input from `ReflectionMethod` to `ReflectionFunctionAbstract` so existing unwrappers receive the real suffix `ReflectionParameter`.

### 5.4 Failure ownership

| Failure | Owner and result |
| --- | --- |
| Invalid first-argument ABI or initially registered DI attributes on it | Binder or guarded parameter entry point raises `InvalidConfigurationException` before callback execution. |
| Late first-target DI metadata or incompatible completed-suffix change | Existing `AttributeCompositionException`, an `InvalidConfigurationException` subtype; no callback body or completed-handler replay. |
| Runtime input incompatible with the first parameter | Parameter `ResolutionException`, contextualized by the owning transform/property/lifecycle boundary as appropriate. |
| Suffix dependency or parameter failure | Existing resolver diagnostics and Componenta exception hierarchy. |
| Foreign exception inside `Transform` | Existing parameter/property boundary produces `ResolutionException` and preserves the cause. |
| Foreign exception inside callback `SetUp` | Existing object/lifecycle boundary normalizes the creation failure and preserves the cause. |
| Explicit ordinary `container->call()` target throws | Existing behavior remains: target-body throwable propagates unchanged. |

Do not globally change `CallableInvoker` exception handling. Do not retry callback execution to recover a value or improve diagnostics. Already-owned Componenta exceptions retain identity according to current boundaries. A failed property transformation must not commit its pending property value; a failed setup must not publish the object as a successful shared service.

## 6. Deterministic union autowiring

This is an independent resolver enhancement, not a prerequisite for basic PHP 8.5 callback syntax.

### 6.1 Supported candidates

Extend `AutowireByTypeResolver` to handle non-variadic simple unions whose arms are named types. Collect all non-builtin class/interface service IDs, including `Closure`, resolve contextual names using the existing type helpers, and deduplicate equivalent type names case-insensitively. Keep each actual candidate ID for the existing container lookup; this does not introduce case folding for opaque service registrations.

Do not flatten an intersection into unrelated alternatives. Intersections and DNF unions containing intersection arms remain outside implicit autowiring for this release; supplied values still undergo full existing type validation. A plain named type retains existing named-type behavior. There is no special union-only ban on a valid explicit `Closure::class` binding.

### 6.2 Selection algorithm

1. Preserve existing resolver priorities. Applicable explicit values, provider attributes and custom earlier resolvers finish before autowiring. Existing authoritative-source semantics remain unchanged.
2. Query `container->has()` for each distinct candidate service ID. This is the existing availability contract and can autoload; it is not a promise of zero side effects or successful construction.
3. Zero available candidates: return unresolved to the caller. Reflection creation and DI-aware ordinary callable invocation then use their existing PHP-default, nullable, then error path. Explicit `ClassDefinition` calls keep their different omission contract described in §6.3.
4. Exactly one available candidate: call `get()` for that ID once. In `AutowireByTypeResolver`, require the returned value to implement that selected class/interface, then retain full target-type validation. Matching a different union arm is insufficient: a selected `Service::class` binding returning a closure is invalid even though the target is `Closure|Service`.
5. More than one available candidate: throw `ResolutionException` with a stable, sorted list of candidate IDs before calling `get()` for any candidate. Do not choose declaration order, inspect factory bodies, or instantiate every candidate to compare object identity.
6. A selected candidate that fails during construction propagates its normal resolution failure. Do not silently try another arm, fall back to `null`, or use a default after construction starts.

Two different interface IDs that are aliases of the same implementation still form an ambiguity. Resolution must not require evaluating their factories to infer identity. Explicit arguments or a provider remove the ambiguity.

`supports()` classifies only the declared type shape. Candidate availability and the winner are recomputed in `resolveParameter()` on every call; never cache a selected service in a prepared parameter plan. Existing runtime mutations, alias changes and external-container ownership changes must be visible after a plan is warmed. Probe candidates in a stable sorted order so reflected arm ordering is not itself a choice rule; retain the existing `has()` error contract rather than inspecting service factories.

For `Closure|Service`, an explicitly bound `Closure::class` participates under the same `has()` rule as `Service`; two available arms are ambiguous, one available arm wins, and zero arms remain unresolved. For `Closure|string`, a bound closure is the sole possible service candidate; an unbound `Closure` is not implicitly constructible. Explicit argument closures remain valid through the earlier supplied-value path. Enums, abstract classes and unbound interfaces likewise count only as available under existing `has()` behavior. The earlier draft's union-only exclusion of `Closure` had no recovered requirement or demonstrated necessity and is removed.

### 6.3 Shared resolver, separate entry contracts

`ClassDefinition` optional constructor and configured-public-method autowiring also use `AutowireByTypeResolver` through `FactoryResolver::arguments()`; test both against the same union selection rules. This path does not run `ParametersResolver`'s result-validation loop, so the selected-candidate compatibility check belongs in the shared autowire resolver itself. Native invocation and the existing caller boundaries remain in force. Explicit definitions retain their strict lifecycle: no new attribute processing, no implicit setup phase, and no autowiring when that definition has not enabled it.

When no candidate is available, this definition path leaves an absent argument omitted. PHP can apply an actual declared default; a required nullable parameter without a default still raises the existing missing-argument failure. Do not add the reflection pipeline's implicit-null fallback to explicit definitions. For example, an unbound required `Left|Right|null $value` is unresolved in `ClassDefinition`, whereas `$value = null` can use its native default. Parity here means the same candidate selection, not a new shared fallback or lifecycle contract.

Add a dedicated type helper for eligible simple-union candidates if necessary. Do not repurpose `TypeHints::classNames()` wholesale: it currently flattens intersections for supplied type-key handling, which is a different responsibility. Do not change `ArrayTypedResolver` precedence as a side effect.

## 7. Scope, lifetime and cache invariants

The existing `AttributeUsage::newInstance()` materializes an attribute at execution time. Plans store reflection metadata and composition, not runtime callback results or newly constructed attribute instances. Preserve that boundary.

The fresh audit's [portable generator](../audit/reproductions/Php85Callbacks.py) and [exact fixture/output record](../audit/2026-11-13-php85-native-evidence.json) cover 14 cases on PHP 8.4.23 and 8.5.8. The two current-`Init` fixtures assert both class and internal-function source mappings against the checkout before making DI observations. Pure-engine fixtures do not load DI. PHP 8.5.8 observed:

| Callback origin in the attribute argument | Repeated materialization | State behavior |
| --- | --- | --- |
| Inline `static function` | Fresh closure for each attribute materialization | Its static locals start fresh for that materialization. |
| Named function/static-method first-class reference | Fresh closure wrapper | Static state belongs to the referenced function/method and is shared. |
| Class constant holding a closure | Reuses the constant's closure object | State is shared according to the constant's lifetime. |

For four successive materializations, calls in the order first/first/second/third/fourth return `[1, 2, 1, 1, 1]` for the inline callback and `[1, 2, 3, 4, 5]` for named/static-method references and the constant-held closure. Weak references clear for discarded inline and named/static-method wrappers; the class constant legitimately keeps its closure alive. This control distinguishes wrapper identity from the lifetime of referenced function state.

Current `Init` produces identical cached and no-op-composition-rule results across three `make()` calls: inline counters restart, named-function and constant counters advance, the private static helper receives its DI dependency, and shared `get()` preserves identity. Its inline static-local object becomes unreachable after creation. Lazy initialization runs only on first property access. These are verified existing behaviors, not results from a prototype of the proposed `Transform` or callback `SetUp`.

The library must preserve native behavior, rather than promise universal isolation for all callbacks. Attribute materialization can autoload or throw; guard recomposition and original causes must survive. Repeated resolutions, failed attempts and delayed lazy initialization must not retain an otherwise unreachable runtime callback, semantic value, dependency object, or `ReflectionObject` through shared metadata caches.

Do not cache a callback by file/line or `spl_object_id()`, extract its source, serialize it, or reconstruct its scope. Reuse stable class/method metadata where already safe. The November `ReflectionObject` cache isolation fix is a prerequisite regression to retain.

The follow-up [cache parity audit](../audit/2026-11-12-cache-parity-recheck.md) adds executable controls for this boundary. [ObjectAttributeCacheParityContractTest](../../tests/Contract/ObjectAttributeCacheParityContractTest.php) compares ordinary plans with the public no-op-composition-rule control, including a PHP-8.5-gated `Init` fixture whose inline closure has a static local. Repeated materialization must follow PHP's native fresh-closure behavior while each invocation observes the current dependency. Reuse this control for `Transform` and callback `SetUp`; compare values, exception ownership, callback/handler counts, and object lifetime, not only final scalar output.

Keep the revised discovery and classification boundaries in the existing owners. Attribute discovery, object handler discovery, and prepared parameter classification must settle on a coherent registry revision before publication. Preserve the fresh audit's [parameter execution/consumption regression](../../tests/Contract/ParameterExecutionRevisionContractTest.php): coherent slots and plans before execution, then proof of the actual bridge plan consumed. A new suffix-resolution seam must not bypass this stabilization or cache a selected dependency value. Runtime mutation must preserve unaffected shared identity as well as invalidate changed bindings.

## 8. PHP version and release compatibility

Delivery target: DI 6 on PHP 8.5, with branch alias `6.0.x-dev`. The final Composer constraint must use the verified minimum patch from E1; broad `^8.5` is not a settled promise that every runtime in that range satisfies the native trait contract. The current 5.x line retains PHP `^8.4`; none of the audit fixes raises its floor.

The user authorized implementation on a separate major-version branch; `refactor/di6-definitions-callbacks` carries the public `SetUp` and union changes. The specified library implementation can remain parseable on PHP 8.4 while E1 is pending; test it with PHP 8.4-compatible callback construction and the available PHP 8.5 native-expression fixtures. Final platform packaging is a later, explicit gate. Version-gated eval/subprocess fixtures may also remain in 5.x when PHP 8.4 can parse their containing files and skips unsupported syntax before evaluation. A diagnostic of a known engine defect is not a passing support regression for that native form.

One upstream limitation is separate from DI correctness. On PHP 8.5.8, an attribute declared in a trait with `self::who(...)` returns the first consuming class's result for both consumers; `parent::who(...)` has the corresponding parent-scope error. A named private method reference in the trait also incorrectly retains the first consumer's successful visibility result. The native inline-static-closure control resolves each consuming class correctly. These observations come from actual attribute expressions, without DI or shared reflection caches, in the portable evidence above.

The upstream correction is [php-src `44a2a2c3`](https://github.com/php/php-src/commit/44a2a2c3db9e4e06c41a0603c3826fac3b1b99f3), dated November 3, 2026. Its [official announcement and regression cases](https://news-web.php.net/php.cvs/143271) identify the **PHP-8.6 branch** and explain cached scope and visibility. That announcement alone does not prove a backport into any PHP 8.5 release. The [official November 5 announcement](https://news-web.php.net/php.internals/133012) describes 8.5.13RC1 as a testing release and November 19 as an expected release date. On this recheck date, the official stable 8.5 release is [PHP 8.5.12](https://www.php.net/releases/8_5_12.php). Neither an RC, a future NEWS date nor the PHP-8.6 commit proves that PHP 8.5.13 is released or will contain the required fix.

A bounded check of the [released `php-8.5.12` source](https://github.com/php/php-src/blob/php-8.5.12/Zend/zend_ast.c#L1092-L1151) confirms that this correction is absent: the static-call branch reuses cached `fptr` and still stores it unconditionally, without the new `ZEND_FETCH_CLASS_DEFAULT` and `ZEND_ACC_PUBLIC` restrictions. The [official changelog](https://www.php.net/ChangeLog-8.php#8.5.12) dates that release to October 22, 2026. The ordinary DI CI suite passes on PHP 8.5.12, but it does not execute these portable trait fixtures; no binary-level trait result on 8.5.12 is claimed. This source check leaves E1 open for the affected support promise.

### E1 — Verify the released PHP patch floor

**Blocked scope:** the final DI 6 supported-patch declaration and release claim that all supported runtimes correctly implement the affected trait first-class-reference forms. The binder, `Transform`, callback `SetUp`, union implementation, unaffected native-form tests and migration work are not blocked by this platform evidence task. No numeric minimum patch is invented here.

**Bounded deciding evidence:** identify an officially released PHP 8.5 patch; verify its tagged source contains the engine correction; run the upstream trait `self::`/`parent::`, named-class visibility and two-consuming-class fixtures plus the attribute-expression examples on that exact binary. Record the release/tag, binary version and outcomes. A release candidate may inform the investigation but cannot satisfy this stable-release gate. Do not broaden this into an engine workaround or a new DI callback-scope mechanism.

**Stop and unblock:** once the released patch passes those fixtures, update this section with the actual minimum, tagged-source evidence and corresponding Composer/CI target. If no released patch satisfies the evidence, keep the affected platform/release gate blocked while independently ready library work can proceed. Do not publish a claim of fully correct trait-relative callbacks on the tested 8.5.8 runtime. A capture-free inline static closure or fully qualified reference to a public helper is an interim application-level alternative within the verified scope; DI must not emulate or rebind native lexical scope.

| Work or promise | Readiness |
| --- | --- |
| Existing `Init` native-expression examples and compatible regression fixtures | Ready; actual current behavior is recorded. |
| Shared binder, `Transform`, callback `SetUp`, simple-union behavior | Implemented on the authorized major branch; supported forms are tested while E1 remains pending. |
| Correct trait-relative/mixed-visibility first-class references on the minimum supported runtime | Blocked by E1; PHP 8.5.8 demonstrably fails. |
| Final DI 6 Composer floor, supported-runtime CI and release asserting that scope contract | Blocked by the released-patch proof, then by the ordinary implementation/release gates. |
| Request-to-DTO callback | Blocked independently by §11's semantic decisions. |

Compatibility review must cover existing `SetUp` subclasses and named arguments, `Init` string/array/invokable inputs, extension handler definitions, decorated `CallableInvokerInterface` services, companion package constraints, and applications enabling `ClassDefinition` autowiring. Use the actual consuming packages' manifests and test suites before proposing any coordinated version bumps. No unrelated package release is required just to add syntax-aware tests here.

Direct construction of the infrastructure handler `SetUpRunner` changes in this major: its constructor takes `CallableInvokerInterface`, `ObjectResolutionParameterStore`, then the shared `AttributeCallbackInvoker`, followed by the existing variadic `SetUpValueUnwrapperInterface` instances. The only in-repository construction site is `ContainerFactory`; external direct consumers insert this helper before their unwrappers. This does not change the attribute constructor names/order or add a legacy handler path.

There is a specific major-version migration for `SetUp` subclasses: a subclass redeclaring or promoting `public string $method` must widen that property to the same `string|Closure` type or remove the redeclaration. PHP property invariance rejects the narrower redeclaration after the parent changes. A constructor-only `string` parameter that forwards to the parent without redeclaring the property can remain narrower. Document and test these cases; do not describe the widening as universally source-compatible.

## 9. Acceptance matrix

| Area | Required observable evidence |
| --- | --- |
| Syntax | Inline static closure and named/declared-static first-class references compile; forbidden arrow/non-static/capture, dynamic-reference, object-method and magic-static examples are tested in separate subprocess fixtures so they cannot break suite discovery. |
| `Init` parity | Existing input forms remain valid; readonly initialization; DI dependency; private static helper; three repeated creations; native closure state and lazy initialization behavior. |
| First argument | Arbitrary name, nullable and optional declaration, zero parameters, whole array input, invalid type, by-reference rejection, variadic-first rejection. |
| First-argument isolation | Ambient name/position cannot replace it; first parameter never enters custom resolver classification or execution; registered DI metadata rejected; ordinary `SensitiveParameter` allowed; late metadata fails before body. First-target discovery revealing a completed-suffix change and suffix discovery revealing first-target metadata both fail before body without replaying completed handlers. |
| Suffix | Dependency autowiring, attributed dependency, custom resolver seeing `resolved[0]`, default/null, native omitted optional argument, variadic collection and ordering. |
| Parameter transform | Explicit/source/default/null/empty-variadic/missing input; no implicit source autowiring; repeated and mixed `Cast`/`Transform`; final full type checking. |
| Property transform | Pending intermediate types; readonly final assignment; promoted skip; write-only setter; wider setter input; one setter call; no partial write on callback failure. |
| Setup | Unchanged string validation; callback after property commits; repeatable declaration order; ignored return; explicit reserved-name or integer-key rejection before unwrappers execute; ambient suffix positions preserved; matching-name unwrappers receive the real suffix parameter, other string keys receive `null` metadata; unused/type-key values and named values for a zero-declaration callback retain §5.3 handling without extra native arguments; existing request provenance. |
| Classless setup provenance | Full `MapRequest` → DTO → named-function callback path rejects raw name/type-key collisions with suffix `EntryId`/`Env`; repeat with a late-loaded source after suffix dependency resolution; callback body never receives the conflict, owner/provenance stay hidden from extensions. |
| Union | Zero/one/two candidates, reversed arm order, aliases, bound/unbound `Closure` arm under uniform `has()` rules, explicit override, optional/default/null, failing selected factory, wrong returned value (including another union arm), variadic exclusion, intersections/DNF unchanged; warmed-plan transitions zero → one and one → ambiguous after mutation. |
| Definition parity | Enabled/disabled `ClassDefinition` constructor and configured-method autowiring; shared candidate validation; declared default versus required nullable omission; no accidental adoption of the reflection attribute lifecycle. |
| Failure and retry | Correct owner diagnostics and `previous`; direct callable exceptions unchanged; failed lazy attempt does not retain callback objects or replay completed work in the same attempt. |
| Memory | `WeakReference` assertions for discarded callback/value/dependency/object-reflection graphs; repeated same-line closure instances remain independent where PHP defines independence. |
| Cache parity | Identical complete callback signatures under ordinary and non-memoized plan paths; warmed service/alias/provider replacement; no skipped suffix parameters or retained runtime callback/value; stable discovery after incidental autoload; unchanged independent shared-service identity. |
| Engine | Two trait-consuming classes resolve their own relative helper and respect visibility on the released minimum supported PHP patch. |

Use Pest public-contract tests and focused fixtures, with failing regressions before code where applicable. Do not assert private cache sizes or reimplement the resolver in tests. Preserve full-suite PHPStan max, formatting, dependency audit, and the repository's applicable Linux/Windows/coverage/mutation gates. Record which gates actually ran.

## 10. Derived implementation plan

**Library slices 1–5 are implemented on the authorized major branch. Slice 6 contains the remaining verification, documentation and release gates.** E1 blocks the final affected platform/support promise, not unrelated coding. None of these slices retroactively adds features to the published 5.0.5 tag.

Every completed slice must be reviewed, checked, committed and pushed. Slices 3 and 4 depend on the binder in slice 2; union work in slice 5 is independent of that binder. No slice should leave two competing resolution algorithms in place. The evidence requirements below define completion; release packaging remains subject to E1.

### Slice 1 — Establish native behavior and existing `Init` controls

Files: `README.md`, new PHP 8.5 fixture directory and `tests/Contract/Php85AttributeCallableContractTest.php`; retain the portable audit reproduction as historical evidence.

Add syntax/scope/lifetime fixtures and `Init` end-to-end cases against the existing implementation. Keep compile-error fixtures outside normal test discovery and confirm source mapping when using an installed Composer graph. Separate verified native forms from the known failing trait diagnostic; once E1 is resolved, run the latter as a correct-behavior regression on the supported minimum. Do not change the manifest merely to start this work and do not teach DI an engine workaround.

Done when verified native examples run through the real container, old `Init` representations still pass, native state/lifetime controls match, and the unresolved patch-floor question is explicitly isolated as E1.

### Slice 2 — Add the semantic-first-argument seam

Files: new `src/Internal/AttributeCallbackInvoker.php`; `src/Resolver/Parameter/ParametersResolver.php`; `src/Internal/Resolver/Parameter/ParameterResolutionBoundary.php`; `src/Internal/Resolver/Parameter/Request/MappedRequestParameterSourceGuard.php`; focused new `tests/Contract/AttributeCallbackInvocationContractTest.php`.

Depends on the native baseline and §5 contract, not on E1. Extract only the existing argument assembly loop into a shared private implementation accepting an initial prefix/resolved state and reserved-target proof. Add the internal suffix entry point; do not route the first parameter through classification. Keep its metadata-only validation and completed suffixes in one guard through final stabilization. Carry an optional internal diagnostic owner through initial and refreshed source-boundary checks so classless setup suffixes remain guarded. The binder uses existing strict type acceptance and invokes through `PreparedCallable` and the existing invoker. No new public configuration key or extension interface is needed.

Done when first-slot override attempts fail or are ignored as specified, suffix extensions see the correct state, decorators see one prepared invocation, native default omission remains intact, and lifetime regressions pass. The ordinary callable suite must remain unchanged in behavior.

### Slice 3 — Add `Transform` with existing composition and staging

Files: new `src/Attribute/Transform.php`; new `src/Resolver/Attribute/Handler/TransformHandler.php`; `src/ContainerFactory.php`; new `tests/Contract/TransformContractTest.php` and property-hook fixtures.

Depends on slice 2. Wire one binder shared by the two new consumers. Register the capability/order, implement the precise source fallback, use existing pending property reads/writes, and add diagnostics at the owning target. Do not modify `ObjectCreationContext` assignment semantics or reorder the global resolver chain.

Done when the parameter/property rows in §9 pass, a callback runs once per usage, mixed transformer order is stable, and setter/readonly failure cases demonstrate one final write.

### Slice 4 — Extend `SetUp`, preserving the method path

Files: `src/Attribute/SetUp.php`; `src/Resolver/Entry/SetUpRunner.php`; `src/ContainerFactory.php`; new `tests/Contract/SetUpCallbackContractTest.php`; existing setup/environment/provenance regressions.

Depends on slice 2 and a major-version branch. Widen the constructor/property type without renaming it. Add closure dispatch through the binder with the constructed class as the private diagnostic owner, generalize the reflector accepted by unwrapping, preserve the private context store and merge rule, and reject explicit integer keys or first-parameter-name replacement before unwrapping. Preserve ambient suffix positions and the existing named-key unwrapper interface. Keep string validation and existing method invocation behavior. Exercise the complete mapped-request path with named-function callbacks as well as class-scoped closures.

Done when callbacks run after property injection, repeated hooks execute in order, returning an object cannot replace the entry, and method-based setup and request source guards retain their previous results.

### Slice 5 — Extend union selection independently

Files: `src/Resolver/Parameter/AutowireByTypeResolver.php`; a narrow helper in `src/Resolver/TypeHints.php` if needed; `tests/Contract/UnionAutowiringContractTest.php`; `ClassDefinition` contract tests.

Depends on §6 and a major-version branch, not on the callback binder or E1. Implement candidate collection and cardinality checking before `get()`, and selected-candidate compatibility validation in the shared resolver after it. Apply existing availability uniformly to `Closure` and other named arms. Keep `supports()` independent of mutable service availability, and keep priority, supplied type-key handling, intersection/DNF behavior and definition opt-in semantics intact. Add stable ambiguity diagnostics, warm-plan mutation and selected-factory-failure cases for reflection creation and explicit constructor/method definitions.

Done when factory counters prove ambiguity performs no candidate `get()`, exactly one candidate is instantiated once, and explicit values/default/null/definition cases obey §6.

### Slice 6 — Validate cost, migration and final release packaging

Files: `README.md`; migration documentation; focused additions to existing benchmark harnesses only for new paths; `composer.json` and `.github/workflows/ci.yml` for the final supported-platform decision.

Documentation and measurements can proceed as their feature slices finish. Document correct PHP syntax, the property/parameter source distinction, callback scope/lifetime, setup parameter binding and subclass migration, and union ambiguity. Compare ordinary get/make/call/build behavior against a pinned pre-feature commit, plus zero/one/multiple transformations, setup callbacks and union candidate counts. Verify weak-reference lifetime contracts separately from noisy wall-clock timing.

Only after E1 is resolved, set the verified PHP patch floor and `6.0.x-dev` alias, align the supported-runtime CI, and record the exact tested platform in migration docs. Run the applicable CI matrix, current companion-workspace tests and dependency checks. Confirm that the released minimum passes the engine fixtures. Record commands and results; do not infer application throughput from microbenchmarks. Tag/release is a separate action after implementation and gates are complete. Pending E1 must not be relabeled as production readiness for the affected native forms.

## 11. Deferred work and bounded decisions

### Request-to-DTO callback (possible 6.1)

The recovered notes proposed a final optional `transform` argument on `MapRequest` and its source-specific subclasses. This is not needed for `Transform` or `Init` and is not implementation-ready in this specification.

Before implementation, settle whether it transforms the mapped array or the finished DTO; the earlier intent was a mapped array before DTO construction. Preserve the current concrete order: raw request validation, then mapper operations `map → cast → defaults → sort → exclude`, then any new whole-array callback, then the existing named-key/source-conflict checks and DTO creation. Require an array result and preserve raw-field provenance. Do not bypass validation or expose private transport metadata to callback dependencies.

Bounded next step: inspect every `MapRequest` subclass constructor and current `RequestMapper`/`RequestAttributeHandler` call site, produce an exact signature and provenance table, and test raw-key removal/renaming, numeric keys, conflicting source attributes, callback failures, and nested DTO creation. Only then prepare its own implementation slices. No speculative signature changes belong in this audit.

### Performance of unavailable attributes

Address the measured repeated autoload issue under the separate [availability performance plan](attribute-availability-performance.md). Callback syntax does not require weakening late-loading guarantees, polling deadlines, or a new persistent cache.

### Settled policies and convergence

The following are normative choices for the proposed target, not unresolved decisions: empty-signature callbacks are allowed; first DI parameter metadata is forbidden and guarded together with the suffix; the `Transform` suffix receives no ambient public bag; explicit callback `SetUp::$params` uses string keys with existing provided-parameter matching rules; standalone property transformation needs a source; only simple unions are autowired; `Closure` uses the same availability rule as other service-type arms; different service IDs remain ambiguous even when aliases converge. If a choice changes, revise its owning contract and acceptance rows before implementing it. E1 blocks the affected platform/release promise, while the separate request-to-DTO questions block that deferred feature only.

During implementation convergence, compare code, wiring, public migration notes and observed behavior with §§2–9. Resolve missing, partial, contradictory or unrequested behavior in its owning source. In particular, remove any duplicated argument-resolution loop, unintended public metadata transport, permanent alternate callback route, changed string-method behavior, or broadened union/DNF policy. Passing tests alone does not establish that this architecture was implemented.
