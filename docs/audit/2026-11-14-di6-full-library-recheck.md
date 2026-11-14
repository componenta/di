# DI 6: full-library review and verification

[Русская версия](2026-11-14-di6-full-library-recheck.ru.md)

**Date:** November 14, 2026. **Branch:** `refactor/di6-definitions-callbacks`.

The fresh review of the complete library found **three reproducible production defects** and several gaps in tests of otherwise correct behavior. The fixes and strengthened tests are integrated in code/test head [`3f03d0c`](https://github.com/componenta/di/commit/3f03d0c5d6f26162f6641428408e0e63702cc5ad). Final local and hosted library checks pass, including full coverage and mutation testing. Performance and current-source consumer verification are complete and recorded below. **E1 remains open. The DI 6 release awaits stable-platform acceptance.**

The [evidence bundle](2026-11-14-di6-full-library-evidence.json) records source identity, review inventories, commands, logs, dependencies and individual outcomes. This report covers the new full-library pass from [`9f006fa`](https://github.com/componenta/di/commit/9f006fadf983bf3191226335c735a0f7763ab375); earlier implementation history remains in the [preceding audit](2026-11-14-di6-refactoring.md).

## Scope and source identity

The deduplicated review inventories cover **169 of 169 production files**. Independent passes examined the following areas and their relevant public-contract tests:

| Area | Boundaries reviewed |
| --- | --- |
| Core and entry resolution | Configuration and bootstrap, central definition ownership, snapshots and attachment, aliases, factories, delegators, external containers, cache publication, retirement, Fiber ownership and exception normalization |
| Parameters, callables and requests | Callable routing and native invocation, ordinary and semantic-prefix parameter resolution, typed/default/variadic arguments, simple unions, resolver result validation, request provenance and DTO transport |
| Attributes and object lifecycle | Definition discovery, capability ordering, plan caches, completed-phase proof, property ownership and staged writes, eager/lazy/proxy creation, retries, `Transform`, `SetUp` and object lifetime |
| Integration | Corresponding interfaces and exceptions, complete suites, static analysis, formatting, Composer metadata and deterministic mutation-history parity |

Review included existing behavior outside the recent diff. Tests were assessed for observable values, identities, side effects, failure timing and recovery. Source inventory coverage is not a proof of every possible execution path or of arbitrary third-party extension behavior.

The exact tested code/test head is `3f03d0c5d6f26162f6641428408e0e63702cc5ad`. Its source identity is:

| File set | Count | SHA-256 |
| --- | ---: | --- |
| Production `src` | 169 | `dee6a637b8246ad16d9189291c587203224aff9bad2b790a895acf50d1b2e821` |
| `src` and `tests` | 406 | `de9c38d8bc1eaa52191180ef71a8b9d3f9d668039ba361d6fe0bc92777a24254` |

The follow-up commit containing this report changes documentation and the standalone cost reproduction. Its `src` and `tests` bytes match the tested tree above. Each hosted result below retains the identity of the commit that its run executed.

## Production defects corrected

### Bootstrap finalization after late semantic discovery

A later parameter resolver's immutable `supports()` check could autoload a previously unknown DI attribute after bootstrap had already checked the affected dependency. The container could then accept an object created with incomplete semantics: a cached dependency and a fresh one behaved differently.

[`91ba2cd`](https://github.com/componenta/di/commit/91ba2cdc6a4877fd252b23fadca876d4ae162297) makes `BootstrapResolutionGuard::finish()` stabilize its completed-use proof against the plan revision. If classification discovers relevant semantics, earlier uses are checked again. The guard rechecks metadata and resolver classification without replaying completed dependency creation, attribute handlers or parameter value resolution. Unrelated metadata remains permitted.

[BootstrapFinalizationContractTest](../../tests/Contract/BootstrapFinalizationContractTest.php) failed before the fix on both PHP versions: **2 failures and 1 passing control**. The related bootstrap suite passes afterward: **28 cases / 88 assertions** per runtime.

### Attribute selection during autoload

During a definition lookup, a later `is_a()` probe could load an attribute class that an earlier probe had skipped. Selection from that single pass was incomplete, allowing cache state and lookup order to affect the selected definitions.

[`93d1bc5`](https://github.com/componenta/di/commit/93d1bc53a0ef7f12d358f652115b47f492426bc4) stabilizes selection in `AttributeDefinitionRegistry`, keeping discovery and caching within the existing owner. It preserves unresolved-name polling and the established late-availability contract.

[AttributeLookupStabilizationContractTest](../../tests/Contract/AttributeLookupStabilizationContractTest.php) produced **6 failures and 12 passing controls** before the fix on each runtime. All **18 cases / 64 assertions** pass afterward, covering the relevant cached and uncached lookup paths.

### Extension factory routing during materialization

An extension factory configured as `[owner, method]` could retain caller reference slots. Owner resolution or callable-inspection autoload could rebind those slots midway through materialization and redirect the method invoked for that extension.

[`dc94d0a`](https://github.com/componenta/di/commit/dc94d0a4664cc232ff510eb4be081b3457d67871) snapshots array slots at entry to `ContainerFactory::materializeExtension()`, before callable inspection or owner lookup. Keys and actual factory object identity remain intact. Changes made before a later extension starts remain visible; mutable factory state stays live.

[ExtensionInvocationSnapshotContractTest](../../tests/Contract/ExtensionInvocationSnapshotContractTest.php) covers parameter-resolver and attribute-definition factories. Before the fix, **4 cases failed and 2 controls passed** on each runtime. The new cases and related bootstrap/normalization tests now pass together: **54 cases / 150 assertions**.

## Test gaps closed

The pass adds **44 cases**: **27 regression/control cases** attached to the three fixes and **17 cases strengthening existing contract assertions**. The latter required no production correction. They are recorded in [`3f03d0c`](https://github.com/componenta/di/commit/3f03d0c5d6f26162f6641428408e0e63702cc5ad).

| Existing contract strengthened | Added cases | Distinct faulty implementations rejected |
| --- | ---: | ---: |
| Malformed resolver tuples fail before target invocation; the same container recovers | 6 | 1 |
| A union with one DTO class and no array alternative constructs that DTO | 2 | 1 |
| Same-named parent/child private properties receive their own services in eager/lazy/proxy construction | 3 | 1 |
| Static `SetUp` fails before dependency resolution or method execution | 1 | 1 |
| Raw and definition replacement retain the old base until complete invalidation | 2 | 2 |
| A suspended factory cannot republish its old base after definition replacement | 1 | 1 |
| A decorated `null` remains shared until source invalidation | 1 | 1 |
| Recursive external `has()` terminates while independent IDs and recovery remain usable | 1 | 1 |
| **Total** | **17** | **9** |

These nine intentionally faulty implementations were exercised in isolation. Counts are by distinct implementation, not by runtime or number of failing datasets. The retirement cases use scalar decorations and avoid retaining the old base in the test; the recursion fixture has a bound so a broken guard fails without hanging. The mutation evidence demonstrates that the assertions detect the specified contract failures.

The prior hosted mutation report supplied review leads. Equivalent mutations, formatting-only diagnostics and changes without a demonstrated public-contract effect were not counted as production defects. These targeted results do not establish a new whole-suite mutation score.

## Final local verification

The final checks use the source/test bytes identified above. Complete suites were repeated after the last added contract test.

| Check | Result |
| --- | --- |
| PHP 8.4.23, reverse order | **1,412 passed; 18 expected native-PHP-8.5 skips; 23,768 assertions** |
| PHP 8.4.23, OPcache enabled, JIT off, random seed `7328414` | Same passing counts |
| PHP 8.5.8, reverse order | **1,430 passed; 23,870 assertions** |
| PHP 8.5.8, OPcache enabled, JIT off, random seed `7328514` | Same passing counts |
| PHP 8.5.13RC1, full suite, seed `7315823` | **1,430 passed; 23,870 assertions** |
| PHPStan maximum level | No errors |
| PHP CS Fixer | **401 files**, no formatting changes required |
| Composer `validate --strict` | Pass |
| Mutation-history parity, each stable test runtime | **1,200 operations / 28,800 warm/cold value comparisons**, no divergence |
| Original definition-lifecycle and cache-retirement reproductions | Pass on PHP 8.4.23 and 8.5.8 |

Mutation-history parity is a state-transition check, separate from intentionally mutating production code. Exact commands, seeds and log hashes are retained in the evidence bundle.

Dependency evidence identifies the installed graph through its metadata hash and package references, including Config 3.0.0, Reflection 2.0.1 and Validation 3.0.1. The local checkout has no `composer.lock`; the recorded installed graph defines the dependency scope of these runs. Strict Composer validation establishes valid package metadata and is not reported as a new dependency-security audit.

## Completed hosted verification

[Run 43642882924](https://github.com/componenta/di/actions/runs/43642882924) completed **successfully** at **22:15:33 UTC on November 14, 2026**, for code/test head `3f03d0c5d6f26162f6641428408e0e63702cc5ad`. All four required jobs passed.

| Job | Result |
| --- | --- |
| Linux, PHP 8.4.27 | **1,412 passed; 18 expected native skips; 23,768 assertions**; static analysis and style pass |
| Windows, PHP 8.4.27 | **1,412 passed; 18 expected native skips; 23,768 assertions**; static analysis and style pass |
| Linux, PHP 8.5.12 | **1,430 passed; 23,870 assertions**; static analysis and style pass |
| Coverage/mutation, PHP 8.4.27 | **91.5% coverage** against the 80% requirement; **86.94% mutation score** against the 85% requirement |

The full mutation run reports **3,578 tested, 38 timeout and 543 untested mutations**, for **4,159 total**, using four parallel processes. Its reported execution duration is **2,702.65 seconds**; GitHub step timestamps span **2,851 seconds**. The commands were `composer test-coverage -- --coverage-clover coverage.xml` and `composer test-mutation -- --parallel`. The evidence bundle preserves the exact summaries, job identities, commands and complete job-log hash. The historical benchmark job is restricted to workflow dispatch and was skipped as expected.

## Performance

The baseline is `9f006fa`; final source is `3f03d0c`. Both use the same installed dependencies, PHP binary and `-n` settings. For each runtime and harness, processes run **baseline–final–final–baseline serially**. Each process reports the median of seven rounds; the table uses the median of the two process medians for each source. Standard workloads use 20,000 operations per round, or 5,000 builds. The selected-source loader checks the origin of every loaded DI declaration and function.

Across **68 standard workload/runtime comparisons**, the largest observed median increase was **2.56%**. The process spread does not support interpreting every small change as an effect of the patch. In particular, the apparent 14% reduction in shared-get latency on PHP 8.4 includes a baseline process range of 189.6–257.4 ns; that unchanged execution path is not claimed as an optimization.

| Standard workload | PHP 8.4 baseline → final, µs | Change | PHP 8.5 baseline → final, µs | Change |
| --- | ---: | ---: | ---: | ---: |
| Empty build | 84.938 → 84.867 | −0.1% | 88.163 → 89.234 | +1.2% |
| Representative build | 104.703 → 104.158 | −0.5% | 108.168 → 110.666 | +2.3% |
| Autowired construction | 12.349 → 12.013 | −2.7% | 12.654 → 12.892 | +1.9% |
| Three transforms | 31.663 → 31.783 | +0.4% | 32.839 → 33.679 | +2.6% |
| Callback SetUp | 40.130 → 37.743 | −5.9% | 40.402 → 40.986 | +1.4% |
| Construction with eight tracked unavailable attributes | 146.902 → 147.614 | +0.5% | 158.265 → 156.070 | −1.4% |
| Six prepared parameters | 21.103 → 21.029 | −0.4% | 22.849 → 22.857 | +0.0% |

A separate [boundary reproduction](reproductions/AttributeExtensionBoundaryCosts.php) measures the operations changed by the fixes. Its eight processes use the same serial order, seven rounds, 100,000 derived-definition lookups or 2,000 builds per round. Each build materializes two array factory specifications, one resolver and one attribute definition; inputs are prepared outside timing, and every operation constructs a fresh container.

| Changed boundary | PHP 8.4 baseline → final, µs | Change | PHP 8.5 baseline → final, µs | Change |
| --- | ---: | ---: | ---: | ---: |
| Loaded derived-definition lookup | 0.2342 → 0.2905 | +24.0% | 0.2725 → 0.3138 | +15.2% |
| Build with two static factory tuples | 88.957 → 89.569 | +0.7% | 94.401 → 97.863 | +3.7% |
| Build with two service-method tuples | 96.489 → 98.155 | +1.7% | 104.197 → 107.072 | +2.8% |

The additional availability check has an observable cost of **41–56 ns per direct derived-definition lookup** in this one-parent fixture. Both final process medians exceed both baseline medians on each PHP version. Exact registered definitions return before that check; reuse of an existing named plan returns before definition discovery. Uncached plan construction still pays the lookup cost. The build-process ranges overlap, so the smaller build deltas cannot be isolated as the cost of copying the tuple alone. Full samples and source checks are retained in the evidence.

To run the boundary reproduction against a selected source tree, set `COMPONENTA_DI_BENCH_AUTOLOAD` to the shared `vendor/autoload.php`, `COMPONENTA_DI_BENCH_SOURCE` to that tree's `src` directory, and `COMPONENTA_DI_BENCH_LOADER` to this repository's `benchmarks/autoload.php`, then run `php -n docs/audit/reproductions/AttributeExtensionBoundaryCosts.php`. The new reproduction passes PHP lint on both versions and its own formatting check.

## Consumers

The isolated consumer check uses an archive of the exact DI commit `3f03d0c`. App source is [`49025670f902c596af886ee4493e114c8b44303b`](https://github.com/componenta/app/commit/49025670f902c596af886ee4493e114c8b44303b); its 67 source/test/configuration files were checked against the frozen original archive. Original projects and manifests were preserved.

| Current-source integration | PHP 8.4.23 | PHP 8.5.8 |
| --- | --- | --- |
| Complete App suite | **52 tests / 220 assertions**, random seed `20261114` | **52 tests / 220 assertions**, reverse order |
| Failures / errors / skips | 0 / 0 / 0 | 0 / 0 / 0 |
| No-dev consumer smoke | Pass | Pass |

The consumer regenerates an authoritative classmap; App regenerates its optimized autoloader. All **168 DI class mappings**, all actually loaded DI declarations and **all six DI functions** point to the new isolated source. All 169 production files byte-match the root checkout before and after execution and reproduce its normalized fingerprint. Smoke checks cover container/config identity, shared/fresh/set behavior, direct strategies, callback isolation, `Transform`, union selection/ambiguity and committed retirement failure.

The App setup retains the earlier temporary development alias (`dev-refactor/di6-definitions-callbacks as 5.0.99`) in the isolated test project. This validates runtime behavior with the new source; adoption of the eventual major release still requires deliberate consumer constraint changes.

## E1 and remaining boundary

The official [stable source listing](https://www.php.net/downloads.php?source=Y), checked again on November 14, still lists PHP 8.5.12. PHP 8.5.13RC1 remains on the [pre-release page](https://www.php.net/pre-release-builds.php). Hosted PHP 8.5.12 library checks do not execute this standalone native acceptance fixture.

The independent native acceptance runner still reports **2/10 semantic cases on PHP 8.5.8** and **10/10 on PHP 8.5.13RC1**. `stable_runtime_acceptance_passed` is **false for both**, and both commands exit **1**. The RC proves candidate semantics; it does not satisfy stable-release acceptance. The successful library suites are a separate result.

E1 requires an officially released stable patch with the relevant trait-callable correction, verified tagged source and acceptance of the exact binary. This report changes no Composer floor, branch alias or release-platform promise and publishes no DI 6 release. The conditional availability optimization and request-to-DTO callback feature retain their separately deferred scope. Within the reviewed implementation-ready library contracts, no further confirmed production defect remains after these three fixes.
