# Attribute availability polling: evidence and design plan

[Русская версия](attribute-availability-performance.ru.md)

**Decision:** retain the corrected polling behavior in the current 5.x runtime. Do not implement a broad availability optimization from this plan. Fix independently demonstrated coherence defects locally. If application workloads justify changing the synchronization contract later, prefer B2: explicit availability refresh and pure generation observation within the existing owners. C is not selected. No production optimization slice is `implementation-ready`; the conditional B2 work remains `blocked` by the contract and qualification requirements below.

The November 13 audit completed a bounded autoloader comparison, a source-level checkpoint map, and an isolated prototype of A. A reduced measured miss counts but changed observable discovery and exception timing, so it was not adopted. The same-poll cache-coherence defect found during that experiment was fixed separately in `996187f`; that completed correction preserves both autoload attempts.

## Objective and scope

Determine when repeated autoload work caused by unresolved optional attributes justifies a synchronization change, and identify the smallest eligible direction. Any eventual optimization must preserve the selected late-discovery contract, ordered execution, and detection of incompatible changes after part of a resolution has completed. The affected behavior spans class creation, prepared parameters, request mapping, object phases, bootstrap, and deferred initialization. Cached shared-service retrieval is a control case.

The finding concerns the existing PHP 8.4+ runtime. It does not require the PHP 8.5 callback-attribute refactor, an AOT compiler, a persistent metadata cache, or a new public registration API.

## Readiness and evidence boundary

The preserved semantics and settled design constraints below are normative for the current behavior-preserving scope. Retaining corrected polling is the current decision; preferring B2 for a justified future change does not select its production contract or authorize its implementation. G2/G3 describe conditional, decision-only investigations. Their results must first revise or supersede the normative specification before dependent production slices are derived.

| Scope | State | Next step |
|---|---|---|
| Baseline, isolated A comparison, G1 observations and checkpoint inventory | Completed evidence | Reuse the recorded results; do not repeat G1 merely to reopen the same decision. |
| Independent P11 correctness fix | Completed and verified | Retain same-poll coherence and its regression proof. It is not an outstanding optimization slice. |
| G1 compatibility decision for current 5.x | Settled: retain corrected behavior | Keep discovery, same-poll coherence, and loader failure observations. A future reduction in polling needs an explicit revised contract. |
| G2 synchronization contract | Blocked for conditional B2 work | First justify the workload need and any revised G1 contract; then complete B2's owner/checkpoint/failure obligations using the impact map. |
| G3 candidate qualification | Blocked by the prerequisites of G2 | Perform only the bounded isolated B2 comparison below if its stated preconditions are satisfied. |
| Production optimization | Not selected for current 5.x | Derive executable slices only from an updated specification with settled target semantics and replacement obligations. |

The first audit's corrected runtime used for final evidence is `32b1142ede099e398cba03961372584aef069e69`. The A measurements use historical source `65da4acc18b19989041a2813dbc92f2f474b7177`, before P11. That final evidence contains 28 observation processes and a small availability smoke: class/alias discovery returns revision 1 in the first poll, existing miss counts remain 22/176 per creation, and clean/shared controls remain zero. These checks do not turn the historical A timings into a later-source performance comparison. The completed P11 correction belongs to the existing 5.x runtime; it is not deferred to a major release or a future performance-policy decision.

## Practical necessity and preferred direction

The proven bottleneck has a specific trigger: an inspected annotation names a class or interface which remains unavailable. It is not caused by the mere presence of attributes, nor by every cache hit. The registry records only unresolved names; an exact registered definition is a direct lookup, and an already loaded unrelated attribute is not added to the unresolved set. A container which has never observed a missing annotation has no repeated missing-name autoload work to remove. These distinctions follow from [`definition()` and `revision`](../../src/Attribute/Composition/AttributeDefinitionRegistry.php), not an assumption about how frequently an application uses attributes.

| Workload | What the current code does | Consequence for the decision |
|---|---|---|
| Known DI attributes | Built-in definitions are registered during container construction; exact names use the definition lookup. | Valid DI attributes alone do not trigger the unresolved-name multiplier. Handler execution and native argument construction remain real work. |
| Installed, loaded foreign attributes | Classification can find no DI definition, but the loaded class/interface is not retained as unavailable. | Ignoring an unrelated, available annotation does not create persistent polling contamination. Initial discovery still has a cost. |
| Absent optional integration attribute | Its name remains in the container's unresolved set until a later observation discovers it. | Later metadata checks for unrelated `make()` and `call()` operations repeatedly probe that name. Long-lived containers with many creations are the affected workload. |
| Warm shared-service `get()` | The local resolved cache can return the existing instance without entering attribute planning. | This optimization does not improve the controlled shared-cache hit. User callbacks in external containers are outside that control. |
| Repeated root creation with autowired services | The root is new, while [`AutowireByTypeResolver`](../../src/Resolver/Parameter/AutowireByTypeResolver.php) obtains dependencies through `has()`/`get()`. | Warm children are shared. Multiplying an empty-class `make()` result by every node of the original graph would misstate the ongoing work. |
| Explicitly fresh graph | A caller can use `make()` for every node and pass the new children explicitly. | More creation and parameter boundaries expose more polling; this is a distinct workload from ordinary shared dependency resolution. |
| Custom composition rules | Registering a custom rule disables semantic plan memoization so current state and arguments remain observable. | Repeated discovery/validation is deliberate. An availability change must not restore stale rule-result caching, and it cannot remove the rule's own work. |
| New container and first graph | Configuration/bootstrap, initial discovery, plan construction and first service creation all contribute. | Setup must be measured separately from a warm creation loop. The missing-name benchmark alone does not establish startup or request-level benefit. |

