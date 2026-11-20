# Invocation context for legacy relative callables

**Status (2026-11-20): proposed; not implemented.** Audit result **A20-P3** is a confirmed compatibility limitation. A violation of an explicit support promise or a regression introduced by DI 6 has **not** been established. The architectural option in this report is conditional on a real requirement for parity with deprecated PHP forms. [Русская версия](relative-callable-context.ru.md).

## Recheck after report preservation

The subsequent [evidence recheck](audit-architecture-recheck-2026-11-20.md) records fresh executions on PHP 8.4.28 and 8.5.13 after report preservation, with [machine-readable output](audit-architecture-recheck-2026-11-20.json). Historical revision labels and statements about what was not executed during the initial preservation step remain historical; the recheck supplies the later evidence. No architecture option is implemented by that verification.

## 1. The observed difference

For an inherited method, PHP distinguishes its lexical declaring class from its actual called class and, for an instance call, its receiver. DI's ordinary parameter acceptance path knows the reflected parameter's declaring class. It does not carry all three parts of the current invocation into `TypeHints::matchesCallable()`.

The distinction matters for some deprecated context-dependent string/array values supplied to a `callable` parameter. In the minimal cases below, native PHP and an invocation with already resolved arguments execute the callback, whereas ordinary `Container::call()` rejects its argument before the receiving method body.

| Target declared on `Owner` | Supplied argument | Native PHP | Ordinary DI | `PreparedCallable` control |
| --- | --- | --- | --- | --- |
| `[Child::class, 'accept']` | `'static::childPublic'` | `child-public` | `ResolutionException` before body | `child-public` |
| `[new Child(), 'acceptInstance']` | `['self', 'childPublic']` | `child-public` | `ResolutionException` before body | `child-public` |

Both native forms emit `E_DEPRECATED` on the recorded PHP **8.4.28** and **8.5.13** runtimes. The diagnostic captures warnings instead of turning them into exceptions; otherwise a deprecation handler could mask the acceptance difference. Native and prepared routes reach both the receiving parameter check and `call_user_func()` in the body. The ordinary DI route stops at its earlier parameter validation.

