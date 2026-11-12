# Componenta DI: whole-library cache parity recheck

Date: 2026-11-12. Repository: `componenta/di`. Recheck baseline: `e75aa6a9373e13846c3f64af60b35d404cdbe217`. Verified production and test commit: `94c6ec77a78af4c7db481a3126b1898503e499d7`.

This is the follow-up to the [container audit](2026-11-12-container-audit.md), prompted by the requirement that optimization must not substitute services, discard parameters, or otherwise change observable behavior. It covers the entire library; the cache comparison is a method within that review, not a restriction to one cache class.

## Outcome

Four further defects were reproduced, corrected, reviewed, and pushed as separate changes. Three concern metadata becoming incomplete during autoloading. The fourth changes shared object identity and repeats delegator side effects after an unrelated alias mutation. No public platform requirement was raised and no proposed PHP 8.5 architecture was implemented.

The final local suite passes **1,076 cases / 4,884 assertions on PHP 8.4.23**, with two intentional PHP-8.5-only skips, and **1,078 cases / 4,892 assertions on PHP 8.5.8**. PHPStan max and formatting pass. The final source's complete push CI also passed on Linux and Windows, with **90.5% coverage and 85.78% mutation score**. The recheck adds **66 executable cases in nine contract files**; two require PHP 8.5. Test counts are not a coverage estimate.