The decision is therefore **no broad performance refactor for the current 5.x scope**. The evidence establishes a real pathological workload, not its prevalence or share of an application's latency. Local cache-coherence corrections remain necessary regardless of whether an application encounters that workload. The workload reassessment below reports ordinary valid-attribute paths, loaded foreign attributes, missing optional names, shared/fresh graph identity, custom-rule controls, and cold setup separately; a faster missing-name loop is insufficient evidence for a general library speedup.

If an application does spend material time on repeated unresolved-name polling, **B2 is the preferred future direction**. Keep the existing per-container registry as the sole availability owner; separate the operation which can execute autoloaders from a pure observation of the generation already established. Consumers must publish or execute a plan together with the generation and resolver slots which actually produced it. A later refresh cannot retroactively mark an unexecuted handler as completed. Making synchronization explicit helps review these obligations, but merely renaming or splitting the existing reads does not reduce their number and has no demonstrated speedup.

Reducing B2's polls is a further contract change. It needs a named refresh owner at each preserved user-code boundary and an explicit reason why each omitted check is safe under the revised contract. Autoloading itself is such a boundary. A single entry refresh, an autoloader-list identity check, or a pure revision getter without those owners is insufficient.

**C is rejected for the present scope.** It promises to avoid unrelated-name scans, but no qualified comparison establishes additional application benefit over a correctly specified B2. It also adds an unresolved-name/target index spanning active plans, completed guards, inherited members, parameter targets, nested calls and deferred objects. That index needs its own invalidation and lifetime proof, including closure and object-bound reflector retention. It would still need B2's coherent snapshots and user-code boundaries; target relevance alone does not solve those problems. Removing unrelated loader side effects is also a compatibility decision. Do not add this state merely because the synthetic eight-name case is expensive.

The order of preference is: retain corrected behavior with local fixes now; use B2 only for a justified future contract change; reconsider C only if an already qualified B2 leaves a measured, significant cost from scanning irrelevant names. A and B1 remain rejected as transparent optimizations. No additional architecture or prototype is selected here.

## Reproducible evidence

The canonical probe is [AttributeAvailabilityBench.php](../../benchmarks/AttributeAvailabilityBench.php). Run it from the repository root with each PHP binary separately:

```bash
COMPONENTA_DI_BENCH_AUTOLOAD="$PWD/vendor/autoload.php" \
COMPONENTA_DI_BENCH_SOURCE="$PWD/src" \
DI_BENCH_ITERATIONS=10000 DI_BENCH_ROUNDS=7 \
php -n benchmarks/AttributeAvailabilityBench.php
```

`COMPONENTA_DI_BENCH_AUTOLOAD` and `COMPONENTA_DI_BENCH_SOURCE` are required paths; use absolute paths as in the command above and the existing benchmark convention. `DI_BENCH_ITERATIONS` and `DI_BENCH_ROUNDS` are optional positive integers, defaulting to 10,000 and 7. Warmup is the smaller of 2,000 and the iteration count. The script prints the selected source, binary, runtime version, and OPcache/JIT settings.

For every case in every round, the probe creates a separate container. The contaminated cases first create an unrelated object with either one or eight deliberately nonexistent attributes. The measured target is an empty, unannotated class. Bootstrap, the initial annotated-object creation, warmup, and cycle collection are outside the timed interval. Case order reverses on alternate rounds. The script reports the median latency across rounds and the full range of counted autoload calls per round.

A prepended autoloader counts requests only for the eight reserved fixture names. It never defines a class. Name matching is case insensitive, unrelated Composer requests are ignored, and counts reset immediately before timing. Loaded-only checks before and after measurement reject a run in which another loader defines a fixture name. Consequently, counts represent attempts to autoload these missing names, not the number of filesystem reads or the total number of callbacks in the autoloader stack. Timings include the counting callback's overhead.

### Observed baseline, November 13

Observed on Linux x86_64, PHP 8.4.23 and PHP 8.5.8 static CLI binaries, `-n`, OPcache CLI disabled and JIT disabled. Each version ran separately, with seven rounds of 10,000 operations and 2,000 warmup operations per case/round. These measurements use an immutable snapshot of `65da4acc18b19989041a2813dbc92f2f474b7177`, before the November 13 fixes. The [audit evidence](../audit/2026-11-13-evidence.json) records exact source/dependency identity and a later final-source smoke check. Historical measurements remain in the November 12 reports; differences between sessions are not attributed to code changes.

| Case | PHP 8.4.23 median, µs/op | PHP 8.5.8 median, µs/op | Missing-name autoload calls/op, both versions | Calls in every 10,000-operation round |
|---|---:|---:|---:|---:|
| `make/clean` | 5.1196 | 5.5019 | 0 | 0 |
| `make/1-unavailable` | 22.0571 | 25.2777 | 22 | 220,000 |
| `make/8-unavailable` | 132.1754 | 137.7157 | 176 | 1,760,000 |
| `get-shared/clean` | 0.2010 | 0.2008 | 0 | 0 |
| `get-shared/1-unavailable` | 0.1997 | 0.2050 | 0 | 0 |
| `get-shared/8-unavailable` | 0.2016 | 0.1993 | 0 | 0 |

