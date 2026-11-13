# Attribute availability polling: evidence and design plan

**Readiness:** the November 13 audit completed a bounded autoloader comparison, a source-level checkpoint map, and an isolated prototype of alternative A. A is measurably cheaper but changes observable discovery and exception timing, so it was not adopted. A broader production optimization remains **blocked by the compatibility and checkpoint decisions below**. The same-poll cache-coherence defect found during the experiment was fixed separately in `996187f`; that correction preserves both autoload attempts.

## Objective and scope

Reduce repeated autoload work caused by unresolved optional attributes without losing late attribute discovery, ordered execution, or detection of incompatible changes after part of a resolution has completed. The affected behavior spans class creation, prepared parameters, request mapping, object phases, bootstrap, and deferred initialization. Cached shared-service retrieval is a control case.

The finding concerns the existing PHP 8.4+ runtime. It does not require the PHP 8.5 callback-attribute refactor, an AOT compiler, a persistent metadata cache, or a new public registration API.

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
| `ParametersResolver` | Prepare and resolve parameter plans | Earlier dependencies can change the policies applicable to later parameters. |
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
| P8 | An already cached shared service retains its identity and existing retrieval behavior. Container instances have independent availability state. | The benchmark's independent-container and shared-get controls; `CompletedObjectPhaseContractTest` for failed-versus-successful shared results. |
| P9 | Discovery must be coherent before composition validation, handler-presence queries, or publication of prepared resolver slots. Loading a later target or classifying its type must not silently omit an earlier source/handler. | [AttributeDiscoveryRevisionContractTest](../../tests/Contract/AttributeDiscoveryRevisionContractTest.php), [AttributeProcessorRevisionContractTest](../../tests/Contract/AttributeProcessorRevisionContractTest.php), [ParameterClassificationRefreshContractTest](../../tests/Contract/ParameterClassificationRefreshContractTest.php). |
| P10 | An existing autoloader can become ready inside a dependency factory without changing the registered loader list or directly declaring the attribute. Subsequent work in that same resolution must discover it. | [ObjectAttributeCacheParityContractTest](../../tests/Contract/ObjectAttributeCacheParityContractTest.php), cold/warm and ordinary/no-op-rule controls. |
| P11 | A class or alias declared by the fallback interface probe advances availability in that same poll. A cached `has()` agrees with the non-memoized control immediately. Both existing miss attempts and a fallback loader exception retain their timing. | [AttributeAvailabilityRevisionContractTest](../../tests/Contract/AttributeAvailabilityRevisionContractTest.php), including first-attempt, fallback, interface, missing-name and throwing-loader controls. |

P1–P6 constrain when discovery must become observable; they do not establish that the precise number of failed autoload calls is itself a supported public contract. That distinction is a design gate, not permission to silently change discovery timing.

The original eleven P1–P8 test files passed on both runtimes; the [cache parity recheck](../audit/2026-11-12-cache-parity-recheck.md) added P9–P10. On November 13, fifteen preservation files passed on both the immutable baseline and prototype A: **117 passing, two PHP-8.5-only skips, 820 assertions on PHP 8.4; 119 passing and 828 assertions on PHP 8.5**. The new P11 file had four failing cases and nine successful controls on the pre-correction source, then all thirteen passed. Passing P1–P10 did not establish arbitrary autoloader equivalence: G1 below contains concrete counterexamples. P11 is part of every future optimization's preservation obligations.

## Settled design constraints

The semantic definition registry remains the authority for availability and generation. Cached attribute/object/parameter plans remain derived state owned by the existing per-container components. Execution sequences and completed-work guards retain ownership of ordering and phase safety. This finding does not justify another global registry, a persistent negative cache, or an event subsystem.

Any future split between availability synchronization and reading a generation must specify both operations and migrate their consumers together. A cache consumer must never observe a generation that appears current while a required discovery checkpoint was skipped. Reentrant resolution, bootstrap, deferred initialization, and direct use of the currently exposed plan/processor APIs must be included in that contract; adding a check only to `Container::make()` is insufficient.

No target, counter, retry limit, timeout, or miss-expiry policy is selected by this report. No optimization may permanently ignore unresolved attributes, replay completed work, or retain runtime attribute instances to avoid materialization cost. Full source compatibility remains PHP `^8.4`; this performance work must not raise the minimum to PHP 8.5.

## Alternatives requiring a decision