| Finding | Observable defect | Fix |
| --- | --- | --- |
| R1: incomplete attribute discovery | A cold operation can report a missing required attribute even though autoloading a later attribute has just made it available; the repeated operation succeeds. | [`56784b0`](https://github.com/componenta/di/commit/56784b07b7f9134ca9fad9465a46b6662182a5df) |
| R2: stale single-parameter classification | `resolveParameter()` returns the default instead of a newly available source, while the complete parameter/call paths use that source. | [`7ae200e`](https://github.com/componenta/di/commit/7ae200e2dbe857e65df188906326d32a5aa0da41) |
| R3: overly broad pipeline invalidation | Changing one alias's pipeline replaces independent shared wrappers and repeats their delegators. A suspended alias has the same issue after a canonical-only pipeline change. | [`54be73b`](https://github.com/componenta/di/commit/54be73b4bfa848e86ea29dce2ddc4f8e9b39e88f) |
| R4: incomplete object-handler discovery | Direct `AttributeProcessor::hasHandlers()` returns false, then true, when member autoloading reveals a previously skipped class handler. | [`94c6ec7`](https://github.com/componenta/di/commit/94c6ec77a78af4c7db481a3126b1898503e499d7) |

The preserved [machine-readable evidence](2026-11-12-cache-parity-evidence.json) records runtime checks, exact source identities, benchmark protocol/results, and CI observations. Relative code/test links below identify the executable evidence for each claim.

## 1. What equivalence means here

For a metadata optimization, the same invocation history must preserve the returned values and their relevant identities, all parameter positions and names, resolution precedence, exception ownership and causes, and the number/order of user handler or callback executions. A faster path is not equivalent merely because its final string looks correct.

`get()` has an explicit shared-result contract. Disabling that value cache and repeatedly invoking a stateful factory would be a different operation. Its controls therefore check that unchanged bindings and requested pipelines keep their exact shared object, mutations invalidate the appropriate results, failed/in-flight attempts do not restore stale values, and `null`/`false` are cached as values. `make()` and `call()` are separately checked for current runtime inputs and current dependency bindings.

### Reference path

The differential tests retain the real `ContainerFactory`, protected services, and built-in resolver chain. A public attribute definition with a no-op custom composition rule disables memoization of attribute, object, and prepared parameter plans under the documented extension contract. The rule does not read arguments, replace a handler, or change ordering.

[CallableCacheParityContractTest](../../tests/Contract/CallableCacheParityContractTest.php) proves that this control actually reclassifies every position in a six-parameter signature after warmup, while the ordinary container reuses its plan. Both containers have the same resolver classes. Other fixtures use the same control for object creation, request handling, and late-loading regressions.

This is **not a switch that removes every cache from the library**. Stable named targets, method staticness, native reflection metadata, and the deliberately shared service results remain subject to their ordinary contracts. Their keys, isolation, invalidation and lifetime were checked separately. Public operations with fresh reflectors, distinct same-class instances, class aliases, inherited members, closures and cross-container histories provide independent controls; tests do not inspect private cache sizes.

## 2. Confirmed corrections

### R1 — validate attribute composition only after discovery stabilizes

Owner: [AttributePlanBuilder](../../src/Attribute/Composition/AttributePlanBuilder.php). Regression: [AttributeDiscoveryRevisionContractTest](../../tests/Contract/AttributeDiscoveryRevisionContractTest.php).

A target declares an unavailable required attribute, followed by a dependent attribute. Loading the dependent attribute also declares/aliases the required one. The original traversal had already skipped the first declaration, then validated the incomplete list and threw a misleading `requires` error. A subsequent call saw both declarations and succeeded.

Discovery now repeats when the registry revision changes during traversal, before dependency/capability/composition validation is performed. The existing successful cache-hit return remains before this new work. Runtime attribute construction and handler execution stay outside the retry loop; no created service is reconstructed to repair metadata.

The four regression cases cover cold/warm metadata with ordinary/non-memoized plans. Before the fix, both cold cases failed on both runtimes; both warm controls passed. All four now pass, with 54 assertions. They also verify that `has()` does not construct runtime attribute payloads, handlers run once, `new` argument expressions are fresh, and argument objects are not retained by shared plans.

### R2 — publish parameter resolver slots from a coherent revision

Owner: [ParametersResolver](../../src/Resolver/Parameter/ParametersResolver.php). Regression: [ParameterClassificationRefreshContractTest](../../tests/Contract/ParameterClassificationRefreshContractTest.php).

An immutable custom classifier legitimately inspects a declared class with autoload enabled. That class file can also declare an attribute previously unavailable on the parameter. The built-in attribute resolver had already been classified as inapplicable. The single-parameter path consumed those stale slots and returned the native default; recording the now-current attribute plan afterward hid the omission from the completion guard.

`prepareTarget()` now builds/classifies until the existing revision is stable, then returns or caches the slots. Values, handlers and the callable body are not replayed. This uses the existing preparation owner instead of adding a separate resolution engine.

The eight public cases compare `resolveParameter()`, `resolve()`, `resolvePrepared()` and `call()`, under ordinary and non-memoized plans. Before the fix, the two single-parameter cases failed and six controls passed on both runtimes. All eight now pass. This defect still reproduced with R1 already fixed: it is a separate refresh boundary. It also occurred with plan memoization disabled, so it must not be described as exclusively a cache-hit defect.

### R3 — distinguish source replacement from requested-pipeline changes

Owners: [Container](../../src/Container.php), [EntryCache](../../src/Internal/EntryCache.php), [AliasResolver](../../src/Internal/AliasResolver.php). Regression: [AliasPipelineInvalidationContractTest](../../tests/Contract/AliasPipelineInvalidationContractTest.php).

Aliases share source creation, but each requested ID owns its delegator pipeline. The former invalidation helper cleared the canonical result and every sibling whenever one requested pipeline changed. Adding a delegator to alias A, replacing a deferred decorator used only by A, or retargeting A could therefore recreate the independent wrapper returned by alias B. Its source and own pipeline were unchanged.

This is observable through object identity and side effects. The independent delegator ran twice instead of once. A related Fiber case discarded an in-flight alias result solely because the canonical ID's independent pipeline changed.

The corrective plan was reviewed before implementation:

1. Retain family invalidation for actual source/base replacement.
2. Add exact invalidation for one requested pipeline, unlinking its previous membership from the existing reverse index.
3. Compare canonical targets in the original and already validated candidate alias maps. Invalidate changed requested IDs, including cold ancestors/descendants, while preserving unchanged pipelines. This scan runs on mutation, not ordinary lookups.
4. Protect publication using the requested result's revision and the source **base** revision. After its own successful `putBase()`, the resolving call updates its captured base revision. A change to the canonical pipeline alone is no longer confused with a change to the shared source.

The existing two-level cache, alias map and declared deferred-dependency index remain the architecture. There is no new general service dependency graph. Validation remains before mutation; the revised boundaries were independently reviewed and tested against cycles, rejected registration and suspended calls.

Four of eight new cases failed before the fix on both runtimes. All eight now pass, with 55 assertions. Controls verify that source replacement still invalidates all siblings and deferred dependents, retargeted aliases stop depending on the old source, a cold suspended descendant cannot republish its old result, and unchanged pipelines retain exact identity. A separate independent public probe on PHP 8.5 confirmed that a same-canonical retarget preserves the decorated instance; that probe is not one of these eight regression cases.

An additional bounded probe compared the final public values of 120 deterministic mutation histories with fresh replay, using pure callbacks. Results matched both before and after the fix. Failure and concurrency behavior were checked separately by the contract tests. The probe did not compare shared identity or execution counts; the dedicated regressions detected that defect.

### R4 — return coherent object-handler metadata to direct consumers

Owner: [AttributeProcessor](../../src/Resolver/Attribute/AttributeProcessor.php). Regression: [AttributeProcessorRevisionContractTest](../../tests/Contract/AttributeProcessorRevisionContractTest.php).

The execution-plan traversal inspects the class and its members. A property attribute's autoloader can reveal a handler on an earlier class target. The former plan retained the earlier `hasHandlers=false` result and returned it with an obsolete revision. A repeated query corrected itself. Normal creation through `ObjectPipeline` already had a revision loop, but direct public `AttributeProcessor` consumers did not receive that protection.

The complete target traversal now stabilizes its registry revision before return/publication. Its existing cache-hit path and `ReflectionObject` bypass are preserved. Runtime materialization/handlers remain after discovery. Custom rules may revalidate when a real late load changes the revision, as in the existing object pipeline. If a rule explicitly reads argument expressions, their evaluation follows that existing custom-rule contract; a universal single-evaluation promise is not introduced.

Before the fix, two cold cases failed and two warm controls passed on each runtime. All four now pass, with 40 assertions. The test verifies that metadata queries do not construct runtime attribute arguments or execute handlers, and actual processing performs the handler exactly once.

## 3. Cache and ownership inventory

| Area / owner | Cached or retained state | Recheck result and evidence |
| --- | --- | --- |
| `EntryCache` | Canonical base values, requested decorated results, reverse membership and revisions | R3 corrected mutation scope. `null`/`false`, current binding, independent identity and stale in-flight publication are covered by alias and [entry isolation](../../tests/Contract/EntryCacheIsolationContractTest.php) contracts. |
| `AliasResolver` | Compressed paths to canonical IDs | Path cache clears when the map changes. The new changed-target scan includes unwarmed aliases. Retarget, ancestor chains, old-index cleanup, same-canonical changes and protected targets were checked. |
| `DelegatorRegistry` | Per-container resolved callable chains, declaration dependencies and revisions | Bound/deferred receiver isolation, replacement, transitive invalidation, external registration takeover, registration atomicity and suspended dependency resolution were checked. Pipeline results remain shared through `get()`. |
| `ExternalContainerRegistry` | Ordered container instances, not cached ownership answers | Direct `get()` observes live first-owner availability. Removing external ownership reveals the same previously cached local instance. Protected IDs remain local. |
| Entry resolvers and factory validation | Definitions, validation metadata, stable parameter targets | Fresh factory parameters and current references are preserved. Same-class factory/decorator instances in two containers are not substituted. [ClassDefinitionValueLifetimeContractTest](../../tests/Contract/ClassDefinitionValueLifetimeContractTest.php) verifies fresh constructor/method object defaults, including positional variadic materialization. |
| `CallableResolver` / `CallableExecutor` | Method staticness and stable named signature plans | Current receiver/service/alias binding remains separate from cached signature metadata. Twenty-four callable forms, case/leading-slash names, class aliases, native optional omission, full signatures and failure recovery are compared. |
| Prepared parameter structures and target factory | Immutable target classification and resolver slots, with pipeline owner/revision | R2 fixes incidental-autoload refresh. Runtime values/defaults/variadics are recalculated. Caller-owned targets are collectable after GC; fresh named reflectors reuse one stable target. |
| Attribute definition/plan registry | Semantic definitions, resolved inheritance, target flags and named plans | R1 fixes discovery completeness. Unknown-name availability remains live. User rules disable semantic-plan memoization. Runtime attribute arguments and instances are not cached as values. |
| `AttributeProcessor` | Targets/order and handler presence for an object class | R4 fixes direct-consumer completeness. Inherited private members, traits and aliases preserve their native declaring scopes. Object-bound reflectors remain excluded from name caches. |
| `ObjectPipeline` and creation context | Class metadata, constructor plans, per-attempt pending writes and policies | Ordinary/non-memoized plans agree. Lazy/proxy initialization, discard and failed-attempt retry preserve policy and argument lifetime. No new runtime-value retention was found. |
| Completion/bootstrap guards | Metadata describing already completed work | Existing guards still reject incompatible late policy changes without replaying completed constructors/handlers. Both new discovery fixes execute before runtime handlers. |
| Request source guard | Static constructor source/name/type metadata | No request, resolved service or container is cached there. Three-container warm histories preserve distinct trusted instances and reject raw name/type-key collisions. Missing/unavailable source metadata is not frozen as a completed negative. |
| Request provider bridges | Protected factory bridge; fresh caster/validation provider lookup | Warmed mapping observes provider replacement/alias retarget, validates current raw input before casting and recovers after failure. [RequestCacheParityContractTest](../../tests/Contract/RequestCacheParityContractTest.php) compares all request source/map families, null/false and request/upload identity. |
| Weak stores and closure/deferred contexts | Per-attempt provenance and state needed for deferred execution | Weak-reference contracts cover discarded owners, captured payloads, runtime attribute arguments, caller-created targets, lazy/proxy discard and release after successful initialization. No confirmed unbounded target/value retention was found. |

Leaf handlers, setup unwrappers, configuration normalization/bootstrap, exception boundaries, definitions, native type validation and request provenance were also reread. Their current runtime values are not replaced with metadata snapshots. The existing broad contract suite remains part of the evidence, alongside the focused new controls.

## 4. Results and limits

| Check | Result |
| --- | --- |
| Full local suite, PHP 8.4.23 | 1,076 passed; 4,884 assertions; two deliberate PHP-8.5-only skips. |
| Full local suite, PHP 8.5.8 | 1,078 passed; 4,892 assertions; no skips. |
| PHPStan max, complete `src` and `tests` | No errors. The local static runtime cannot load the optional turbo extension; analysis completed successfully without it. |
| PHP CS Fixer, complete configured scope | No changes required, 353 files checked. |
| Independent reviews | Alias/base/in-flight invalidation and object-handler discovery were reviewed by a second auditor, with additional public probes. |
| Prior complete push CI, `e75aa6a` | Linux PHP 8.4/8.5, Windows PHP 8.4, coverage and mutation gates passed. Coverage 90.3%; mutation score 85.38%. These numbers belong to the baseline, not automatically to this recheck. |
| Final source CI, `94c6ec7`, Linux PHP 8.4.27 / 8.5.12 | Both complete checks and all four benchmark smoke harnesses passed; test/assertion totals match the local suites. Dependency audit reported no advisories. |
| Final source CI, `94c6ec7`, Windows PHP 8.4.27 | Complete checks passed; 1,076 passed / 4,884 assertions, two expected PHP 8.5 skips. |
| Final source CI, coverage/mutation on PHP 8.4.27 | Both gates passed: coverage 90.5%, mutation score 85.78%. Of 3,875 generated mutations across 107 files, the tool reports 3,293 tested, 31 timeouts and 551 untested. |

The original audit's CI lookup used a tool limited to pull-request runs, so it did not expose the successful push workflow. The push run was retrieved explicitly during this recheck: [baseline CI](https://github.com/componenta/di/actions/runs/43249462750). The final source run, [CI 43256979009](https://github.com/componenta/di/actions/runs/43256979009), completed successfully at 16:40 UTC. Its companion checkout SHAs are included in the evidence. The manual workflow-dispatch v4-versus-v5 benchmark job was intentionally skipped on this push; the four normal benchmark smoke harnesses ran successfully in both Linux matrix jobs.

The reference path does not prove equivalence for arbitrary third-party extensions that violate their contracts. `supports()` must classify immutable target metadata; state-dependent results belong in `resolveParameter()`. That permitted mutable-value path is tested. Config `LazyValue(cache: true)` deliberately caches under its own Config context, while `cache: false` reads fresh values; a shared factory/LazyValue probe confirms separate container contexts. These policies must not be mislabeled as DI metadata-cache leaks.

Likewise, changing an ordinary dependency does not rebuild every already-created shared consumer: this container has no general graph for that purpose. Explicit factory and `ClassDefinition` paths own their lifecycle. `make()` does not apply the `get()` delegator pipeline or delegate creation to external PSR-11 containers. Those are established entry contracts, not cache parity defects to silently rewrite.

## 5. Performance and architecture decisions

### Controlled runtime comparison

The unchanged `RuntimeBench.php` was run in baseline → fixed → fixed → baseline order on each runtime, exactly eight sequential processes. Each process uses 30,000 runtime operations, 1,000 builds and the harness's seven-round medians; CLI OPcache is enabled and JIT is off. The two snapshots use the same installed dependencies. The source wrapper overrides the optimized Composer classmap and root autoloaded functions, then checks the actual reflection file of every loaded DI declaration. All **130 loaded declarations in all eight processes** belonged to the intended snapshot.

Below, each source figure is the median of its two process medians. It is not a confidence interval or a pooled fourteen-round sample.

| Operation | PHP 8.4.23 baseline → fixed, µs | Change | PHP 8.5.8 baseline → fixed, µs | Change |
| --- | ---: | ---: | ---: | ---: |
| Build default container | 94.590 → 100.957 | +6.73% | 93.965 → 95.263 | +1.38% |
| `make()` without arguments | 6.839 → 7.240 | +5.86% | 7.117 → 7.078 | −0.56% |
| `make()` with autowiring | 15.255 → 14.784 | −3.08% | 15.317 → 15.194 | −0.80% |
| Call reused closure | 18.874 → 19.530 | +3.47% | 17.697 → 18.983 | +7.27% |
| Call fresh closure | 18.764 → 20.224 | +7.78% | 22.433 → 19.086 | −14.92% |
| Call method array | 9.109 → 8.677 | −4.75% | 12.627 → 8.842 | −29.97% |

There is a positive latency signal for reused closures in both adjacent comparisons on both runtimes: approximately **+0.66 µs on PHP 8.4 and +1.29 µs on PHP 8.5** in the aggregate. The newly required revision checks perform additional work on non-memoized paths, so this result must remain visible as a correctness cost to investigate in a later optimization. It does not justify removing checks that fix the reproduced missing-source/handler failures. These measurements alone do not isolate the cause of every timing difference.

Other results vary substantially between processes. The apparent PHP 8.5 method-array improvement includes baseline medians of **8.925 and 16.328 µs**; its fresh-closure baseline is **18.412 and 26.455 µs**. These figures do not establish 15–30% improvements. PHP 8.4 fresh-closure adjacent changes have opposite signs (+17.96%, −1.94%). The evidence keeps every process result and adjacent comparison; none was discarded to improve the conclusion. Two processes per source/runtime are insufficient for a statistical-significance claim or an application-throughput guarantee.

### Unavailable-attribute polling remains measurable

The availability harness was repeated on the final source with independent containers, seven rounds of 10,000 operations, 2,000 warmups, OPcache off (`-n`) and the same counting-loader protocol as the first audit.

| Scenario | PHP 8.4.23 median | PHP 8.5.8 median | Unavailable-name autoload calls per operation |
| --- | ---: | ---: | ---: |
| Warm `make()`, clean registry | 8.20 µs | 7.36 µs | 0 |
| Warm `make()`, one unavailable attribute previously observed | 33.55 µs | 36.39 µs | 22 |
| Warm `make()`, eight unavailable attributes previously observed | 232.04 µs | 230.07 µs | 176 |
| Shared `get()`, all three registry states | 0.32–0.34 µs | 0.33–0.38 µs | 0 |

The exact counts are unchanged in every measured round on both runtimes. The timings include instrumentation and do not use the OPcache setting of the preceding runtime table. The correctness fixes did not silently introduce permanent negative caching or suppress late loading to make this benchmark look faster.

The known unavailable-attribute polling issue remains an architectural/performance proposal under [attribute-availability-performance.md](../plans/attribute-availability-performance.md). Negative caching, a TTL, or checking only whether the registered autoloader list changed is not an accepted shortcut. The new readiness fixture shows the same loader becoming effective inside a dependency factory without changing that list and without directly declaring the attribute. Current resolution must still observe it.

The performance plan now explicitly requires coherent discovery before composition validation, handler-presence queries and prepared-slot publication. It also includes the loader-readiness control. Existing stable cache hits retain their fast paths in the three metadata fixes. The alias mutation scan is outside the ordinary lookup path; source replacement and requested-pipeline mutation retain different invalidation scopes.

The [PHP 8.5 callback plan](../plans/php85-callbacks.md) now references these executable cache/lifetime controls. `Transform`, callback `SetUp`, the semantic-first-argument seam and union selection remain planned work. Their acceptance matrix requires equivalent complete signatures, current suffix dependencies, native closure state, late-load stabilization and unaffected shared identity before any optimization can be accepted. The current line still requires PHP `^8.4`; its PHP-8.5-only test syntax is evaluated only after the version gate.

## 6. Published work

Each completed implementation/test slice was pushed to the existing `main` branch without a force push.

| Commit | Slice |
| --- | --- |
| `56784b07` | Attribute discovery coherence and regression. |
| `57b89748` | Request source/provider/cache parity contracts. |
| `7ae200e2` | Parameter classification refresh and regression. |
| `2887a23c` | Callable/parameter plan parity and lifetime contracts. |
| `54be73b4` | Requested-pipeline invalidation and regressions. |
| `0aa20097` | Entry/cache/container isolation contracts. |
| `dad59a94` | ClassDefinition fresh values/default lifetime contracts. |
| `55fd5847` | Object/attribute/lazy/proxy cache parity and native PHP 8.5 closure contracts. |
| `94c6ec77` | Object-handler discovery coherence and regression. |

This report and its evidence accompany the updated plans in a subsequent documentation-only commit. The production/test identity used for validation remains explicit above.