The exact autoload counts were identical in every round on both runtimes. They establish that one inspected class can add repeated work to unrelated later object creation within the same container. Increasing the unresolved set from one name to eight multiplies that count by eight in this fixture. A shared `get()` hit does not perform these probes.

These are local, instrumented measurements, not a production throughput claim or a PHP-version comparison. CPU scheduling, the autoloader implementation, its own negative cache, filesystem behavior, and extensions affect elapsed time. The stable signal is repeated unresolved-name autoloading; the measured latency demonstrates its cost in this environment. The benchmark does not measure cold startup, real application request latency, or memory growth from arbitrarily many generated class names.

### Corrected-source workload reassessment

The later [full-library recheck](../audit/2026-11-13-full-library-recheck.md) measured production source `fce3f176a2ab37b6ac7d595712c3fe5ff18564b0` after the availability and parameter-coherence corrections. Its [evidence](../audit/2026-11-13-full-library-evidence.json) retains the raw five-round latency samples, independent autoload counts, complete source hashes and dependency identity. This is a baseline workload characterization: no B2/C candidate or before/after fix comparison was run. The later callable-key correction `7dc88a4` and benchmark-loader correction `71a8430` are not the timed source; these results are not relabelled as measurements of those commits.

The measured [WorkloadNecessity.php](../audit/reproductions/WorkloadNecessity.php) and [source adapter](../audit/reproductions/autoload-selected-source.php) are preserved byte for byte. The two versions ran serially on Linux x86_64, PHP 8.4.23 and 8.5.8, with CLI OPcache enabled and JIT disabled. Released dependencies included `componenta/config v3.0.0`, `componenta/reflection v2.0.1`, and `componenta/validation v3.0.1`. Both processes verified the same 161-file source manifest, 132 loaded production declarations and six DI functions from the selected source. Each version covered 66 cases: five rounds of 500 warm operations or 40 fresh-container operations, plus a separate 20-operation counting control. Latency did not include an observing autoloader; the counts below come from that separate control.

```bash
COMPONENTA_DI_BENCH_AUTOLOAD="$PWD/vendor/autoload.php" \
COMPONENTA_DI_BENCH_SOURCE="$PWD/src" \
DI_REASSESS_ITERATIONS=500 DI_REASSESS_COLD_ITERATIONS=40 \
DI_REASSESS_ROUNDS=5 DI_REASSESS_COUNT_ITERATIONS=20 \
php -n -d opcache.enable_cli=1 -d opcache.jit=0 \
docs/audit/reproductions/WorkloadNecessity.php
```

Select the source/dependency revisions recorded in the evidence to reproduce that snapshot; the command above selects the current checkout and its installed dependencies. The adapter remaps optimized Composer classmaps and autoloaded DI functions before loading them. A later `setPsr4()` call alone is insufficient when those mappings or declarations already select another source.

For warm cases, an initial `has()` inspects an unrelated class with zero, one, or eight permanently absent fixture attributes, or one already loaded foreign attribute. Container setup and path warmup are outside timing. The measured objects do not carry those missing annotations: these results characterize contamination of unrelated later work. They do not separately measure repeated creation of a DTO which itself carries an absent annotation. Each timing round owns a new container, while fixture classes and the PHP process are already warm.

All latency cells below are **µs/operation, PHP 8.4.23 / PHP 8.5.8**. Count triples mean **clean / one missing name / eight missing names**, identical on both runtimes.

| Default cached workload | Clean | One missing name | Eight missing names | Missing-name calls/op |
|---|---:|---:|---:|---:|
| Plain `make()` | 5.474 / 5.268 | 17.417 / 19.088 | 91.121 / 101.237 | 0 / 22 / 176 |
| `#[Inject]` property and `#[SetUp]` lifecycle | 23.567 / 25.901 | 65.137 / 70.758 | 305.883 / 332.499 | 0 / 70 / 560 |
| Reused typed closure | 16.616 / 17.701 | 39.512 / 43.294 | 169.944 / 183.569 | 0 / 38 / 304 |
| New root, warmed shared children | 15.902 / 17.031 | 38.824 / 41.790 | 178.608 / 193.561 | 0 / 40 / 320 |
| Explicitly fresh five-object graph | 43.070 / 47.223 | 128.059 / 141.158 | 654.452 / 706.572 | 0 / 152 / 1216 |
| Warm shared-root `get()` | 0.181 / 0.188 | 0.182 / 0.191 | 0.186 / 0.188 | 0 / 0 / 0 |

The loaded-foreign controls also recorded zero missing-name calls in every case. Plain creation was 4.931 / 5.387 µs and the valid DI-attribute path was 24.241 / 26.353 µs. These controls distinguish available unrelated annotations from absent names; small latency differences are not treated as regressions. No result establishes an architectural speedup on the clean path or a gain on shared-cache hits.

Graph identity is deliberate. Ordinary autowiring reuses `Left` and `Right` services and the same `Leaf` shared by both branches. The warm root case creates only a new root. The explicit-fresh case calls `make()` for every object and supplies its children, producing two distinct leaves and five new objects. Its cost cannot be presented as the cost of ordinary warm shared dependency injection.

