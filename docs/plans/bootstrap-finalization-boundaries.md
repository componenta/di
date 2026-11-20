# Bootstrap finalization: availability and reference-release boundaries

[Русская версия](bootstrap-finalization-boundaries.ru.md)

**Status: proposed; not implemented.** This report records two confirmed boundaries of the DI 6 refactoring branch and proposes separate contract decisions. It does not introduce an availability notification API, a new cleanup phase, or a promise to detect private autoloader readiness. The local convergence and discovery fixes remain in place.

**Recommendation:** require influencing attribute declarations to be available before bootstrap dependencies consume them. Design an optional, explicit availability signal only if a real integration requires dynamic readiness. Separately, introduce a terminal reference-release phase with conservative failure when observable metadata changes before successful container publication. The latter changes failure behavior and needs a deliberate architecture decision.

## 1. Scope, evidence and current guarantees

The recorded probes ran on **PHP 8.4.28 and 8.5.13**, against both immutable snapshots:

| Snapshot | Commit | Role |
|---|---|---|
| Baseline | [`e11d69619fd808da1935b100c752fef7cf8bbf69`](https://github.com/componenta/di/commit/e11d69619fd808da1935b100c752fef7cf8bbf69) | Source before the latest local correctness fixes. |
| Corrected source | [`c49b77c204dae3d25a111195f9cb410c699734c5`](https://github.com/componenta/di/commit/c49b77c204dae3d25a111195f9cb410c699734c5) | Source used in the recorded architecture probes. |
| Reviewed branch head | [`43c464187f80c95802fc36191350ae68b70a469e`](https://github.com/componenta/di/commit/43c464187f80c95802fc36191350ae68b70a469e) | Adds regression coverage; its production `src` tree equals `c49b77c`. |

The corrected and reviewed source tree is `971dc4709d472411e1f08b73f01700b976c9e096`. Both boundaries existed in the baseline; they are not new regressions introduced by the latest fix. Eight recorded probe processes cover two scenarios × two snapshots × two PHP versions. Every process verified the loaded DI declaration/function paths: 135 declarations and six functions for cleanup; 17 declarations and six functions for readiness.

| Probe | Result in all four source/runtime combinations | What this proves |
|---|---|---|
| Hidden readiness | Before: revision 0. First poll: revision 0, readiness changed, first class absent. Second poll: revision 1, first class declared. Loader counts: first name 3, later name 4. | A direct public registry observation cannot see an undeclared name's private readiness change after that name has already been polled. This is not a separate complete Container bootstrap reproduction. |
| Release of bootstrap records | `accepted`; events `retained until proof`, `destructor`; token released; cached dependency `null`, fresh dependency `stdClass`. | A complete build can succeed after cleanup changes attribute semantics that an already cached bootstrap dependency consumed. |

The original evidence manifest was recorded at `2026-11-20T00:54:02.959874+00:00`, SHA-256 `356dff95f82720319dff86de32df978a3df64aee93a7b2498ab96b040c909302`. Original probe SHA-256 values are `efdf812c4ff2d130b50a2bed00684015b6a21332f171c245fecac4fcfe1e48ed` for readiness and `a39cb89fd061722d7c4b94a7539f89d1cf3bef8206c3ea2ab9572f7b7fb92513` for cleanup. The complete observations and portable reproductions below make this report usable without access to those working-session files. Adding this report did not rerun the probes or benchmarks.

### Completed local corrections remain required

[`162072e346a6b369e491e310df404ec31a756df4`](https://github.com/componenta/di/commit/162072e346a6b369e491e310df404ec31a756df4) corrected convergence and reentrant discovery. [`43c4641`](https://github.com/componenta/di/commit/43c464187f80c95802fc36191350ae68b70a469e) added public-path regressions for the completed work. In the reviewed source:

- [`BootstrapResolutionGuard::finish()`](../../src/Internal/BootstrapResolutionGuard.php) keeps accounting active through proof. It repeats when the observed generation or the number of parameter observations needing proof changes. Resolutions using the complete final resolver prefix do not create an endless proof obligation.
- [`AttributeDefinitionRegistry::revision`](../../src/Attribute/Composition/AttributeDefinitionRegistry.php) processes names discovered reentrantly during that poll. Each missing name receives its existing class/interface autoload attempts once in that poll. A final check with autoload disabled notices declarations of earlier names made by later callbacks.
- A name which remains undeclared is not polled indefinitely just because its loader might become ready later. Stable reentrant resolution and permanent missing markers must still terminate. An arbitrary application callback graph that continually introduces new names has no general termination guarantee.

These corrections cover observable declarations and meaningful new observations. Neither correction establishes visibility into private readiness or validates changes caused by releasing the proof itself. [The availability plan](attribute-availability-performance.md) describes the measured polling cost and the separate B2 synchronization proposal.

## 2. Boundary A: private readiness after the last poll of a name

### Problem and standalone reproduction

A loader is asked for `First` and returns a miss. Later in the same registry poll it is asked for `Later`; that callback changes application state so `First` could now load. It does not declare `First`, change the autoloader list, or notify the registry. The registry's final check sees no new declaration. The next independent poll invokes the loader again and discovers `First`.

From a repository checkout with dependencies installed, save this snippet as a PHP file and run it from the repository root with PHP 8.4+ in a **fresh process**. It uses only Composer autoloading and public registry methods; no private reflection or modified source is required. Keep the namespace isolated from other probes.

```php
<?php
declare(strict_types=1);

namespace Audit21HiddenReadiness;

require getcwd() . '/vendor/autoload.php';

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Inject;

$registry = new AttributeDefinitionRegistry();
$first = __NAMESPACE__ . '\\First';
$later = __NAMESPACE__ . '\\Later';
$registry->definition($first);
$registry->definition($later);
$before = $registry->revision;
$ready = false;
$loads = [$first => 0, $later => 0];
$loader = static function (string $name) use ($first, $later, &$ready, &$loads): void {
    if (array_key_exists($name, $loads)) {
        ++$loads[$name];
    }
    if ($name === $first && $ready) {
        class_alias(Inject::class, $first);
    } elseif ($name === $later) {
        $ready = true; // No declaration and no notification.
    }
};
spl_autoload_register($loader);
try {
    $firstPoll = $registry->revision;
    $declaredAfterFirst = class_exists($first, false);
    $secondPoll = $registry->revision;
    echo json_encode([
        'before' => $before,
        'first_poll' => $firstPoll,
        'readiness_changed' => $ready,
        'declared_after_first' => $declaredAfterFirst,
        'second_poll' => $secondPoll,
        'declared_after_second' => class_exists($first, false),
        'loader_calls' => $loads,
    ], JSON_THROW_ON_ERROR), "\n";
} finally {
    spl_autoload_unregister($loader);
}
```

The recorded result is:

```json
{"before":0,"first_poll":0,"readiness_changed":true,"declared_after_first":false,"second_poll":1,"declared_after_second":true,"loader_calls":{"Audit21HiddenReadiness\\First":3,"Audit21HiddenReadiness\\Later":4}}
```

The original executed probe used the repository's source-remapping benchmark autoloader to select immutable snapshots. The example above changes only that loading setup to the checkout's Composer autoloader. The result establishes a registry observability boundary. The risk that later resolution can use different semantics follows from the supported late-discovery behavior; this small probe alone does not demonstrate a particular complete bootstrap ordering failure.

### Why another polling loop is not a complete fix

There is always a last callback in a finite sequence of opaque callbacks. That callback can change the readiness of an earlier name without declaring it. Another unconditional pass moves that last opportunity; it cannot prove that arbitrary application state is stable. Unbounded retries do not terminate for ordinary permanent misses and can repeat user effects. The intermediate bootstrap approach in [`4abca4a`](https://github.com/componenta/di/commit/4abca4a5eb03643fe4c0f01778e0f0f59f580010) already required the convergence correction in `162072e`; increasing the loop condition must not restore that defect.

The architecture question is which event makes availability authoritative and who must publish it. A private loader flag has no such event in the current interface. `supports()` is an existing classifier, not an implicit notification mechanism.

### Options, recommendation and compatibility

| Option | Guarantee / benefit | Cost and compatibility |
|---|---|---|
| Actual preload before consuming bootstrap dependencies | Influencing declarations exist before their semantics are used; a finite, inspectable ordering rule. | Applications must move declarations or integration setup earlier. Merely registering an autoloader or setting a private ready flag is insufficient. |
| Optional explicit availability notification | Cooperative readiness transitions advance an observable monotonic generation. Final proof can detect a change without executing an arbitrary loader while reading that generation. | New integration contract: owner, transition, ordering, failure and reentrancy rules. Nonparticipating loaders still have the current limit. |
| Freeze semantics on first use | Later availability never changes the already selected target semantics. | Broad change to current late discovery and fresh `make()` behavior; it also requires defining the scope and lifetime of a frozen target. |
| Keep the present limit and only document it | No runtime or integration changes. | No stronger guarantee for opaque readiness; application ordering remains its responsibility. |

**Recommended minimum:** document actual preload as the supported preparation for bootstrap dependencies. Consider an optional signal only with a concrete dynamic integration requirement. Do not add a global registry, permanent miss cache, or whole-container cache reset. Retain late discovery for supported runtime paths and preserve shared-service identity.

### Conditional implementation plan

1. State in EN/RU extension and migration documentation what must be declared before an extension factory consumes a bootstrap dependency. Distinguish actual declaration, a newly tracked name, and private readiness of an already tracked name.
2. If notifications are selected, assign one per-container availability owner and specify how process-wide declaration/loader state is reflected into each participating container. Decide who can signal and how an integration reaches that owner; no new public API shape is selected by this report.
3. Make the signal a monotonic record of an actual transition, published before dependent resolution can observe that transition. Do not increment on every miss or every revision read. Specify the ordering around callbacks and Fiber suspension; reading the established generation must execute no user callbacks.
4. Include the signal in semantic proof and plan invalidation. A notified incompatible transition during bootstrap must cause rejection before successful publication; completed handlers, constructors and resolved values must not be replayed.
5. Cover direct registry/processor access, nested calls, failure, lazy/proxy entry and interaction with B2 before reducing any existing refresh. Keep the current polling behavior until its replacement obligations are implemented together.

### Acceptance criteria

- Preloaded influencing attributes yield coherent bootstrap/fresh behavior and preserve the same shared instance.
- A cooperative, notified incompatible transition is rejected before successful build, with actionable diagnostics.
- A permanent missing marker and stable reentrant `make()` terminate; actual declarations by later loaders and names discovered during a poll remain visible in that poll.
- Class, alias, interface, fallback-loader exception, cold/warm, direct API and Fiber cases preserve the selected contract.
- Constructors, handlers and invocation values run once for the original work; no cache flush recreates an already issued shared service.
- The absence of both declaration and notification remains an explicit limit. A passing notification test must not be described as solving arbitrary private readiness.

## 3. Boundary B: metadata changes while releasing bootstrap proof records

### Problem and standalone reproduction

Bootstrap creates and caches an `Owner` while `LateInject` is unavailable, so its dependency remains `null`. A subsequent call takes a closure that captures a `Token`. A proof record retains the parameter's runtime reflection context and, through it, the closure and token. After the final semantic check, releasing records releases the token; its destructor declares `LateInject` as an alias of `Inject`. Build succeeds. The cached owner keeps its old dependency, while a fresh owner receives `stdClass`.

The following uses the public `ContainerFactory`, `Container::get()`, `call()` and `make()` paths. Save it as a PHP file and run it from the checkout root with dependencies installed in a **fresh PHP 8.4+ process**:

```php
<?php
declare(strict_types=1);

namespace Audit21BootstrapCleanup;

require getcwd() . '/vendor/autoload.php';

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;

#[\Attribute(\Attribute::TARGET_ALL)]
final class Extra {}

final class Owner
{
    #[LateInject]
    public ?\stdClass $dependency = null;
}

final class Trace
{
    public static array $events = [];
    public static ?\WeakReference $token = null;
}

final class Token
{
    public function __destruct()
    {
        Trace::$events[] = 'destructor';
        class_alias(Inject::class, __NAMESPACE__ . '\\LateInject');
    }
}

try {
    $definitions = new DependencyDefinitions([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (Container $container): AttributeDefinition {
                $container->get(Owner::class);
                $token = new Token();
                Trace::$token = \WeakReference::create($token);
                $callback = static fn(string $value = 'ready'): string => $value . ':' . $token::class;
                $container->call($callback);
                unset($callback, $token);
                Trace::$events[] = Trace::$token->get() === null
                    ? 'released inside factory' : 'retained until proof';

                return new AttributeDefinition(Extra::class);
            },
        ],
    ]);
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        $definitions,
    )->container;
    if (!$container instanceof Container) {
        throw new \RuntimeException('Unexpected container implementation.');
    }
    echo json_encode([
        'status' => 'accepted',
        'events' => Trace::$events,
        'released' => Trace::$token?->get() === null,
        'cached' => get_debug_type($container->get(Owner::class)->dependency),
        'fresh' => get_debug_type($container->make(Owner::class)->dependency),
    ], JSON_THROW_ON_ERROR), "\n";
} catch (\Throwable $exception) {
    echo json_encode([
        'status' => 'failed',
        'events' => Trace::$events,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR), "\n";
}
```

The recorded result is:

```json
{"status":"accepted","events":["retained until proof","destructor"],"released":true,"cached":"null","fresh":"stdClass"}
```

The executed probe used [`Tests\Support\ContainerBuilder`](../../tests/Support/ContainerBuilder.php), which routes the same definition section and empty configuration through public `ContainerFactory::create()`. The portable example expands that helper and uses the checkout's Composer autoloader. Those setup adaptations are documented, not claimed as another executed measurement.

### Why this needs a lifecycle decision

[`BootstrapResolutionGuard`](../../src/Internal/BootstrapResolutionGuard.php) validates semantics and then disables accounting and clears its records in `finally`. The proof and the release of objects owned by the proof lie on different sides of the effective completion boundary. Clearing arrays is not enough: a local `foreach` variable can keep the last record alive until the method scope ends.

Keeping the original reflectors for another check delays the same destructor again. Reconstructing a closure target from a function name loses its runtime context; custom classifiers may depend on the supplied reflection object. Recreating a cached service or replaying completed handlers changes identity and repeats user effects. Failing a build that previously succeeded is a compatibility decision even when it closes a confirmed coherence gap.

The intended guarantee must be scoped to synchronous cleanup owned by container construction. Arbitrary future GC, destructors or Fiber activity after publication cannot be made atomic with an earlier `create()` return. The container must also not retain runtime closures indefinitely just to postpone the issue.

### Options and recommendation

| Option | Benefit | Cost / limitation |
|---|---|---|
| Document a prohibition on metadata changes during proof cleanup | Small documentation change; no new runtime state. | Violations still produce the observed successful-but-inconsistent build. |
| Explicit terminal cleanup followed by a check without user callbacks | Detects observable changes caused by synchronous release before success; preserves shared identity and avoids replay. | Conservative rejection can include unrelated changes. Requires a lifecycle/publication boundary and a defined failure policy. |
| Detached semantic descriptors and precise revalidation after release | Could accept unrelated changes and report exactly which completed semantics changed. | Larger redesign of proof representation and custom classifier contracts; must preserve closure context semantics without retaining closure objects. |

**Recommended minimum strict behavior:** select the terminal cleanup phase with conservative failure. Accepting unrelated changes during this phase is a separate extension requiring evidence that detached descriptors can prove their irrelevance. The existing allowance for unrelated declarations during normal execution need not be silently reinterpreted as an allowance during this new terminal phase; document the distinction.

### Conditional implementation plan

1. Specify the phases: active recording, semantic proof, release of proof references, final observation, publication. On success and failure, define which component owns record disposal and exception propagation.
2. After successful proof, retain only scalar generation/checkpoint data needed by finalization. Disable accounting and release all strong references owned solely by proof, including attribute records, parameter targets, temporary resolver references and last loop locals. End the scope that held them before the terminal observation.
3. Add an outer check in [`ContainerFactory`](../../src/ContainerFactory.php) after that scope returns. It must observe already declared tracked classes/interfaces with autoload disabled and any selected explicit notification generation. Reading ordinary `revision` is insufficient: it can run loaders and create another user-code boundary.
4. If observable availability changed during release, reject with `InvalidConfigurationException` before returning a successful `ContainerValue`. Preserve the original cause when cleanup itself throws. Do not clear shared caches, replace already issued objects, or rerun their construction.
5. Place all potentially effectful preparation before the final checkpoint, or prove that remaining work cannot execute user code or release relevant temporary references. Review the current `get(Config::class)` after `finish()`, construction of the return value and factory-scope retirement explicitly. The final check must precede successful publication and follow all cleanup it promises to cover.
6. Update EN/RU lifecycle/migration documentation and focused contracts before broad implementation. If optional readiness signals are not selected, state that this phase sees actual declarations and existing generation changes only; it does not solve Boundary A.

### Benefit, cost and compatibility

The benefit is a concrete publication guarantee: an observable incompatible change caused by the container's own synchronous retirement no longer hides behind a successful build. Captured closures are released before return, shared identity stays intact, and user work is not replayed.

The minimum strict policy rejects any observed generation change during cleanup, including a potentially unrelated one. This is deliberately more conservative than a precise semantic comparison. Documentation must identify affected applications and the remedy: declare influencing attributes before using bootstrap dependencies, and avoid changing availability from retirement callbacks. No numeric latency or memory benefit is established by this report. A check over tracked missing names adds work; retaining or copying runtime proof state is not an acceptable shortcut.

Failure does not roll back arbitrary application effects or invalidate a container reference an extension already leaked externally. Define the guarantee as absence of a successful factory return, and document cleanup exceptions consistently with the existing factory wrapping behavior.

### Acceptance criteria

- The reproduced cleanup case fails before successful `create()` publication. With an added construction counter, `Owner` is constructed once; no handler or value resolution is replayed.
- A destructor without metadata changes, including an ordinary reentrant `get()`, succeeds and retains the same shared object.
- A fixture whose final local loop record is the last owner releases its captured token before the final observation. Clearing only the arrays must not satisfy this test.
- Weak references show no proof-owned closure/target retention after either success or failure. Deferred callbacks retain only the lifetime their own supported contract requires.
- A class, alias or interface declared during cleanup is observed without invoking autoloaders. If explicit signals are selected, signaled transitions during cleanup are observed as well.
- Cleanup exceptions preserve their cause and useful bootstrap context. No success result escapes after a failed terminal check.
- Conservative rejection of an unrelated observed change is covered and documented. Any later relaxation has an independent precision proof.
- PHP 8.4/8.5, nested bootstrap calls, stable reentry and relevant Fiber handoffs preserve the selected synchronous boundary. Later unrelated GC activity is not presented as covered by it.

## 4. Decisions required before implementation

Boundary A needs a supported application ordering rule and, only if justified, a cooperative availability contract. Boundary B needs a failure policy and an explicit owner for cleanup/publication. They should remain separate decisions: a signal can make readiness observable without fixing reference-release timing, and a terminal check can detect declarations without seeing private loader state.

Implement neither a whole-container reset nor speculative retries as a substitute. Preserve the completed local regression fixes, the current public-path tests, and the performance evidence. Once the chosen contracts are recorded, implementation can proceed in coherent slices that each include the affected owner, consumers, diagnostics, and their acceptance tests.
