# DI 6 implementation and v5.0.6 release verification — November 14, 2026

[Полная русская версия](2026-11-14-di6-refactoring.ru.md) · [Machine-readable evidence](2026-11-14-di6-evidence.json)

**Continuation:** the [completion recheck](#completion-recheck) records final production source, three complete passes on each stable local runtime, candidate-runtime and consumer evidence. Earlier sections retain their own historical measurements and verification.

## Outcome and version boundaries

**v5.0.6 is published** on [GitHub](https://github.com/componenta/di/releases/tag/v5.0.6) and [Packagist](https://packagist.org/packages/componenta/di#v5.0.6). Both references identify `ad46b3098f663d6c0d55bf6d1651a526af16ac68`. The release is a compatible patch: PHP remains `^8.4`, public construction and configuration APIs are unchanged, and the four new TDD fixes plus the prior main-branch corrections are recorded in the [release audit](2026-11-14-release-audit.md).

The requested major refactoring runs separately on [`refactor/di6-definitions-callbacks`](https://github.com/componenta/di/tree/refactor/di6-definitions-callbacks), created from that release commit. Ready library slices are implemented and locally verified; **DI 6 is not released**. E1 still governs the final supported PHP patch and the affected native trait-callable support promise. The Composer constraint and branch alias have not been promoted to an unverified DI 6 platform declaration.

## Implemented architecture and behavior

| Slice | Commit | Result |
| --- | --- | --- |
| Simple-union autowiring | `135d4679` | Collect distinct named class/interface arms in a stable order; choose exactly one available service; reject ambiguity before any candidate `get()`; check the selected type. Explicit values, priorities, defaults, nullable fallback, DNF/intersection boundaries and ClassDefinition opt-in remain distinct. |
| Central definitions and retirement | `87d7a775` | One table of immutable bindings in CompositeResolver; registration-free FactoryResolver/InvokableResolver strategies; one semantic admission path; nested snapshots; attachment authority; complete publication/invalidation before native release in all four registration mutations. |
| Shared binder and callback SetUp | `f1934225` | One semantic first argument plus the existing DI suffix loop. A single completed-attribute guard covers the reserved first target and consumed suffix metadata. SetUp accepts string or Closure, preserves method setup and privately carries mapped-request diagnostics. |
| Transform | `ecea22e5` | Repeatable parameter/property transformer using the shared binder. Precise source fallback, isolated suffix parameters, existing composition order and staged property assignment with one final native write. |

DefinitionAwareResolverInterface and the old registration/owner maps are removed from source and tests. Custom strategies use DefinitionResolverInterface; public definition constructors and Container mutation signatures remain. A bare fallback resolver is still accepted by Container and wrapped by a composite. No second argument-resolution loop, general transaction mechanism, public retirement receipt, long-lived disposal queue or forced production GC was added.

All four mutators follow validation → publication → complete affected invalidation → retirement. A destructor triggered by final retirement may reenter or suspend only after the outer mutation's state writes have finished. MutationRetirementException exposes the committed mutation's ID and ordered causes; its first cause is `getPrevious()`. Already-owned Componenta exceptions preserve identity, so a nested committed mutation can propagate that exception through an outer validation that has not published. The [definition migration](../migrations/di6-definitions.md) documents this distinction.

## TDD and conformance evidence

The immutable pre-refactor source is the release commit above. New tests were copied to separate baseline workbenches; their production source was not edited for RED runs.

| Behavior | RED evidence | GREEN evidence |
| --- | --- | --- |
| Retired definition/cache roots | All three original public diagnostics failed: retained obsolete factory; shared/fresh disagreement after throwing retirement; partially invalidated sibling aliases. | 14 focused tests / 96 assertions on both runtimes, including all four mutators, ordered multiple causes, reentry, Fiber suspension, caller ownership and non-forced cycle collection. |
| Union selection | 23 failed, 6 existing-behavior controls passed. | 29 tests / 93 assertions on both runtimes. |
| Callback binder and SetUp | Initial binder/setup regressions failed before their implementation; the frozen expanded suite on baseline had 40 failures while 8 existing native Init controls passed. | New and adjacent callback, setup, request, variadic, reflection-lifetime and parameter contracts pass in the full suite. |
| Transform | Initial RED: 40 failures, 2 controls passed, 4 native skips on PHP 8.4. | Final focused suite: 46 passed + 6 native skips / 172 assertions on PHP 8.4; 52 passed / 240 assertions on PHP 8.5. |
| Admission availability regression | A new admission-path test failed when a static callable became available during discovery but lost its ABI-validation flag. | The admission snapshot retains the correct validation policy; existing late-availability cases also pass. |

Central-strategy coverage includes bootstrap/runtime transitions, immutable custom validation snapshots, first-match selection, rejection without speculative writes, raw set preserving make, definition references, native defaults, custom metadata isolation, duplicate/conflicting snapshots, detached children, shared strategies and attached-root rejection. Existing deferred factory and cache revision tests preserve in-flight behavior.

Callback coverage checks both directions of late metadata discovery without replay, first-argument isolation, native argument omission, exact suffix positions, prepared invoker decoration, classless request-source conflicts, property hooks with wider setter input, readonly/promoted properties, eager/lazy retries, and WeakReference lifetime for callbacks, values, dependencies and local static graphs. Known failing native trait forms are kept separate from passing support tests; DI does not emulate engine scope.

## Verification

| Check | Result |
| --- | --- |
| Full PHP 8.4.23 suite | 1,326 passed; 18 PHP-8.5-native skips; 23,324 assertions |
| Full PHP 8.5.8 suite | 1,344 passed; 23,426 assertions |
| PHPStan maximum level | No errors. The optional turbo extension cannot load in the static CLI; the PHP analyzer completed successfully. |
| PHP CS Fixer | 387 PHP files; zero changes required. The final benchmark-only edit was also checked. |
| Mutation-history cache parity | 600 operations and 14,400 value comparisons on each runtime. This is not a mutation-testing score or proof of exception identity. |
| Definition migration examples | Six PHP blocks in each complete language version executed on PHP 8.4 and 8.5; all four runs succeeded. |
| Callback migration examples | Four executable examples passed on PHP 8.5; EN/RU code blocks match. Relative links were checked. |
| Independent review | The mutation/retirement boundary and binder/SetUp integration were reviewed separately from their implementation; no additional confirmed defect remained. |

The final production source contains 169 PHP files. There are 201 named test files and nine native `.php.fixture` files. The evidence JSON records source and verification fingerprints, exact commits, commands and dependency mode. Local full suites use the existing companion workspace installation. Hosted branch checks install published dependencies independently; timing comparisons use one retained published-dependency installation for both source trees.

### Hosted release gates

[Release run 43589493808](https://github.com/componenta/di/actions/runs/43589493808) succeeded on the exact v5.0.6 commit: Linux PHP 8.4/8.5, Windows PHP 8.4, coverage **90.6%** and mutation score **86.09%**, exceeding the required 80% and 85%. Mutation output reports 3,359 tested, 34 timeout and 548 untested mutants. The workflow published the release at **2026-11-14 11:43:26 UTC**; Packagist was independently checked against the same source reference.

A separate clean consumer project then installed exactly `componenta/di:5.0.6` from Packagist with optimized autoloading and no development dependencies. Public shared/fresh/config-identity and alias-availability smoke checks passed on PHP 8.4.23 and 8.5.8. All 161 installed production PHP files byte-match the release checkout, and the major-only Transform API is absent. This validates the published archive as well as the repository release.

For the major source commit `ecea22e5`, [run 43591226872](https://github.com/componenta/di/actions/runs/43591226872) also completed successfully: Linux PHP 8.4/8.5, Windows PHP 8.4, coverage **91.2%** and mutation score **86.62%**. Mutation output reports 3,517 tested, 36 timeout and 549 untested mutants. This commit contains all production and test changes. The final documentation/benchmark commit preserves those production and test bytes; its separate CI rerun may be pending. Consult [current branch runs](https://github.com/componenta/di/actions?query=branch%3Arefactor%2Fdi6-definitions-callbacks) for the current head. These are the major branch's own results, separate from the patch release's scores.

## Cost measurements

Both source trees used the same PHP binary, `-n` settings, dependency installation and benchmark file. The selected-source loader asserts production class and helper-function origins even with optimized Composer classmaps. Every reported process uses seven rounds and a median, with GC between rounds; ordinary operations use 20,000 iterations per round. Runtime baseline cases finish before creating feature-only container graphs.

Initial build measurements varied materially, so construction was repeated with 5,000 builds per round, two process medians per version/runtime, in baseline–branch–branch–baseline order. The first PHP 8.4 baseline process overlapped a short diagnostic and was replaced with an isolated run; its initial value is excluded. The construction rows below use the median of process medians. Full case values and source checks are in the evidence JSON.

| Case | PHP 8.4: 5.0.6 → branch, µs | Change | PHP 8.5: 5.0.6 → branch, µs | Change |
| --- | ---: | ---: | ---: | ---: |
| `get/shared` | 0.1875 → 0.1886 | +0.6% | 0.1975 → 0.1961 | -0.7% |
| `make/no-arguments` | 5.31 → 4.97 | -6.4% | 5.60 → 5.43 | -2.9% |
| `make/autowire` | 11.80 → 11.81 | +0.1% | 11.97 → 12.21 | +2.0% |
| `call/reused-closure` | 17.54 → 17.11 | -2.4% | 17.78 → 19.07 | +7.2% |
| `call/fresh-closure` | 17.20 → 18.07 | +5.1% | 18.01 → 18.96 | +5.3% |
| `call/method-array` | 7.43 → 7.21 | -2.9% | 7.40 → 7.38 | -0.3% |
| `factory/default` | 2.03 → 1.91 | -5.8% | 2.18 → 1.87 | -14.0% |
| `factory/override` | 2.19 → 2.07 | -5.3% | 2.23 → 2.20 | -1.6% |
| `build/empty` | 62.30 → 66.59 | +6.9% | 67.93 → 70.77 | +4.2% |
| `build/representative` | 78.93 → 84.89 | +7.5% | 85.13 → 92.21 | +8.3% |

The representative bootstrap cost increased **7.5% on PHP 8.4** and **8.3% on PHP 8.5**, approximately 6–7 µs per container build. Empty builds increased 6.9% and 4.2%. These measurements describe the combined branch, including new attribute registration and binding admission; they do not isolate one feature as the sole cause. Warm shared get stayed within 1%. Some closure invocation rows were 5–7% slower, while explicit factory paths were faster in this run. These microbenchmarks establish measured costs and do not promise an application speedup.

### New paths, absolute cost

| Case | PHP 8.4, µs | PHP 8.5, µs |
| --- | ---: | ---: |
| `transform/none` | 6.01 | 6.22 |
| `transform/zeroArguments` | 11.37 | 11.72 |
| `transform/one` | 16.92 | 18.03 |
| `transform/three` | 30.33 | 32.74 |
| `transform/suffix` | 32.93 | 33.86 |
| `setup/method` | 22.95 | 23.19 |
| `setup/callback` | 37.62 | 41.66 |
| `union/zero` | 12.70 | 13.31 |
| `union/one` | 11.75 | 12.81 |
| `union/two-rejected` | 11.63 | 12.55 |

The no-transform control uses the same static-method invocation shape and supplied integer, with no attributes. The one/three cases apply one/three transformations to that value; the suffix case adds an injected dependency. Method and callback setup each inject one dependency. Union rows cover zero/one available candidates and ambiguity rejection before construction. Runtime callback constants keep this benchmark parseable on PHP 8.4; separate native fixtures prove PHP 8.5 expression identity and lifetime.

## Migration and remaining gates

The complete guides are [definitions](../migrations/di6-definitions.md) / [русский](../migrations/di6-definitions.ru.md) and [callbacks/unions](../migrations/di6-callbacks.md) / [русский](../migrations/di6-callbacks.ru.md). They cover removed extension APIs and constructors, normalizer/admission timing, snapshot identity, attachment, committed errors, SetUp subclass property widening, direct SetUpRunner wiring, source binding, native syntax and union behavior.

- **E1 remains open.** Verify the exact binary of an officially released PHP 8.5 patch containing the native trait first-class-reference correction before setting the final Composer floor, `6.0.x-dev` alias and DI 6 support/release promise. The [platform specification](../plans/php85-callbacks.md#8-php-version-and-release-compatibility) contains upstream evidence and the bounded acceptance task.
- **Attribute availability B2 remains conditional.** Application cost evidence and a complete revised synchronization contract are still required. The corrected polling contract is retained; no broad availability rewrite was performed.
- **Request-to-DTO callback transformation remains deferred.** Its source, ordering, provenance and API decisions are a separate specification task. Existing request guards remain active in the implemented callback paths.

These are explicit remaining gates, not an unfinished implementation of the specified ready library slices. The [plan index](../plans/README.md) and both full specification translations now distinguish implemented work from these conditions.

## Reproduction

Run the following with each documented PHP version in the repository with development dependencies installed:

```sh
php -n vendor/bin/pest --compact
php -n vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php -n vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
php -n docs/audit/reproductions/MutationParity.php
```

For timings, set `COMPONENTA_DI_BENCH_AUTOLOAD` to the shared vendor autoloader and `COMPONENTA_DI_BENCH_SOURCE` to either the immutable release checkout or the branch source. Run RuntimeBench, GeneratedVsReflectionBench, BuildPhasesBench and ParameterPlanBench serially with the iteration values above. `DI_BENCH_CASES` optionally selects comma-separated RuntimeBench cases; no filter was used for the final full RuntimeBench measurements.

## Follow-up: owned array snapshots and E1 acceptance

Re-entry started at `4f8acab83aaf940211a021d8c87bc2a3891f9831`, which matched the remote branch and had no uncommitted changes. The other local workbenches contained earlier implementation stages, not missing patches. [CI run 43593362160](https://github.com/componenta/di/actions/runs/43593362160) had completed successfully on that exact head. The new review found that readonly PHP array properties could still retain caller-owned reference slots, undermining the specified stable configuration boundary.

| Correction | Commit | Observable result |
| --- | --- | --- |
| Factory callable-array snapshot | [`db74140`](https://github.com/componenta/di/commit/db74140ed1c4a0b2959ef0efafa912e0920e877c) | Rebinding caller owner/method variables cannot redirect an admitted factory. Deferred resolution no longer writes the resolved owner through the caller's reference or pins that owner after service replacement. |
| Delegator routing snapshot | [`e31948f`](https://github.com/componenta/di/commit/e31948f19fe08368bc241369874582f31f37658e) | Callable routing and its dependency index use the same snapshot, including when autoload code changes caller references during discovery. Owner replacement invalidates the correct warmed entries. |
| ClassDefinition array snapshot | [`21a1ded`](https://github.com/componenta/di/commit/21a1ded6c04fc9b81d7f03d03eb70425c58a14c6) | Nested arguments and configured method routing cannot change outside registration. Array cycles/shared edges remain owned; objects retain identity, service references remain fresh and runtime overrides keep their literal semantics. |

The three regression groups failed before their corresponding production changes: **7 factory, 5 delegator and 7 ClassDefinition cases**. Admission now owns configuration reference slots; mutable application objects retain their intended business state. Recursive configured arrays still fail only when consumed, so runtime overrides can bypass unused invalid configuration. Independent checks also verified autoload-time mutation isolation and release of acyclic/cyclic payloads after replacement. No production GC, object cloning, new registry or second invocation path was introduced.

| Current local gate | Result |
| --- | --- |
| PHP 8.4.23 complete suite | 1,345 passed; 18 expected native PHP 8.5 skips; 23,421 assertions |
| PHP 8.5.8 complete suite | 1,363 passed; 23,523 assertions |
| PHPStan maximum level | No errors; the static CLI's optional turbo-extension warning does not prevent analysis. |
| PHP CS Fixer | 391 PHP files; no changes required. |
| Composer metadata and locked dependency audit | Valid metadata; no advisories or abandoned packages in the installed companion-workspace dependency graph. |
| Cache mutation parity, each runtime | 600 operations / 14,400 comparisons. |

These checks cover the three corrections and the new platform runner. The `continuation` section of the evidence JSON records production/test fingerprints, exact commands and dependency-lock identity. Hosted checks are associated with their own commit SHA; [branch runs](https://github.com/componenta/di/actions?query=branch%3Arefactor%2Fdi6-definitions-callbacks) show the current result. The earlier timing table has not been rerun or relabeled as performance evidence for these admission changes.

### Native platform progress

`composer test-platform` now runs an isolated, DI-independent acceptance fixture for the ten required trait/default/property/attribute scope and visibility scenarios. PHP 8.5.8 passes only the two unaffected controls. A minimal CLI built from the official, checksum-verified **PHP 8.5.13RC1** archive passes **10/10 semantic cases**. The command still exits nonzero for that RC because it is not a stable PHP 8.5 release; PHP 8.4 is rejected before the native fixture executes. [Platform evidence](2026-11-14-di6-platform-evidence.json) includes the exact source and binary hashes, build options and individual outcomes.

The RC source contains the upstream correction; the current stable 8.5.12 tag does not. No 8.5.12 binary result is claimed. **E1 remains open** until an officially released stable patch, its tagged source and its exact binary satisfy the same proof. The final Composer floor, branch alias and DI 6 release remain governed by that condition. Conditional availability optimization and request-to-DTO callbacks retain their separately deferred scope.

## Completion recheck

The request to finish the refactor re-entered at `35c5eb54`. Independent passes compared every implementation-ready definition/lifecycle and callback contract with its runtime paths and consumers. The library refactoring has converged: central definition ownership, mutation retirement, one shared callback parameter loop, `Transform`, callback `SetUp`, deterministic simple unions and their migration are implemented. Final production source is [`064be206`](https://github.com/componenta/di/commit/064be206fab16ce0c5a9a75d96e85f92b71ffb24); the evidence JSON records its 169-file production fingerprint and the unchanged 399-file source/test fingerprint across the final runs. E1 still governs final platform metadata and release.

### Additional corrections and review

| Correction | Commit | Contract preserved |
| --- | --- | --- |
| Semantic attribute definition arrays | [`40f61b0`](https://github.com/componenta/di/commit/40f61b0a8f7c9a52754069a409be2918e63265ff) | Caller references cannot change any of the six capability/selector/rule arrays, even during validation autoload. Original handler/rule objects retain identity and current state. |
| Callable invocation routing | [`8e36c7a`](https://github.com/componenta/di/commit/8e36c7a7c84543ef46903a71769f6c1e0e230ed4) | Reflection, argument resolution and invocation use one captured owner/method route. A later invocation may observe the caller's updated route; mutable owner business state remains live. |
| Final object-phase completion | [`378f3e4`](https://github.com/componenta/di/commit/378f3e43fcbc14a10701a4d4c01ebf9f1eb46ed0) | Both completed phases remain guarded through final configuration. A newly discovered skipped handler fails without replay or successful shared publication; a lazy/proxy retry owns fresh after-phase proof. |
| Cost correction | [`064be20`](https://github.com/componenta/di/commit/064be206fab16ce0c5a9a75d96e85f92b71ffb24) | Empty arrays need no copied slots. Combining two already verified phases retains their common revision; the next assertion still polls, and a changed/different revision validates both phases. No availability checkpoint or fallback autoload attempt was removed. |

The first three defects were reproduced before their fixes: six semantic-array failures, five callable-route failures and eight completed-phase failures. The full-suite integration pass also exposed nine metadata-only false positives in the unpublished first phase correction. The final skipped-phase proof retains validated metadata while excluding all unexecuted object handlers. Two additional positive metadata controls cover class, property and method targets under cold/warm and cached/non-memoized execution. In total, the recheck adds 23 public-contract cases across three test files.

Independent review covered definition strategies, nested snapshots and attachment, canonical-ID capture, external ownership callbacks, all four mutators, suspended invocations and retirement, object lifetime, the ordinary and semantic-prefix parameter paths, type/explicit/default/variadic resolution, union selection, staged properties, callbacks, request provenance, native callable dispatch and plan lifetime. No further actionable defect or unimplemented ready contract remained. The existing [mutation parity reproduction](reproductions/MutationParity.php) now includes factory, ClassDefinition, invokable and external-container changes: **1,200 mutations / 28,800 warm-versus-cold value comparisons per runtime**, with 12 deterministic seeds. This is separate from mutation testing.

### Repeated final verification

| Final source check | Result |
| --- | --- |
| PHP 8.4.23, reverse order | 1,368 passed; 18 expected PHP-8.5-native skips; 23,578 assertions |
| PHP 8.4.23, random seed 401684 | Same passing counts |
| PHP 8.4.23, OPcache enabled/JIT off, seed 402684 | Same passing counts |
| PHP 8.5.8, reverse order | 1,386 passed; 23,680 assertions |
| PHP 8.5.8, random seed 401685 | Same passing counts |
| PHP 8.5.8, OPcache enabled/JIT off, seed 402685 | Same passing counts |
| PHP 8.5.13RC1, full library, seed 20261114 | 1,386 passed; 23,680 assertions |
| PHPStan max / formatting | No errors; 394 PHP files require no formatting changes |
| Composer metadata / locked dependencies | Strict validation passes; no advisories or abandoned packages |

The three stable-runtime passes were repeated after the cost corrections, on identical source and test bytes. Source fingerprints, exact commands, random seeds, log hashes and dependency identity are in `completion_recheck` in the [evidence JSON](2026-11-14-di6-evidence.json). The earlier complete hosted run for `35c5eb54` is [43604203062](https://github.com/componenta/di/actions/runs/43604203062): Linux PHP 8.4/8.5, Windows PHP 8.4, **91.3% coverage** and **86.66% mutation score** (3,531 tested, 36 timeout, 549 untested). Those scores belong to that earlier source. [Current branch Actions](https://github.com/componenta/di/actions?query=branch%3Arefactor%2Fdi6-definitions-callbacks) records subsequent exact-commit results.

### Consumer and candidate-runtime proof

A clean Composer consumer installs the frozen branch archive without development dependencies or a version alias, with optimized authoritative autoloading. Its smoke succeeds on PHP 8.4.23, 8.5.8 and 8.5.13RC1. The installed 169 production files match the archive exactly. **32/32** process runs of the English/Russian README and migration examples pass; native-only examples execute on the PHP 8.5 runtimes.

The actual `componenta/app` suite at `49025670f902c596af886ee4493e114c8b44303b` also passes: **52 tests / 220 assertions** on PHP 8.4.23 and the RC. This bounded runtime-compatibility test uses an explicit temporary `dev-refactor/di6-definitions-callbacks as 5.0.99` alias in an extracted test project. App's original `^5.0.3` constraint remains unchanged. Auth (`^5.0.0`) and Skeleton (`^5.0.3`) were inspected at manifest/public integration boundaries; their full suites were not run. Their ordinary adoption of DI 6 needs deliberate downstream constraint changes after the release contract is settled.

The separately built full-extension RC uses the verified official PHP archive and a checksum-verified libxml2 build. Its source/configure/compiler/module/binary provenance is retained in the evidence. The binary passes the full library and **10/10** pure-engine semantics, while `test-platform` correctly exits **1** because stable-runtime acceptance is false. The original minimal RC evidence remains intact. No stable 8.5.12 binary result is inferred from tagged-source inspection.

### Final cost qualification

The initial paired comparison detected avoidable empty-array allocation and duplicate same-generation phase validation. Both local operations were removed while preserving the required ownership and synchronization boundaries. The final comparison below uses immutable `35c5eb54` as the pre-recheck baseline and `064be206` as the final source, the same installed dependencies and binary, `-n`, seven rounds per process, 20,000 ordinary operations or 5,000 builds per round. Each runtime runs baseline–final–final–baseline serially; the table reports the median of its two process medians. Full initial/final process results are retained separately in the evidence.

| Case | PHP 8.4 baseline → final, µs | Change | PHP 8.5 baseline → final, µs | Change |
| --- | ---: | ---: | ---: | ---: |
| `build/default` | 68.953 → 85.426 | +23.9% | 75.704 → 94.354 | +24.6% |
| `get/shared` | 0.193 → 0.185 | -4.5% | 0.207 → 0.199 | -4.3% |
| `make/no-arguments` | 5.285 → 5.675 | +7.4% | 5.551 → 6.384 | +15.0% |
| `make/autowire` | 11.982 → 12.503 | +4.4% | 12.488 → 13.205 | +5.7% |
| `call/reused-closure` | 18.652 → 18.309 | -1.8% | 18.721 → 19.001 | +1.5% |
| `call/fresh-closure` | 17.437 → 18.430 | +5.7% | 19.321 → 19.422 | +0.5% |
| `call/method-array` | 7.344 → 7.923 | +7.9% | 7.854 → 8.439 | +7.5% |
| `transform/one` | 16.438 → 17.149 | +4.3% | 17.937 → 17.834 | -0.6% |
| `setup/method` | 21.471 → 23.816 | +10.9% | 24.187 → 24.835 | +2.7% |
| `setup/callback` | 37.998 → 38.640 | +1.7% | 40.340 → 41.354 | +2.5% |

These measurements retain the remaining cost of the correctness boundaries and their observed spread. They describe the combined changes; they do not isolate one fix as the sole cause or establish application throughput. Earlier pre-feature timings are historical and have not been relabelled as measurements of this final source.

### Remaining release boundary

The implementation-ready refactor, library verification and consumer/migration proof are complete. **E1 remains open** for an officially released stable PHP patch with the trait-callable correction, its tagged source and its exact binary acceptance. Final Composer floor, `6.0.x-dev` alias and supported-platform release checks follow that evidence. Conditional availability B2 and the possible request-to-DTO callback feature retain their separately deferred scopes. No DI 6 release has been published.