The custom-rule control registers one unused marker with a no-op rule. That registration disables semantic plan memoization globally; it does not simulate the cost of an expensive rule body. The following results therefore expose the cost of repeatedly rebuilding metadata while retaining current rule semantics:

| Custom-rule control workload | Clean | One missing name | Eight missing names | Missing-name calls/op |
|---|---:|---:|---:|---:|
| Plain `make()` | 16.937 / 17.438 | 47.832 / 51.447 | 244.082 / 254.146 | 0 / 56 / 448 |
| `#[Inject]` property and `#[SetUp]` lifecycle | 85.169 / 88.205 | 180.440 / 194.503 | 756.403 / 772.198 | 0 / 154 / 1232 |
| New root, warmed shared children | 80.239 / 85.093 | 215.335 / 226.970 | 1035.606 / 1104.240 | 0 / 228 / 1824 |
| Explicitly fresh five-object graph | 206.011 / 217.694 | 567.694 / 616.190 | 2757.660 / 3158.261 | 0 / 608 / 4864 |

Shared retrieval still made zero missing-name calls in every custom-rule control. The larger clean custom-rule cost cannot be attributed to unavailable-name polling: that counter is zero. Restoring semantic memoization for arbitrary stateful rules would violate P6, and a polling refactor does not establish that it can remove this separate cost.

Cold cases mean a new container and new metadata/service state in an already warm process. Their totals include construction and the initial contaminant observation. The first root resolves shared dependency services for the first time. Setup and total columns are separate measurements, not additive phases or paired subtractions:

| Cache mode / availability | Build and observe, µs | Build, observe, first root, µs | Missing-name calls, setup / total |
|---|---:|---:|---:|
| Default / clean | 52.899 / 58.349 | 190.356 / 212.346 | 0 / 0 |
| Default / one missing name | 92.841 / 103.566 | 358.403 / 396.658 | 38 / 262 |
| Default / eight missing names | 227.583 / 254.769 | 1316.168 / 1432.186 | 304 / 2096 |
| Custom rule / clean | 58.383 / 58.062 | 308.998 / 344.630 | 0 / 0 |
| Custom rule / one missing name | 122.902 / 148.925 | 715.143 / 891.551 | 70 / 666 |
| Custom rule / eight missing names | 358.793 / 417.676 | 3096.557 / 3355.903 | 560 / 5328 |

Independent build-only controls and all raw rounds remain in the evidence. The small cold samples showed noticeable spread; subtracting independently measured medians would not yield a reliable exact setup percentage. These figures do not measure PHP process startup, I/O or application request latency. They also cannot be compared with the historical 65da/32b timings as a fix speedup: source, dependencies, settings and counting instrumentation differ.

The new evidence supports the decision above: persistent missing names impose substantial repeated work, especially across many creation/parameter boundaries and uncached-rule paths, while known/loaded attributes and shared retrieval do not produce that miss multiplier. The practical next input for an application considering B2 is a profile showing that this conditional work is material in its own workload. The baseline matrix supplies no measured B2/C benefit and does not justify adding C's index.

## Current ownership and root cause

[AttributeDefinitionRegistry](../../src/Attribute/Composition/AttributeDefinitionRegistry.php) owns registered semantic definitions, capability policies, the set of unresolved attribute names, and a generation counter. `definition()` can record a name whose class/interface is not yet available. Reading `revision` scans every recorded name with:

```php
if (class_exists($attribute) || interface_exists($attribute) || class_exists($attribute, false)) {
    unset($this->unavailableAttributes[$attribute]);
    ++$this->generation;
}
```

