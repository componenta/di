# DI 6 migration: callbacks and union autowiring

[Русская версия](di6-callbacks.ru.md)

This guide describes the implementation on the DI 6 development branch, following the DI 5.0.6 baseline. It is not a DI 6 release announcement. The implementation remains parseable on PHP 8.4; inline callbacks and first-class callable references written directly in attribute arguments require PHP 8.5.

**The final supported PHP patch floor is unresolved.** E1 in the [callback specification](../plans/php85-callbacks.md#8-php-version-and-release-compatibility) blocks the affected platform support promise and final DI 6 release packaging. PHP 8.5.8 has a reproduced native defect in trait-relative first-class references. This branch does not raise the Composer requirement, declare every PHP 8.5 patch supported, or emulate PHP lexical scope. See [platform limits](#8-platform-limits) below.

## 1. Changes to review before upgrading

| Area | DI 6 behavior | Migration action |
| --- | --- | --- |
| `Transform` | New repeatable parameter/property attribute accepting one `Closure`. | Choose an explicit value source and review the callback's first parameter and DI suffix. |
| `SetUp::$method` | Widened from `string` to `string\|Closure`; constructor names/order remain `method`, `params`. | Remove or widen a subclass's redeclared `string $method` property. Review direct `SetUpRunner` construction. |
| Method-based setup | A string still names a public, concrete, non-static instance method. | Existing `#[SetUp('initialize')]` and named arguments retain their meaning. |
| `Init` | Existing `Init(mixed $callable, array $params = [])` API remains. | Existing string, array and invokable values remain valid. New syntax is optional. |
| Simple union autowiring | Exactly one available class/interface arm is selected; multiple available arms fail. | Review union parameters that previously reached defaults/null and ambiguous service bindings. |

The callback and union changes are independent. Existing `Init` can already use native PHP 8.5 expressions on the 5.x line; the new `Transform`, callback `SetUp`, and union selection belong to this development branch.

## 2. Write supported native callback expressions

Use capture-free static closures or first-class references to named functions and declared static methods:

```php
use Componenta\DI\Attribute\Transform;

final class EmailInput
{
    public function __construct(
        #[Transform(trim(...))]
        #[Transform(static function (string $value): string {
            return strtolower($value);
        })]
        public string $email,
    ) {}
}

$input = $container->make(EmailInput::class, ['email' => ' Alice@Example.COM ']);
// $input->email === 'alice@example.com'
```

These expressions require PHP 8.5. `static fn`, non-static closure literals, closure captures with `use (...)`, dynamic expressions such as `[Formatter::class, 'normalize'](...)`, and object-method references are not valid attribute callback expressions. A reference resolved only through `__callStatic()` can parse but fails when PHP materializes the attribute. Syntax checking alone does not verify that case.

These restrictions apply to native constant-expression syntax. They do not remove runtime string/array/invokable callable forms from `Init` or ordinary container calls. DI reflects and invokes the actual closure without rebinding it, extracting its source, or reconstructing its scope. A valid private static reference retains its native lexical access.

## 3. `Transform`: source, ordering and result

The public API is:

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Transform
{
    public function __construct(public Closure $callback) {}
}
```

There is no `params`, `default`, service-ID or mapper argument. A transformer follows value providers, and repeated transformations run in declaration order. `Cast` and `Transform` retain their relative declaration order unless an explicitly registered composition constraint changes it.

For a parameter, the callback receives the current value supplied by an explicit argument, provider, or earlier transformer, including `null`. If no value exists, an unresolved variadic parameter supplies one empty array; otherwise a declared default supplies its value for this attempt, then nullable parameters supply `null`. A required parameter without one of those sources fails before the callback body. Supplying an invalid value does not cause fallback to the default.

`Transform` does not implicitly autowire the parameter being transformed. Use `#[Make(Service::class)]` to supply a fresh object or `#[EntryId('service.id')]` to supply a shared service. Additional callback parameters can still be autowired.

For a property, use a provider or an explicit creation-context key matching the property's name. A mutable property's declared default is not a source for a standalone transformer. For example:

```php
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Transform;

final class Label
{
    #[Init(static function (): string { return ' ready '; })]
    #[Transform(trim(...))]
    public readonly string $value;
}
```

Property transformations operate on staged values. The existing object pipeline assigns the final value once, preserving readonly initialization and setter-hook behavior. A setter can accept a wider input type than the property's readable type; native assignment remains responsible for that conversion. Initialized readonly and promoted properties keep their existing skip rules; static and get-only virtual properties remain invalid injection targets.

A transformer result, including `null` or the actual result of a `void` callback, becomes the next value. Final parameter validation still checks the full declared type. A variadic target passes its entire collection to the callback once, then validates and expands the final collection using existing native argument-order rules.

## 4. The first parameter is reserved for the semantic value

Both callback forms share one binding contract:

| Callback | First declared parameter | Remaining parameters |
| --- | --- | --- |
| `Transform` | The current value, including a whole array as one argument. | Resolved through the normal DI chain with an empty supplied public bag. |
| Callback `SetUp` | The constructed object. | Resolved through the normal DI chain with the setup context described below. |

The first parameter can have any name and can be nullable or optional. Its default does not replace the semantic value. Its declared type must accept that value under DI's existing strict rules. By-reference and variadic first parameters are invalid. By-reference suffix parameters remain unsupported.

The first parameter never enters custom resolver classification or execution. Registered DI attributes on it, including registered metadata-only attributes, are invalid; ordinary metadata such as `SensitiveParameter` is allowed. Late discovery of forbidden first-parameter metadata or a changed completed suffix fails before the body, without replaying completed handlers.

The suffix retains original positions and all normal resolver behavior: provider attributes, custom resolvers, supplied values where permitted, autowiring, defaults, nullable fallback, native internal-default omission, and variadics. A custom suffix resolver sees the actual semantic value at `ParameterResolutionContext::$resolved[0]`.

A zero-parameter callback receives zero native arguments. A zero-parameter transformer still requires a valid input source. All forms invoke once through `PreparedCallable` and the existing callable invoker; there is no second DI pass over the reserved parameter.

`Transform` does not inherit the outer constructor, DTO, or request argument bag. An outer field sharing a suffix parameter name cannot replace its dependency, and no current HTTP request is implicitly forwarded. Resolve needed services through the suffix's own DI rules.

## 5. Callback `SetUp`

The attribute remains readonly, non-final and repeatable:

```php
public function __construct(
    public string|Closure $method,
    public array $params = [],
) {}
```

`method:` remains the named constructor argument for either representation. Strings continue to name instance methods; they are not global function names or service IDs. Use a first-class closure reference for a named function or declared static helper.

```php
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\SetUp;

final class Formatter
{
    public function format(string $value): string { return strtoupper($value); }
}

#[SetUp(
    method: static function (Worker $instance, Formatter $formatter, string $label = 'ready'): void {
        $instance->label = $formatter->format($label);
    },
    params: ['label' => 'started'],
)]
final class Worker
{
    #[Inject]
    public readonly Formatter $formatter;

    public string $label;
}
```

The callback runs after property injection has committed. Repeated method and callback hooks retain declaration order. Its return value is ignored, including a returned object: setup cannot replace the constructed instance. Shared `get()` still initializes once; `make()` still creates independently; lazy objects defer setup until initialization.

### Supplied setup parameters

The callback branch validates explicit `params` before any value unwrapper runs:

- Every explicit key must be a string. Any integer key, including `0`, is rejected with `InvalidConfigurationException`.
- The first parameter's name is reserved and cannot appear in explicit `params`.
- Ambient position `0` and the first parameter's name are removed from the binding bag. Ambient positions `1` and above remain unchanged; they are not renumbered.
- A matching suffix name is unwrapped with its actual `ReflectionParameter`. Other string keys, including unused names and class type-keys, are still accepted and unwrapped with `null` reflection metadata.
- Explicit public values override ambient public values. Private DI metadata is preserved separately and cannot be overwritten or exposed to custom parameter resolvers.

Unused keys do not become extra native arguments. A zero-parameter callback has no reserved name, but still rejects integer keys and unwraps named values before its empty native call. These restrictions apply to the callback branch; existing method-based setup mapping is unchanged.

Mapped request provenance continues through setup. Raw request keys cannot shadow an explicit suffix source such as `EntryId` or `Env`, whether they match the parameter name or a type-key. This includes named-function callbacks with no declaring class and sources loaded during another suffix dependency's resolution. Diagnostics name the constructed DTO; neither provenance nor that diagnostic owner is added to public extension context.

### Subclass migration

PHP property types are invariant. A subclass that redeclares `public string $method` becomes invalid when the parent property is `string|Closure`. Remove the redundant property declaration, or declare the identical `string|Closure` type if a redeclaration is required. A constructor that merely forwards a string remains valid:

```php
use Componenta\DI\Attribute\SetUp;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class ProjectSetUp extends SetUp
{
    /** @param array<string, mixed> $params */
    public function __construct(string $method, array $params = [])
    {
        parent::__construct(method: $method, params: $params);
    }
}
```

Direct infrastructure consumers must also update `SetUpRunner` construction. Its mandatory third argument is the shared helper, before the existing variadic unwrappers:

```php
$callbacks = new Componenta\DI\Internal\AttributeCallbackInvoker($parameters, $invoker);
$runner = new Componenta\DI\Resolver\Entry\SetUpRunner(
    $invoker,
    $resolutionParameters,
    $callbacks,
    ...$valueUnwrappers,
);
```

Here `$parameters` is a `ParametersResolver`, `$invoker` is a `CallableInvokerInterface`, and `$resolutionParameters` is an `ObjectResolutionParameterStore`. The default factory creates one helper for setup and transformation; applications using the normal factory need no manual wiring. The helper remains internal and has no new public service registration. `SetUpRunner::providedParameters()` now accepts `?ReflectionFunctionAbstract` under its existing `method` argument name, so both method and function suffix metadata reach unwrappers.

## 6. Union autowiring changes selection

Implicit selection supports non-variadic simple unions of named types. Built-in arms do not create service candidates. Class/interface IDs are deduplicated case-insensitively and checked in stable order using the container's existing `has()` contract. Intersections and DNF unions containing intersection arms remain outside implicit discovery; explicitly supplied values still undergo full type validation.

| Available candidate IDs | Result |
| --- | --- |
| None | Leave unresolved; the caller's existing default/null/omission rules apply. |
| Exactly one | Call `get()` once and require the result to satisfy that selected service type and the full target type. |
| More than one | Throw `ResolutionException` with a stable sorted candidate list before constructing any candidate. |

Two interface IDs aliased to the same implementation still count as two candidates. DI does not instantiate them to compare identity. Resolve ambiguity with an explicit value or provider, for example `#[EntryId('primary.clock')] ClockA|ClockB $clock`.

Review nullable/defaulted unions carefully: one newly available arm now wins before the default or null fallback. A default does not resolve ambiguity. Once a selected candidate starts construction, its failure propagates; DI does not silently choose another arm or fall back to null. `has()` can autoload and does not guarantee successful construction.

An explicit `Closure::class` binding participates normally. A selected `Service::class` binding that returns a closure is invalid even when the full parameter type is `Closure|Service`; matching a different arm does not satisfy the selected ID's contract. Candidate availability is recomputed on every resolution, so warmed plans observe service, alias and external-ownership changes.

Opt-in `ClassDefinition` constructor and configured public-method autowiring use the same selection algorithm. Their existing lifecycle and fallback rules remain distinct: no new attribute processing or implicit setup phase is added, and an unresolved argument is omitted. A declared default can then apply natively; a required nullable parameter without a default is still a missing argument. Ordinary reflection creation and DI-aware calls retain their existing nullable fallback.

## 7. Exceptions, retries and callback lifetime

Invalid reserved-parameter declarations or explicit setup keys fail with `InvalidConfigurationException`; incompatible late metadata uses the existing `AttributeCompositionException`. A first value of the wrong type or an unresolved/invalid suffix uses parameter resolution diagnostics. Foreign exceptions inside transformations or setup pass through the existing owning parameter/property/object boundary as `ResolutionException` with their original cause. Ordinary direct `container->call()` target-body exceptions still propagate unchanged.

A failed property transformation does not commit that property's pending value. A failed setup does not publish the object as a successful shared service. These boundaries do not undo external side effects or writes to earlier properties. Lazy initialization can retry under the existing lifecycle; completed work is not replayed within the same attempt to repair a metadata conflict.

Native state is preserved. A freshly materialized inline static closure has fresh local static state. First-class wrappers for named functions/static methods share the referenced function's static state. A constant-held closure remains shared because the constant owns it. DI does not cache runtime callback instances, values, or results to change these rules. Contract fixtures verify repeated creation, lazy retries, and release of otherwise unreachable callbacks, semantic objects and inline static-local objects.

`Init` still produces a property's value from its ordinary DI-aware callable. It does **not** receive the property owner as an implicit first argument. Its old callable forms, explicit parameters, readonly initialization and lazy behavior are retained.

## 8. Platform limits

The tested PHP 8.5.8 engine incorrectly reuses the first consuming trait scope for some attribute first-class references using `self::` or `parent::`; a named private reference can likewise retain the first consumer's visibility result. The native inline-static-closure control resolves each consumer correctly. The defect reproduces without DI.

E1 remains open until a released PHP patch passes the correct per-consumer scope and visibility fixtures and establishes the supported minimum. DI does not rebind callbacks to hide that defect. Capture-free inline static closures and fully qualified public-helper references are within the verified alternatives; do not infer support for every affected trait-relative expression from their success.

The branch's PHP 8.4 compatibility tests cover the library implementation and runtime-created closures. PHP 8.5 subprocess tests cover the new attribute syntax and materialization failures. They are separate evidence from a final platform support declaration.

## 9. Verification references

- [Semantic callback binding contracts](../../tests/Contract/AttributeCallbackInvocationContractTest.php): reserved argument, suffix resolution, one prepared invocation, late metadata and lifetime.
- [Setup callback contracts](../../tests/Contract/SetUpCallbackContractTest.php): lifecycle, explicit parameters, mapped source provenance and constructor-only subclass compatibility.
- [Native callback contracts](../../tests/Contract/Php85AttributeCallableContractTest.php) and [fixtures](../../tests/Fixtures/Php85Callbacks): `Init` compatibility, syntax boundaries, scope/state, lazy retries and the property-invariance migration.
- [Union contracts](../../tests/Contract/UnionAutowiringContractTest.php): candidate selection, ambiguity, warmed mutation and explicit-definition behavior.
- [Normative callback specification](../plans/php85-callbacks.md), [native engine evidence](../audit/2026-11-13-php85-native-evidence.json), and [portable reproduction](../audit/reproductions/Php85Callbacks.py).