This concerns callable **values**, not the new expression syntax that produces a `Closure`. The [PHP 8.2 migration guide](https://www.php.net/manual/en/migration82.deprecated.php) lists relative callable forms as deprecated. PHP 8.5 first-class references written in attribute arguments, such as `self::method(...)`, produce native closures and belong to the implemented [callback plan](php85-callbacks.md). Its E1 platform verification is a separate matter; satisfying E1 does not supply legacy string/array invocation context.

## 2. Evidence and its limits

Discovery used baseline [`983871d4fc75fb607a2a92186cf4f64428f290e2`](https://github.com/componenta/di/commit/983871d4fc75fb607a2a92186cf4f64428f290e2). A differential matrix recorded **48 acceptance differences out of 1,551 cases per PHP runtime**, with the same differences on both versions. Every difference accepted by native PHP and rejected by ordinary DI involved a deprecated form. In the broad matrix, some union cases checked acceptance only; do not describe all 1,551 as executed callback bodies. The two minimal cases here execute `call_user_func()` in the receiving body and were independently checked with five controls in the audit ending at [`e11d69619fd808da1935b100c752fef7cf8bbf69`](https://github.com/componenta/di/commit/e11d69619fd808da1935b100c752fef7cf8bbf69).

The relevant `TypeHints`, `ParameterTarget`, `CallableExecutor` and `PreparedCallable` source is unchanged between that audit and [`43c464187f80c95802fc36191350ae68b70a469e`](https://github.com/componenta/di/commit/43c464187f80c95802fc36191350ae68b70a469e). The links below pin the latter source. Saving this plan does not claim a new execution of the historical matrix or a verified result on every PHP patch.

| Source evidence at `43c4641` | Responsibility and consequence |
| --- | --- |
| [`TypeHints::matchesCallable()`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Resolver/TypeHints.php#L170-L183) | Accepts an existing `Closure` directly; checks other forms using a static closure bound to the user-defined declaring class. The signature receives no actual called class or receiver. |
| [`ParameterTarget`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Resolver/Target/ParameterTarget.php) | Stores reflected declaration metadata. `accepts()` passes the declaring class to `TypeHints`; this reusable metadata is not a place for invocation receivers. |
| [`CallableExecutor`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/CallableExecutor.php) | Prepares ordinary callable arguments through `ParametersResolver`. Named/method plans can be cached; closures are prepared per invocation to avoid retaining their captures. |
| [`PreparedParameterPlan`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Internal/Resolver/Parameter/PreparedParameterPlan.php) | Holds reusable targets and resolution structure, not the receiver of a particular call. |
| [`PreparedCallable`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/PreparedCallable.php) | Declares that arguments have already been resolved. It avoids another DI parameter pass and invokes the original target through native dispatch. |

The existing documents preserve runtime callable representations and native closure scope; no separate promise of full parity for every deprecated relative value was identified. Consequently this report does not label the observation a proven DI 6 regression or a general failure of ordinary callables.

## 3. Self-contained public reproduction

Save the PHP block as `relative-callable-reproducer.php` outside a checkout with development dependencies, then run:

```sh
php relative-callable-reproducer.php /absolute/path/to/di
```

The fixture uses the repository's [`tests/bootstrap.php`](../../tests/bootstrap.php) and [`ContainerBuilder`](../../tests/Support/ContainerBuilder.php) only for ordinary wiring. The three observations are native invocation and the public `Container::call()` API with or without the prepared adapter. The original seven-case diagnostic is reproduced below with a checkout-path argument so no external workspace files are needed.

```php
<?php
declare(strict_types=1);
$repository = realpath($argv[1] ?? getcwd());
if ($repository === false) {
    throw new LogicException('Pass the checked-out DI repository as the first argument.');
}
require $repository . '/tests/bootstrap.php';

class Audit20CallableOwner
{
    private static function lexicalPrivate(): string { return 'owner-private'; }
    public static function accept(callable $callback): string { return call_user_func($callback); }
    public function acceptInstance(callable $callback): string { return call_user_func($callback); }
}
class Audit20CallableChild extends Audit20CallableOwner
{
    public static function childPublic(): string { return 'child-public'; }
    protected static function childProtected(): string { return 'child-protected'; }
    private static function childPrivate(): string { return 'child-private'; }
}
function observation(Closure $operation): array
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = ['level' => $level, 'message' => $message];
        return true;
    });
    try { return ['value' => $operation(), 'warnings' => $warnings]; }
    catch (Throwable $error) { return ['error' => $error::class, 'message' => $error->getMessage(), 'warnings' => $warnings]; }
    finally { restore_error_handler(); }
}
$container = (new Componenta\DI\Tests\Support\ContainerBuilder())->build();
$child = new Audit20CallableChild();
$cases = [
    'static runtime class' => [[Audit20CallableChild::class, 'accept'], 'static::childPublic'],
    'instance runtime receiver' => [[$child, 'acceptInstance'], ['self', 'childPublic']],
    'lexical private control' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableOwner::class, 'lexicalPrivate']],
    'protected control' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableChild::class, 'childProtected']],
    'child private rejection control' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableChild::class, 'childPrivate']],
    'fully qualified workaround' => [[Audit20CallableChild::class, 'accept'], [Audit20CallableChild::class, 'childPublic']],
    'closure workaround' => [[Audit20CallableChild::class, 'accept'], Audit20CallableChild::childPublic(...)],
];
$results = [];
foreach ($cases as $name => [$target, $value]) {
    $results[$name] = [
        'native' => observation(static fn() => $target($value)),
        'di' => observation(static fn() => $container->call($target, ['callback' => $value])),
        'prepared' => observation(static fn() => $container->call(new Componenta\DI\PreparedCallable($target), [$value])),
    ];
}
echo json_encode(['php' => PHP_VERSION, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
```

Expected recorded results for the two affected cases are in §1. The five controls establish why replacing the lexical scope with the child class is not a valid correction:

| Control argument for `Child::accept` | Native / ordinary DI / prepared result |
| --- | --- |
| `[Owner::class, 'lexicalPrivate']` | All return `owner-private`, without a deprecation. Lexical private access must remain available. |
| `[Child::class, 'childProtected']` | All return `child-protected`, without a deprecation. |
| `[Child::class, 'childPrivate']` | Native/prepared throw `TypeError`; ordinary DI throws its parameter `ResolutionException`. All reject access. |
| `[Child::class, 'childPublic']` | All return `child-public`, without a deprecation. |
| `Child::childPublic(...)` | All return `child-public`, without a deprecation. |

The fixture uses prefixed class names to avoid accidental collisions; `Owner` and `Child` in the tables abbreviate them. `PreparedCallable` is a diagnostic control with a **fully resolved native argument list**. It intentionally bypasses DI argument injection, attributes and transformation. It is not a drop-in workaround for an invocation that still needs those operations, and native rejection remains possible.

## 4. Why a local scope substitution is unsafe

| Part of invocation | Inherited static example | Inherited instance example | Required owner |
| --- | --- | --- | --- |
| Lexical declaring scope | `Owner` | `Owner` | Reflected declaration metadata. |
| Actual called class | `Child` | `Child` | The current invocation. |
| Receiver | None | The current child object | The current invocation only. |

Binding the acceptance closure to `Child` instead of `Owner` does not preserve the lexical private/protected controls. Saving the child object in a `ParameterTarget`, prepared plan or shared cache risks retaining its application/request graph and mixing calls that reuse the same method metadata. A static closure binding also does not automatically provide independently selectable lexical and late-static classes.

Full parity would therefore require a design for passing the actual invocation context without changing lexical access. It would touch ordinary calls, inherited static/instance methods, constructors, method and callback `SetUp`, callback binding, variadic parameters and direct single-parameter APIs. An entry point receiving only a `ReflectionParameter` has no receiver to recover implicitly; its available context and fallback policy must be explicit.

Do not construct another receiver just to check a value, rebind the user's callback, weaken callable validation globally, or synthesize executable code to imitate a PHP invocation. These approaches add side effects or alter the very visibility/lifetime semantics being preserved. No security or performance benefit from such an expansion has been demonstrated.

## 5. Options and proposed recommendation

| Option | Benefit | Cost, compatibility and limits |
| --- | --- | --- |
| **A. Document the current support boundary** | Makes the deprecated-context limitation explicit and shows portable inputs while preserving current behavior. | EN/RU documentation and examples; no new hot-path state or public signatures. Do not ban relative forms that already work or narrow `Init`/general runtime callable APIs. |
| **B. Invocation-local context for selected legacy parity** | Could support the affected legacy forms where actual called class/receiver matter. | Native-oracle prototype, new context propagation, public/internal API review, lifetime and concurrency proof. Scope and version support must be selected explicitly; parity is not assumed. |

**Proposed recommendation:** choose A unless an identified consumer needs B. Recommend a fully qualified function/class callable, or a first-class `Closure` created in a scope where the requested method is accessible. For the public helper in the fixture, both alternatives continue to use ordinary DI resolution:

```php
$container->call([Audit20CallableChild::class, 'accept'], [
    'callback' => [Audit20CallableChild::class, 'childPublic'],
]);
$container->call([Audit20CallableChild::class, 'accept'], [
    'callback' => Audit20CallableChild::childPublic(...),
]);
```

These expressions reuse the container and prefixed class from the fixture. A closure for a private helper must be created in its valid native scope; writing a fully qualified name outside that scope does not grant private access.

Option A clarifies compatibility rather than fixing the two relative examples. Publishing this proposal alone does not select a new normative API contract. If B becomes necessary, state exactly which legacy forms and receiving routes are supported before introducing context, and preserve A's recommended modern inputs.

## 6. Proposed work plan

### Slice 1 — Specify the boundary and consumer need

Update the public EN/RU callable documentation and [callback migration guide](../migrations/di6-callbacks.md) with the two examples, native deprecation boundary and supported alternatives. Cross-reference this report from the callback plan; preserve the existing native PHP 8.5/E1 status. Record whether an actual consumer requires exact legacy parity and which entry points it uses.

**Done when:** the documented callable representations are not mistaken for a universal promise of late-static-context parity, no currently working input is prohibited, and the distinction between ordinary DI and prepared arguments is explicit. No runtime change is required for A.

### Slice 2 — Native-oracle prototype, only if B is selected

Compare actual PHP invocation against a candidate validation approach using all three context components. Begin with the two reproductions and five controls. Expand to inherited targets, parent/self/static forms, static and instance dispatch, closures, methods with different visibility, callable unions and variadics. Separate type acceptance from execution of the callback body and record deprecations independently of exceptions.

**Done when:** a PHP-supported approach demonstrates the selected parity without changing lexical visibility, creating another receiver, repeating callbacks, or generating executable code. If the static route cannot meet the contract, narrow the proposed support explicitly or retain A; do not merge a partial implementation advertised as full parity.

### Slice 3 — Define and propagate invocation-local context

Inventory the owners before implementation: `CallableExecutor` for ordinary targets, `ParametersResolver` for argument execution, object construction and `SetUpRunner` for lifecycle targets, and `AttributeCallbackInvoker` for the binder. Reuse the existing resolution loop. Keep declaration metadata in `ParameterTarget` and shared plans; pass actual called class/receiver only through an invocation-owned structure.

Specify direct single-parameter behavior when no receiver is supplied, default/optional context semantics, exception ownership and extension compatibility. Changes to public `accepts()` or resolution signatures must be assessed as public API changes, not hidden implementation details. Do not place private transport keys in user parameter bags. Preserve actual target snapshots so validation and invocation cannot select different callable routes.

**Done when:** every selected route has an explicit context source and cleanup owner, nested/Fiber calls cannot overwrite each other's context, and no shared metadata retains an invocation object.

### Slice 4 — Prove compatibility, lifetime and cost

Implement the selected approach with public regressions and native controls. Preserve the parameter binder's semantic-first argument, ordinary resolution priorities, union short-circuit behavior, deprecation/error boundaries, and the prepared adapter contract. Exercise failed calls and suspended calls as well as successful calls. Update both language versions and migration instructions to the behavior actually established.

Measure ordinary warm/cold invocation before and after on pinned runtimes, plus the enabled legacy path. Record extra autoloads, reflection work and allocations. Retain applicable normal static analysis, style, supported-runtime suites and focused mutation checks for the changed behavior.

**Done when:** the acceptance matrix passes for selected forms/routes, no ordinary path regresses, and the cost is justified by the stated compatibility need. The only demonstrated benefit claimed for B should be the selected legacy compatibility.

## 7. Acceptance matrix

This matrix specifies future work. The historical seven-case diagnostic is evidence, not a new committed regression suite.

| Boundary | Required observable evidence |
| --- | --- |
| Two affected examples | A documents the difference; B matches the selected native acceptance and callback-body results on each qualified PHP version. |
| Visibility controls | Lexical `Owner` private and child protected stay accepted; child private stays rejected. Fully qualified public values and first-class closures keep working. |
| Ordinary forms | Functions, static/object methods, invokable objects, magic-dispatch cases and closures retain their existing behavior and callable identity. No broad API narrowing. |
| Context sources | Inherited static/instance calls, constructors, both setup paths, binder suffixes, variadic and direct single-parameter routes follow their explicitly documented context policy. |
| Union and autoload | A non-callable union arm can still accept a value before callable inspection. No unnecessary extra autoload, target execution or receiver construction. |
| Diagnostics | Deprecations are recorded without masking acceptance; source/value validation errors retain their DI owner, while explicit target-body exceptions retain their current boundary. Do not promise identical native and DI exception classes. |
| Cache parity | Cold/warm and cached/non-memoized plans give the same supported behavior without sharing receiver state. |
| Reentry and Fibers | Overlapping calls with different child classes/receivers retain their own context across suspension, exceptions and recovery. |
| Lifetime | `WeakReference` controls show receivers, captures and argument graphs are released after callers, exception traces and any live invocation owners are released. No receiver is retained by shared plans. |
| Existing PHP 8.5 syntax | Native expression/scope/lifetime controls and the separately qualified E1 behavior remain intact; legacy string/array work must not rebind native closures. |
| Performance | Compare unchanged ordinary call paths and selected legacy cases with identical settings; report measured overhead and avoid claiming an unmeasured speedup. |

The plan has no dependency on dynamic external-provider ownership or a general object-lifecycle refactor. The decision is whether a specific deprecated compatibility requirement justifies carrying additional per-call context. Until that decision and proof exist, the ordinary implementation and its current scope/lifetime boundaries remain the baseline.