Both functions default to autoloading: see the PHP manuals for [class_exists](https://www.php.net/manual/en/function.class-exists.php) and [interface_exists](https://www.php.net/manual/en/function.interface-exists.php). For a name which remains absent, both attempts occur. The benchmark's counter is prepended using the documented [spl_autoload_register](https://www.php.net/manual/en/function.spl-autoload-register.php) behavior.

The final loaded-only `class_exists` check is the November 13 correction. The fallback `interface_exists` call can trigger a loader that declares a class; its own return value is still false because that declaration is not an interface. Previously the registry could return the old generation after the class became available. A warmed `has()` then returned false while the public non-memoized control returned true. The added check observes that declaration immediately without invoking another loader, changing the number of miss attempts, or delaying a fallback exception.

Revision reads are used for correctness across several owners:

| Owner | Current responsibility | Why its revision observation matters |
|---|---|---|
| `AttributePlanBuilder` | Compose attributes and invalidate named plans | An ignored annotation can later acquire a registered semantic definition through inheritance or an alias. |
| `ObjectPipeline` | Cache metadata and select constructor/object policies | A plan assembled across different generations must not be published as one coherent plan. |
| `AttributeProcessor` and `ObjectAttributeSequence` | Select phase targets, retain a pending instance, and execute an ordered prefix once | Attribute construction or a dependency can reveal a handler during the same resolution. |
| `ParametersResolver` and `AttributeParameterResolver` | Prepare resolver slots, consume attribute plans, and resolve parameters | Slots and attribute semantics must agree before execution; completed-work metadata must describe handlers actually consumed. Earlier dependencies can change later policies. |
| `CompletedAttributeGuard` | Validate completed parameter/object work | A late policy must not silently reinterpret a value or phase which has already completed. |

The availability query therefore has side effects: a nominal revision read can execute arbitrary autoload code, load a class, throw, and change the generation. For an unrelated warm `make()` these repeated observations still scan the container-wide unresolved set. Caching metadata alone cannot remove this work because checking the cache generation performs the polling.

The current behavior is intentional in one important respect: a missing annotation must not be frozen as irrelevant merely because it was missing during the first inspection. The performance issue and that discovery contract must be addressed together.

## Required preserved semantics

These constraints come from the [extension/runtime contracts in README](../../README.md#extension-points) and the existing executable scenarios. They are normative for any behavior-preserving optimization proposed from this audit.

| ID | Constraint | Existing proof |
|---|---|---|
| P1 | An attribute which becomes loadable after metadata or parameter plans were warmed must be recognized by a subsequent supported resolution. Existing inherited attribute definitions and aliases must continue to work. | [LateAttributeLoadingTest](../../tests/Contract/LateAttributeLoadingTest.php), including warmed `has()`, constructor/callable plans, and mapped DTO factories. |
| P2 | Attributes revealed by earlier dependencies, attribute argument construction, or member attribute construction can affect later work in the same resolution. | `LateAttributeLoadingTest`, [AttributePhaseLoadingContractTest](../../tests/Contract/AttributePhaseLoadingContractTest.php), [BootstrapPropertyProxyContractTest](../../tests/Contract/BootstrapPropertyProxyContractTest.php). |
| P3 | Recomposition preserves the already executed prefix. A pending attribute instance is constructed once for that invocation. Late predecessors fail before execution; already completed handlers and constructors are not replayed. | [AttributeExecutionOrderContractTest](../../tests/Contract/AttributeExecutionOrderContractTest.php), `AttributePhaseLoadingContractTest`, [AttributeFailureSequenceContractTest](../../tests/Contract/AttributeFailureSequenceContractTest.php). |
| P4 | Incompatible late changes to completed parameter input, property transactions, or before-instantiation policies fail with the existing composition exception behavior. Failed creation must not publish a shared result. Unrelated newly loaded attributes remain allowed. | [CompletedParameterAttributesContractTest](../../tests/Contract/CompletedParameterAttributesContractTest.php), [CompletedObjectPhaseContractTest](../../tests/Contract/CompletedObjectPhaseContractTest.php), [CompletedPhaseDiagnosticsContractTest](../../tests/Contract/CompletedPhaseDiagnosticsContractTest.php). |
| P5 | Deferred objects retain their initial before-phase policy. A conflicting late policy rejects initialization without replay; a fresh `make()` can use the newly available policy. | `CompletedObjectPhaseContractTest`, including both `Lazy` and `Proxy`. |
| P6 | Bootstrap registration order and completed-use validation remain effective. A custom composition rule continues to observe current arguments/state on each resolution instead of being frozen into a cached plan. | [BootstrapExtensionDependencyContractTest](../../tests/Contract/BootstrapExtensionDependencyContractTest.php), [CompositionRuleRuntimeTest](../../tests/V5/CompositionRuleRuntimeTest.php), README. |
| P7 | Shared plans retain metadata, not materialized attribute arguments, closure instances, object-bound reflectors, or invocation values. The ReflectionObject lifetime fix remains intact. | README and [ReflectionObjectLifetimeContractTest](../../tests/Contract/ReflectionObjectLifetimeContractTest.php). |
| P8 | An already cached shared service retains its identity and existing retrieval behavior. Each container owns its registry/generation and derived plans; PHP declarations and the autoloader stack remain process-wide. | The benchmark's independent-container and shared-get controls; `CompletedObjectPhaseContractTest` for failed-versus-successful shared results. |
| P9 | Discovery must be coherent before composition validation, handler-presence queries, publication of prepared resolver slots, and parameter execution. A plan and its slots must agree before the first resolver consumes them; completed metadata must describe the semantics actually consumed. Loading a later target or classifying its type must not silently omit an earlier source/handler. | [AttributeDiscoveryRevisionContractTest](../../tests/Contract/AttributeDiscoveryRevisionContractTest.php), [AttributeProcessorRevisionContractTest](../../tests/Contract/AttributeProcessorRevisionContractTest.php), [ParameterClassificationRefreshContractTest](../../tests/Contract/ParameterClassificationRefreshContractTest.php), [ParameterExecutionRevisionContractTest](../../tests/Contract/ParameterExecutionRevisionContractTest.php). |
| P10 | An existing autoloader can become ready inside a dependency factory without changing the registered loader list or directly declaring the attribute. Subsequent work in that same resolution must discover it. | [ObjectAttributeCacheParityContractTest](../../tests/Contract/ObjectAttributeCacheParityContractTest.php), cold/warm and ordinary/no-op-rule controls. |
| P11 | A class or alias declared by the fallback interface probe advances availability in that same poll. A cached `has()` agrees with the non-memoized control immediately. Both existing miss attempts and a fallback loader exception retain their timing. | [AttributeAvailabilityRevisionContractTest](../../tests/Contract/AttributeAvailabilityRevisionContractTest.php), including first-attempt, fallback, interface, missing-name and throwing-loader controls. |

P1–P6 constrain when discovery must become observable; they do not establish that the precise number of failed autoload calls is itself a supported public contract. That distinction is a design gate, not permission to silently change discovery timing.

P11 distinguishes the same-poll coherence invariant from the two-attempt and fallback-exception controls deliberately preserved by the local fix. Both remain obligations under this revision. If G1 proposes changing those autoloader observations, it must explicitly identify the affected constraints, test expectations and compatibility consequences in a revised normative specification; a prototype cannot silently waive them. Immediate invalidation after a declaration has actually been discovered, and agreement between cached and non-memoized behavior, remain correctness requirements.

The original eleven P1–P8 test files passed on both runtimes; the [cache parity recheck](../audit/2026-11-12-cache-parity-recheck.md) added P9–P10. On November 13, fifteen preservation files passed on both the immutable baseline and prototype A: **117 passing, two PHP-8.5-only skips, 820 assertions on PHP 8.4; 119 passing and 828 assertions on PHP 8.5**. The new P11 file had four failing cases and nine successful controls on the pre-correction source, then all thirteen passed. Passing P1–P10 did not establish arbitrary autoloader equivalence: G1 below contains concrete counterexamples. P11 is part of every future optimization's preservation obligations.

## Settled design constraints

The semantic definition registry remains the per-container authority for registered semantics, observed availability and generation. It observes PHP's process-wide declarations and autoloader state; it does not own or isolate them. Cached attribute/object/parameter plans remain derived state owned by the existing per-container components. Execution sequences and completed-work guards retain ownership of ordering and phase safety. This finding does not justify another global registry, a persistent negative cache, or an event subsystem.

Any future split between availability synchronization and reading a generation must specify both operations and migrate their consumers together. A cache consumer must never observe a generation that appears current while a required discovery checkpoint was skipped. Reentrant resolution, bootstrap, deferred initialization, and direct use of the currently exposed plan/processor APIs must be included in that contract; adding a check only to `Container::make()` is insufficient.

No target, counter, retry limit, timeout, or miss-expiry policy is selected by this report. No optimization may permanently ignore unresolved attributes, replay completed work, or retain runtime attribute instances to avoid materialization cost. Full source compatibility remains PHP `^8.4`; this performance work must not raise the minimum to PHP 8.5.

## Alternative assessment

| Alternative | Concrete change being evaluated | Potential benefit | Semantic/design gate |
|---|---|---|---|
| A. One autoload attempt per existing poll | Keep the current polling locations; after the autoload-capable `class_exists`, check `interface_exists` with autoload disabled. | Measured miss counts fall from 22 to 11 and 176 to 88 per operation in every round. The repeated container-wide scan remains. | Rejected as a transparent fix: the G1 prototype delays second-attempt discovery and exceptions. Adoption would require explicitly changing the compatibility contract. |
| B1. Root-only autoload refresh plus loaded-only internal checks | Autoload missing names at the start of an outer resolution; inside it observe only names already declared. | Avoids most repeated autoload misses while retaining explicit `eval`/`class_alias` discovery. | An earlier dependency can make the same loader able to resolve a class without declaring it. A loaded-only check does not discover that state change. This variant is unacceptable unless that loss of same-resolution discovery is explicitly permitted. |
| B2. Explicit refresh checkpoints around user code | Use an availability snapshot during pure metadata operations; refresh at entry and after the relevant user-code boundaries, before consuming or validating the next plan. | Preferred conditional direction. It can remove repeated work only if some polls are demonstrably omitted; separating the operations alone does not establish a speedup. | The checkpoint inventory and reentrancy behavior must be complete. Attribute constructors, custom rules, resolver support/resolution, factories, constructors, property/lifecycle callbacks, configuration callbacks, and deferred initialization need explicit treatment. Autoloading itself is user code. |
| C. Track unresolved names relevant to active plans | Retain unresolved dependencies with target metadata; poll names needed by the active target, its inherited members, and completed-work guards. | Could stop an unrelated annotated class from slowing plain object creation. Not selected: no additional application benefit over a qualified B2 is established. | Adds an index with new invalidation and lifetime obligations while still requiring coherent snapshots. Removes unrelated loader side effects. Reconsider only if a qualified B2 leaves a measured cost that justifies that extra state. |

The bounded A experiment is complete. Do not repeat it merely to obtain a more favorable latency figure. Consider at most one B2 prototype only after revising the synchronization contract and showing that the remaining application-level cost justifies the change. C is outside that conditional experiment. B1 remains a falsification control for the same-resolution contract, not the default design.

Freezing unresolved annotations after bootstrap, imposing an arbitrary poll cap/TTL, or using only changes to `spl_autoload_functions()` as an invalidation signal is not an accepted solution. An unchanged loader can depend on mutable state or newly available files. An explicit application notification API would change ownership and the public contract and requires its own approved proposal.

## Blocking decisions and bounded experiments

### G1 — Which autoloader observations are contractual?

**Status:** evidence complete; the decision for current 5.x is to retain corrected behavior. No production optimization follows from G1. A future reduction in polling must explicitly revise the affected compatibility constraints before G2/G3 can qualify that changed contract.

The repository proves late class loading and completed-work protection. This decision preserves the corrected implementation's observations without declaring every historical miss count a new general public API guarantee. In particular, no optimization here may delay discovery or an exception merely because a loader needs another invocation, or remove an existing unrelated-name side effect. Reducing polls necessarily changes some count-sensitive observations; that would need a revised contract rather than an assertion of transparency.

**Completed evidence:** seven isolated scenarios and their preloaded controls ran against baseline `65da4acc` and A on both runtimes: 56 processes. The same-resolution readiness case reused `ObjectAttributeCacheParityContractTest`. The portable [AttributeAvailabilityObservations.php](../audit/reproductions/AttributeAvailabilityObservations.php) reproduces the isolated observations; the [evidence](../audit/2026-11-13-evidence.json) preserves exact outcomes and source provenance.

| Observation | Baseline `65da4acc` | Prototype A | Decision |
| --- | --- | --- | --- |
| Existing loader becomes ready between two `make()` calls | Values `0, 1`; two constructors, one handler | Same values and counts | Freshness preserved in this case; loader calls differ, 67 versus 48. |
| Readiness changes inside an earlier dependency | Cold/warm and cached/non-memoized controls pass | Same | Keep P10; root-only refresh cannot satisfy it. |
| Class or alias declared on the second loader invocation | Declaration exists after the first poll, but old revision stays 0 | Declaration remains absent until a later poll | A changes discovery timing. The baseline revision defect was independently fixed by P11: current code reports revision 1 in that first poll. |
| Interface declared on the second invocation | First poll returns revision 1 | First poll returns 0, second returns 1 | Direct public registry result changes. |
| Loader throws on its first invocation | Immediate original exception | Same | Preserved control. |
| Loader throws on its second invocation | First poll throws | First poll succeeds, second throws | Exception timing changes; A is not transparent. |
| Previously missing annotation loads an unrelated class as a side effect | Constructor sees the class; one constructor; 30 loader calls before it, 38 total | Same semantic outcome; 15 calls before it, 19 total | This fixture is preserved; arbitrary count-sensitive side effects are not. |
| All preloaded controls | Zero missing-name loader calls | Same | Differences depend on unresolved names. |

The chosen outcome is to retain the corrected baseline's discovery and failure observations, including P11. No observation is deliberately relaxed in this revision. A future proposal must name any observation allowed to change, its compatibility consequences, and the corresponding normative constraints and proof to revise. Passing a broad test suite does not make those differences harmless. Adoption of A or B2 cannot be inferred from this experiment, and no additional open-ended fixture campaign is needed to establish the current decision.

### G2 — Where can a refresh safely be omitted?

**Status:** blocked for conditional B2 work. The current G1 decision retains polling behavior. Before qualifying omissions, a future proposal must justify the application cost and explicitly settle any changed observations. The checkpoint inventory is an impact map; B2's replacement synchronization contract is not settled.

**Deciding evidence:** use the existing map of revision reads, user-code boundaries and completed-work checks to justify each proposed omission under the selected contract. Use the fixtures above together with the P1–P11 suites. Include nested `make()`/`call()`, direct plan/processor API use, bootstrap, and delayed lazy/proxy initialization. Confirm how a refresh that itself loads another attribute or reenters resolution reaches a coherent generation without replay.

The November 13 reviews produced this checkpoint map. It is an impact map for B2, not proof that any listed refresh can already be removed:

| Owner | Required observation boundary | Required failure or lifetime behavior |
| --- | --- | --- |
| Registry `definition()` / `revision` | Classification can autoload; consumers must observe classes, aliases and interfaces discovered by that poll | No permanent negative result; propagate loader exceptions; preserve P11. |
| `AttributePlanBuilder::build` | Before reuse and after complete discovery, before composition validation/publication | A later declaration may reveal an earlier one; stabilize without replaying runtime handlers. |
| `ObjectPipeline::metadata` | Before reuse and after constructor/member discovery | Publish one coherent generation across class, parameters and handlers. |
| `ParametersResolver::prepareTarget` | After custom `supports()` or native type classification, before slot publication | Direct, prepared, complete and callable paths agree. |
| `ParametersResolver::resolvePrepared` / parameter execution | Before the first resolver, and after resolver/dependency/default work, before later input or completed results are accepted | Resolver slots and attribute semantics form one coherent snapshot; a later discovery cannot be recorded as already consumed. Reject incompatible completed input. |
| `AttributeParameterResolver` | Before handler dispatch, after native argument/attribute construction, and after handler effects | Retain a pending instance, preserve executed prefix and input policy, and distinguish the plan actually consumed from a later refreshed plan. |
| `AttributeProcessor::executionPlan` | Direct `prepare()` / `hasHandlers()` entry points | Synchronize without depending on a surrounding Container call. |
| Object attribute sequence / handler dispatch | After argument/attribute materialization and handler effects, before the next handler | Retain one pending instance, preserve executed prefix, reject late predecessors. |
| Completed guards / property transactions | After constructors, setters, callbacks, configuration and later dependencies | Validate completed semantics before success; failures do not publish shared results. |
| Bootstrap guard | Extension factories and registration expose semantics during bootstrap | Do not silently omit final extension semantics from premature service use. |
| Lazy/proxy initialization | Deferred entry into user code, including nested resolution | Keep initial before-phase policy and reject incompatible changes without replay. |

A pure-generation getter requires an explicit synchronization owner at each applicable boundary, including direct public APIs. Autoload itself is user code: a metadata traversal cannot simply be assumed pure. The remaining design work is to select a contract and prove each omitted poll under that contract, not to introduce a second registry.

**Bound:** if evidence beyond the existing map is needed after a revised G1 contract is justified, temporary instrumentation is restricted to an isolated source copy of the registry and the consumers listed in the checkpoint map. Produce the selected contract's checkpoint table with an owner, triggering operation, required observation, and failure propagation for each boundary. Retain the findings and source provenance; discard temporary instrumentation from the production change surface. Stop once every preserved scenario has an explicit checkpoint and every omitted check has a reason grounded in that map, then update the normative synchronization contract. No profiling counters or second synchronization path become a production dependency.

### G3 — Does the smallest preserving candidate justify its complexity?

**Status:** blocked by the prerequisites of G2 and its synchronization contract. Historical A evidence is complete; B2 is only a preferred future direction, and no current-source candidate has been selected for production.

The isolated A experiment measured the following with the same dependencies, settings and harness as the baseline. It is a pre-P11, unpaired exploratory comparison, not a final-source speedup or statistical non-regression result:

| Scenario | PHP 8.4 baseline → A, µs | PHP 8.5 baseline → A, µs | Missing-name calls/op |
| --- | ---: | ---: | ---: |
| One unavailable attribute | 22.057 → 14.656 | 25.278 → 16.654 | 22 → 11 |
| Eight unavailable attributes | 132.175 → 74.966 | 137.716 → 79.601 | 176 → 88 |
| Clean creation and shared retrieval controls | No new missing-name probes | No new missing-name probes | 0 → 0 |

All source-map checks passed; the optimized Composer classmap and autoloaded functions were remapped to the selected immutable copy. The exact counter reduction is established. The latency figures include instrumentation and do not justify dropping P11 or changing exception timing. No B2/C implementation was attempted in that historical experiment. The current decision retains corrected polling rather than treating those results as authorization for a different architecture.

**Deciding evidence:** after a justified revised G1 contract and G2 are settled, use the then-current corrected source, including P11 and the parameter execution coherence fixes, as the comparison baseline. Historical A remains evidence; do not rebase it merely to reopen its completed rejection as a transparent fix. Compare at most one B2 candidate. Use identical settings and the targeted preservation suites; run runtime versions serially. Include the ordinary valid-attribute paths, custom-rule control, shared/fresh graphs, cold setup, and clean/shared-get controls alongside the affected missing-name cases. Record exact miss counts, raw latency rounds and their median, any count range, and additional retained metadata. An autoload-count range is not a latency distribution; timing and counting instrumentation must be identified separately.

Also retain the controlled `RuntimeBench.php` comparison, including reused and fresh closures. The follow-up coherence fixes showed a positive reused-closure latency signal even with no unavailable attribute in that fixture (see the [recheck measurements](../audit/2026-11-12-cache-parity-recheck.md#controlled-runtime-comparison)). A candidate must account for the ordinary non-memoized path as well as the missing-name benchmark; removing a revision check is acceptable only after its required observation is covered by the approved checkpoint contract.

**Bound:** disposable candidate copies only; no production edits or public API adoption. Reject a candidate on any preserved behavior failure. Stop after this comparison: choose the smallest satisfactory candidate, retain the baseline with an explained tradeoff, or revise the desired-state specification if a contract change is necessary. A prototype is not implicitly the selected architecture.

## Implementation sequence and acceptance

The benchmark, historical bounded prototype/evidence and independent P11 correctness fix are completed deliverables. This revision contains no executable production optimization slice. The following order governs the remaining decisions and conditional evidence tasks; it does not authorize production implementation:

1. Preserve the benchmark and the measured baseline as the failure signal. This deliverable is complete.
2. Apply the settled G1 decision: retain corrected behavior in current 5.x. Do not derive a production patch from the synthetic benchmark. A future application-backed proposal must explicitly revise any changed observation before reducing polls.
3. Only for that justified future B2 proposal, resolve G2. Write the normative synchronization contract, including direct API calls, nested calls, failure behavior, coherent plan consumption, and deferred work.
4. Resolve G3 with one isolated B2 candidate. Update this document with the selected architecture, measured tradeoff, and acceptance evidence, or retain corrected polling if the comparison does not justify adoption.
5. Only then derive production slices from the updated normative specification. Under this revision's preservation contract, each slice must change one coherent availability behavior together with all required consumers, preserve P1–P11, and remove superseded polling in that same slice once its replacement is proven. Any intentional compatibility change requires the explicit normative revision described under G1; it cannot be introduced by a slice or a passing benchmark. The result must not leave both strategies active indefinitely.

For the eventual implementation, acceptance requires lower missing-name autoload work in the affected benchmark cases, an explained latency result on both runtimes, no new unresolved-name probes on the clean/shared-get paths, and all P1–P11 scenarios passing. No numeric throughput target is implied. Freshness and failure behavior take precedence over a faster invalid result.

The grounded change surface is `AttributeDefinitionRegistry::revision`/`definition()`, `AttributePlanBuilder`, `ObjectPipeline::metadata`, `AttributeProcessor`, `ObjectAttributeSequence`, `CompletedAttributeGuard`, `ParametersResolver`, and `AttributeParameterResolver`, with request-source/bootstrap consumers included wherever the selected contract crosses them. This is an impact map, not an instruction to modify every file. Registry polling stays unchanged until its complete replacement contract and consumers are settled.

Convergence review must compare the approved synchronization contract with actual runtime paths, verify that no stale polling path or silent negative cache remains, and check that custom-rule freshness, ReflectionObject lifetime, native argument evaluation, and shared-service identity were not traded away for the performance result.