| Alternative | Concrete change being evaluated | Potential benefit | Semantic/design gate |
|---|---|---|---|
| A. One autoload attempt per existing poll | Keep the current polling locations; after the autoload-capable `class_exists`, check `interface_exists` with autoload disabled. | Measured miss counts fall from 22 to 11 and 176 to 88 per operation in every round. The repeated container-wide scan remains. | Rejected as a transparent fix: the G1 prototype delays second-attempt discovery and exceptions. Adoption would require explicitly changing the compatibility contract. |
| B1. Root-only autoload refresh plus loaded-only internal checks | Autoload missing names at the start of an outer resolution; inside it observe only names already declared. | Avoids most repeated autoload misses while retaining explicit `eval`/`class_alias` discovery. | An earlier dependency can make the same loader able to resolve a class without declaring it. A loaded-only check does not discover that state change. This variant is unacceptable unless that loss of same-resolution discovery is explicitly permitted. |
| B2. Explicit refresh checkpoints around user code | Use an availability snapshot during pure metadata operations; refresh at entry and after the relevant user-code boundaries, before consuming or validating the next plan. | Can remove repeated autoload work between boundaries without freezing discovery for a whole resolution. | The checkpoint inventory and reentrancy behavior must be complete. Attribute constructors, custom rules, resolver support/resolution, factories, constructors, property/lifecycle callbacks, configuration callbacks, and deferred initialization need explicit treatment. Autoloading itself is user code. |
| C. Track unresolved names relevant to active plans | Retain unresolved dependencies with target metadata; poll names needed by the active target, its inherited members, and completed-work guards. | Could stop an unrelated annotated class from slowing plain object creation. | Must preserve late discovery for every active/completed target and prepared plan. It also removes autoload side effects from unrelated names; determine whether those are incidental. Account for new dependency-index memory and invalidation complexity before adding it. |

The bounded A experiment is complete. Do not repeat it merely to obtain a more favorable latency figure. Consider at most one B2/C prototype only after settling the compatibility contract and showing that the remaining application-level cost justifies the broader change. B1 remains a falsification control for the same-resolution contract, not the default design.

Freezing unresolved annotations after bootstrap, imposing an arbitrary poll cap/TTL, or using only changes to `spl_autoload_functions()` as an invalidation signal is not an accepted solution. An unchanged loader can depend on mutable state or newly available files. An explicit application notification API would change ownership and the public contract and requires its own approved proposal.

## Blocking decisions and bounded experiments

### G1 — Which autoloader observations are contractual?

The repository proves late class loading and completed-work protection. It does not settle whether arbitrary miss-count-dependent loader behavior must be preserved, whether a newly loadable class must be discovered without visiting its declaring target, or whether every current `revision` read must continue to execute loaders. Reducing these calls necessarily changes some such observations.

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

The current audit preserves the existing autoloader-observability contract. It does not classify these differences as harmless solely because a broad test suite passes. Evidence gathering for G1 is complete; adoption of A or a broader design still needs a written decision explicitly identifying which observations may change. No additional open-ended fixture campaign is required to establish that decision boundary.

### G2 — Where can a refresh safely be omitted?

**Deciding evidence:** map the existing revision reads to the user-code boundaries and completed-work checks they protect. Use the fixtures above together with the P1–P10 suites. Include nested `make()`/`call()`, direct plan/processor API use, bootstrap, and delayed lazy/proxy initialization. Confirm how a refresh that itself loads another attribute or reenters resolution reaches a coherent generation without replay.

The November 13 source review produced this checkpoint map. It is an impact map for B2/C, not proof that any listed refresh can already be removed:

| Owner | Required observation boundary | Required failure or lifetime behavior |
| --- | --- | --- |
| Registry `definition()` / `revision` | Classification can autoload; consumers must observe classes, aliases and interfaces discovered by that poll | No permanent negative result; propagate loader exceptions; preserve P11. |
| `AttributePlanBuilder::build` | Before reuse and after complete discovery, before composition validation/publication | A later declaration may reveal an earlier one; stabilize without replaying runtime handlers. |
| `ObjectPipeline::metadata` | Before reuse and after constructor/member discovery | Publish one coherent generation across class, parameters and handlers. |
| `ParametersResolver::prepareTarget` | After custom `supports()` or native type classification, before slot publication | Direct, prepared, complete and callable paths agree. |
| `ParametersResolver::resolvePrepared` | After resolver/dependency/default work, before later input is consumed or completed results accepted | Discover newly authoritative sources; reject incompatible completed input. |
| `AttributeProcessor::executionPlan` | Direct `prepare()` / `hasHandlers()` entry points | Synchronize without depending on a surrounding Container call. |
| Object attribute sequence / handler dispatch | After argument/attribute materialization and handler effects, before the next handler | Retain one pending instance, preserve executed prefix, reject late predecessors. |
| Completed guards / property transactions | After constructors, setters, callbacks, configuration and later dependencies | Validate completed semantics before success; failures do not publish shared results. |
| Bootstrap guard | Extension factories and registration expose semantics during bootstrap | Do not silently omit final extension semantics from premature service use. |
| Lazy/proxy initialization | Deferred entry into user code, including nested resolution | Keep initial before-phase policy and reject incompatible changes without replay. |

A pure-generation getter requires an explicit synchronization owner at each applicable boundary, including direct public APIs. Autoload itself is user code: a metadata traversal cannot simply be assumed pure. The remaining design work is to select a contract and prove each omitted poll under that contract, not to introduce a second registry.

**Bound:** temporary instrumentation of an isolated source copy, restricted to the registry and the consumers listed in the ownership table. Produce a checkpoint table with an owner, triggering operation, required observation, and failure propagation for each boundary. Do not keep profiling counters or a second synchronization path in production. Stop once every preserved scenario has an explicit checkpoint and every omitted check has a reason grounded in that map.

### G3 — Does the smallest preserving candidate justify its complexity?

The isolated A experiment measured the following with the same dependencies, settings and harness as the baseline. It is a pre-P11, unpaired exploratory comparison, not a final-source speedup or statistical non-regression result:

| Scenario | PHP 8.4 baseline → A, µs | PHP 8.5 baseline → A, µs | Missing-name calls/op |
| --- | ---: | ---: | ---: |
| One unavailable attribute | 22.057 → 14.656 | 25.278 → 16.654 | 22 → 11 |
| Eight unavailable attributes | 132.175 → 74.966 | 137.716 → 79.601 | 176 → 88 |
| Clean creation and shared retrieval controls | No new missing-name probes | No new missing-name probes | 0 → 0 |

All source-map checks passed; the optimized Composer classmap and autoloaded functions were remapped to the selected immutable copy. The exact counter reduction is established. The latency figures include instrumentation and do not justify dropping P11 or changing exception timing. No B2/C implementation was attempted because G1 already established the unresolved compatibility choice.

**Deciding evidence:** after G1 and G2 are resolved, use the then-current corrected source, including P11, as the comparison baseline. Historical A remains evidence; rebase it only if the chosen compatibility contract permits its changed observations. Compare at most one broader candidate selected from B2/C. Use this benchmark with identical settings and the targeted preservation suites; run runtime versions serially. Include the clean path and shared-get control in every comparison. Record exact miss counts, median latency and round spread, and any additional metadata retained by the candidate.

Also retain the controlled `RuntimeBench.php` comparison, including reused and fresh closures. The follow-up coherence fixes showed a positive reused-closure latency signal even with no unavailable attribute in that fixture (see the [recheck measurements](../audit/2026-11-12-cache-parity-recheck.md#controlled-runtime-comparison)). A candidate must account for the ordinary non-memoized path as well as the missing-name benchmark; removing a revision check is acceptable only after its required observation is covered by the approved checkpoint contract.

**Bound:** disposable candidate copies only; no production edits or public API adoption. Reject a candidate on any preserved behavior failure. Stop after this comparison: choose the smallest satisfactory candidate, retain the baseline with an explained tradeoff, or revise the desired-state specification if a contract change is necessary. A prototype is not implicitly the selected architecture.

## Implementation sequence and acceptance

The executable work consists of the benchmark, bounded prototype/evidence, and the independent P11 correctness fix. A broader availability architecture is not implementation-ready. The dependency order is:

1. Preserve the benchmark and the measured baseline as the failure signal. This deliverable is complete.
2. Resolve the G1 compatibility choice using the completed fixtures. Retain current behavior unless the new contract explicitly permits the observed changes. Do not derive a production patch from benchmark numbers alone.
3. Resolve G2 for the selected direction. Write the normative synchronization contract, including direct API calls, nested calls, failure behavior, and deferred work.
4. Resolve G3 in isolated candidate copies. Update this document with the selected architecture, measured tradeoff, and acceptance evidence.
5. Only then derive production slices. Each slice must change one coherent availability behavior together with all required consumers, preserve P1–P11, and remove superseded polling in that same slice once its replacement is proven. It must not leave both strategies active indefinitely.

For the eventual implementation, acceptance requires lower missing-name autoload work in the affected benchmark cases, an explained latency result on both runtimes, no new unresolved-name probes on the clean/shared-get paths, and all P1–P11 scenarios passing. No numeric throughput target is implied. Freshness and failure behavior take precedence over a faster invalid result.

The grounded change surface is `AttributeDefinitionRegistry::revision`/`definition()`, `AttributePlanBuilder`, `ObjectPipeline::metadata`, `AttributeProcessor`, `ObjectAttributeSequence`, `CompletedAttributeGuard`, and `ParametersResolver`, with request-source/bootstrap consumers included wherever the selected contract crosses them. This is an impact map, not an instruction to modify every file. Registry polling stays unchanged until its complete replacement contract and consumers are settled.

Convergence review must compare the approved synchronization contract with actual runtime paths, verify that no stale polling path or silent negative cache remains, and check that custom-rule freshness, ReflectionObject lifetime, native argument evaluation, and shared-service identity were not traded away for the performance result.
