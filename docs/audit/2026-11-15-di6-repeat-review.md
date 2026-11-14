# DI 6: repeated library review and platform correction

[Русская версия](2026-11-15-di6-repeat-review.ru.md)

**Date:** November 15, 2026 (Europe/Kaliningrad). **Branch:** `refactor/di6-definitions-callbacks`. Execution timestamps in the evidence use UTC.

The repeated review found and corrected **two further production defects**: unstable parameter-attribute discovery after autoload and stale ownership decisions during reentrant external-container registration. Both corrections preserve the existing architecture. The final code/test commit is [`ea49d78`](https://github.com/componenta/di/commit/ea49d78222a5e0cf9dc5b9a279c45b15c862059b).

The review also corrects an excessive planning restriction: **DI 6 retains PHP `^8.4`; E1 does not block the whole library release.** PHP 8.5 is required for particular new expressions written directly in attribute arguments. E1 limits the support promise for the affected native trait-callable forms on a verified runtime. The distinction is now normative in the [platform specification](../plans/php85-callbacks.md#8-php-version-and-release-compatibility) and [migration guide](../migrations/di6-callbacks.md).

The [machine-readable evidence](2026-11-15-di6-repeat-evidence.json) retains source identities, review inventories, commands, RED/GREEN results, independent probes, hosted checks and measurements. This pass starts at [`4dc7721`](https://github.com/componenta/di/commit/4dc7721adbeb36684ccb1d4f1f3fbbe85cbd1f8a). The [preceding review](2026-11-14-di6-full-library-recheck.md) remains historical evidence for its own source tree.

## Platform policy: what was wrong and what changes

The previous specification promoted a restriction on affected PHP expressions into a condition on the entire DI 6 release. That was an assistant planning decision, not an explicit requirement recovered from the user's instructions. It was stronger than the implementation and compatibility evidence justify.

| Boundary | Current decision and evidence |
| --- | --- |
| Package requirement | Keep `"php": "^8.4"`. The implementation and complete PHP 8.4 suites support this baseline. No Composer floor was raised by this correction. |
| New syntax in attributes | Inline capture-free `static function` expressions and first-class callable references directly in attribute arguments require PHP 8.5. The official [closure RFC](https://wiki.php.net/rfc/closures_in_const_expr) and [callable-reference RFC](https://wiki.php.net/rfc/fcc_in_const_expr) specify this language boundary. |
| Affected native forms | The upstream trait-relative/mixed-visibility callable issue remains a real PHP limitation. E1 requires verified runtime semantics before claiming support for those forms. Supported alternatives include the verified static closure forms and public helper references. DI does not emulate or rebind callback scope. |
| Major version | Definition/extension migration, widening the public `SetUp::$method` property, and changed simple-union selection behavior justify the major version independently of PHP 8.5. |
| Release preparation | Alias preparation, ordinary library CI and the DI 6 release are independent of E1. DI 6 remains unreleased; this review does not create a release or change an alias. |

The standalone `tests/php85-platform.php` runner is deliberately outside `composer check`. Its semantic result is separate from its stable-runtime predicate, which currently recognizes stable **8.5.x** only. An RC or later PHP series failing that predicate is not a whole-library compatibility failure. Fresh runs at `ea49d78` reproduce the historical results: **2/10 semantic cases on PHP 8.5.8 and 10/10 on PHP 8.5.13RC1**, with `stable_runtime_acceptance_passed=false` and exit code 1 for both. PHP 8.5.8 fails semantic acceptance; the RC passes semantics but fails the stable-version predicate. PHP 8.4 exits 2 before running the unsupported syntax fixture. These are results of the specified binaries, not a claim that the RC is a supported stable release or that a newer stable binary was tested with this runner.

The correction is synchronized across English and Russian README, plans and migration guidance. Four earlier audit documents carry explicit supersession notices; their original execution results and JSON evidence remain unchanged.

## Review coverage and exact source

Independent review inventories cover **169 of 169 production files**, including behavior outside the recent diff. The core pass read 59 files, parameter/callable/request mapping 77, attribute/lifecycle/platform 65, and root cross-review 21; these scopes overlap. Tests were assessed for observable values, identities, side effects, failure timing and recovery. Inventory coverage does not establish every possible execution path or arbitrary extension behavior.

| File set at `ea49d78` | Count | SHA-256 |
| --- | ---: | --- |
| Production `src` | 169 | `93e11c49b6554a7bf821deb1aa3889a9f1347e91ca8ebe65460e0b958a42d595` |
| `src` and `tests` | 407 | `f50cfdeb12d43baead9e9ca43f21fff68565e49ebe7624a45cb7ffd812761a74` |

There are 215 `*Test.php` files and ten isolated native-syntax fixtures. The documentation commit containing this report preserves the code/test bytes above. Some local commands ran before the final commit was created; their recorded before/after fingerprints match this committed tree exactly. Their original checkout HEAD is retained rather than relabeled.

## Corrections and regression proof

### Parameter attributes revealed by later autoload

With an earlier unresolved attribute followed by a later attribute, loading the later type could also define the earlier type or an alias for the queried base. `ParameterTarget::firstAttribute()` could return the later declaration on the first call and the earlier one on the next. `hasAttribute()` could similarly change from false to true. A custom resolver using the public helper exposed the same cold/warm difference through `Container::call()`.

[`35c6cd1`](https://github.com/componenta/di/commit/35c6cd16a21381316793fd1201d1aa9846ecb373) keeps discovery in `ParameterTarget`, then reselects in declaration order from already loaded types when discovery occurred. The corrective pass does not trigger another autoload or instantiate an attribute before selection is complete. Exact and early-positive `hasAttribute()` paths remain intact; class, interface and trait availability preserve native invalid-attribute errors.

[ParameterTargetDiscoveryContractTest](../../tests/Contract/ParameterTargetDiscoveryContractTest.php) adds **11 cases: six regressions and five controls**. The initial regression suite was RED on both runtimes: six failures and four passing controls. A subsequent native-trait control also rejected an intermediate implementation that incorrectly skipped traits. The final helper contract pair passes **13 cases / 55 assertions** on each runtime. Independent probes match native results or native Error messages across eight valid/invalid metadata kinds on both versions.

The first hosted run exposed a test-fixture static-analysis problem: `InvalidMetadata` was an otherwise unused trait. [`0c2cb70`](https://github.com/componenta/di/commit/0c2cb70cf52e8ba31ddd03d770bdf343262835ab) adds a normal fixture class using it. The invalid-attribute control remains unchanged; no baseline or suppression was introduced. This was a test-only follow-up, not a third production defect.

### External registration after nested ownership changes

An external candidate's effectful `has()` callback could register an earlier owner and warm a shared decorated entry. The outer registration retained a stale affected set and invalidated that already-current result. Owner precedence still appeared correct, but the shared identity changed and the decorator ran twice. The same problem occurred when nested work registered the candidate itself, or a later dependency's callback established an owner for an earlier dependency.

[`ea49d78`](https://github.com/componenta/di/commit/ea49d78222a5e0cf9dc5b9a279c45b15c862059b) adds a membership revision to the existing `ExternalContainerRegistry`. Registration keeps candidate answers, refreshes existing-owner proof after nested membership changes, discovers newly added deferred IDs, and returns without publication or invalidation if nested work already registered the same candidate. Candidate `has()` runs at most once per dependency ID in each registration attempt. Errors remain inside preparation, before outer publication; successful nested work survives. The existing mutation-retirement owner is reused.

The current dependency-ID set and external membership are append-only, which makes the completion checks valid for a finite sequence of nested mutations. There is no hard iteration limit for callbacks that keep adding new work indefinitely. The [definition-owner specification](../plans/definition-owner-lifecycle.md) now clarifies the distinction between one-time candidate validation and refreshed proof against the completed registry.

[ExternalRegistrationMutationContractTest](../../tests/Contract/ExternalRegistrationMutationContractTest.php) adds **four regressions and one control**. The exact final test file against the original `Container` and registry gives **four failures and four passing cases / 40 assertions** on each runtime. The corrected implementation passes **8 cases / 54 assertions**. The fourth regression also rejects an isolated implementation that performs only one final owner-proof pass: one failure / five assertions on each runtime. It explicitly requires both nested warmed results, so skipping the second callback cannot make the test pass.

Independent public probes additionally cover final-proof rejection, the same candidate registered from an existing-owner callback, an earlier owner found during initial proof, and a deferred ID introduced during final proof. All four probes pass with **18 explicit checks per runtime**, with declaration/function origins verified.

## Final local verification

The pass adds **16 cases and 69 assertions** to the complete stable-runtime suites. Complete checks use the final fingerprint above.

| Check | Result |
| --- | --- |
| PHP 8.4.23, random seed `1584015` | **1,428 passed; 18 expected native-PHP-8.5 skips; 23,837 assertions** |
| PHP 8.5.8, OPcache enabled, JIT off, seed `1585015` | **1,446 passed; 23,939 assertions** |
| PHPStan, `analyse src tests --level=max --no-progress --memory-limit=1G` | No errors |
| PHP CS Fixer, no cache | **402 files**, no changes required |
| PHP 8.4 syntax, every production file | **169/169 pass**, on the final committed source |
| Mutation-history parity, each runtime | **1,200 operations / 28,800 warm/cold comparisons**, no divergence |
| Original public reproductions, each runtime | Attribute order stable; nested warmed identity retained; selected decorator called once |

The local dependency installation is recorded by metadata hashes and package references. Composer validation passed earlier in this pass, and the manifest was unchanged by the fixes. A local PHPStan warning that its optional Turbo extension cannot load does not prevent analysis; the command exits zero with no analysis errors.

## Hosted verification

The exact final code/test run is [43650778388](https://github.com/componenta/di/actions/runs/43650778388), for `ea49d78222a5e0cf9dc5b9a279c45b15c862059b`.

| Job | Result |
| --- | --- |
| Linux PHP 8.4.27 | **1,428 passed; 18 expected skips; 23,837 assertions**; static analysis, style, metadata and dependency audit pass |
| Windows PHP 8.4.27 | **1,428 passed; 18 expected skips; 23,837 assertions**; static analysis and style pass |
| Linux PHP 8.5.12 | **1,446 passed; 23,939 assertions**; static analysis and style pass |
| Coverage and mutation, PHP 8.4.27 | **91.5% coverage** against the 80% requirement; **86.97% mutation score** against the 85% requirement |

The run completed **successfully**; its final GitHub update is **23:27:41 UTC on November 14, 2026**. All four required jobs passed. The mutation summary records **3,614 tested, 56 timeout and 550 untested mutations**, **4,220 total**, with four parallel processes. Its reported duration is **1,678.95 seconds**; GitHub step timestamps span **1,755 seconds**. The coverage and mutation baseline suites each report **1,428 passed, 18 expected skips and 23,837 assertions**.

The commands were `composer test-coverage -- --coverage-clover coverage.xml` and `composer test-mutation -- --parallel`. The evidence retains full decoded job-log hashes, commands, job/run identifiers and timestamps. The historical v4.0.10-versus-v5 benchmark job was skipped as configured for push events; it is restricted to `workflow_dispatch`. The new local measurements below are separate from that job and from the preceding review's mutation score.

## Measured costs

### Parameter attribute helpers

The comparison uses exact archives of `4dc7721` and `35c6cd1`, the same vendor and binary, and **seven serial baseline–fixed–fixed–baseline rounds on each runtime**: 56 processes in total. Each process performs 20,000 warmup calls followed by 500,000 timed calls for each of six cases with one already loaded attribute. Loaded `ParameterTarget` and `TypeHints` origins and hashes are checked, and every sample records zero autoload callbacks. This benchmark measures ordinary loaded-type lookup, not the repaired late-autoload path. OPcache CLI is off. Later commits leave this production helper unchanged.

The table reports the median of 14 samples per variant, in **nanoseconds per call**; percentages are derived from those medians.

| Helper path | PHP 8.4 baseline → fixed | Change | PHP 8.5 baseline → fixed | Change |
| --- | ---: | ---: | ---: | ---: |
| `hasAttribute`, exact | 47.94 → 48.14 | +0.4% | 50.82 → 51.71 | +1.8% |
| `hasAttribute`, inherited | 109.99 → 188.99 | +71.8% | 113.41 → 210.71 | +85.8% |
| `hasAttribute`, miss | 109.77 → 197.01 | +79.5% | 121.07 → 213.59 | +76.4% |
| `firstAttribute`, exact | 466.00 → 618.07 | +32.6% | 516.76 → 691.49 | +33.8% |
| `firstAttribute`, inherited | 487.81 → 641.01 | +31.4% | 525.61 → 721.44 | +37.3% |
| `firstAttribute`, miss | 176.18 → 273.64 | +55.3% | 179.64 → 280.37 | +56.1% |

Exact `hasAttribute()` has mixed positive/negative paired deltas consistent with measurement variation. Every other path has a positive delta in all seven paired rounds. Median paired increments are **80–110 ns** for inherited/missing `hasAttribute()` and **95–190 ns** for `firstAttribute()`, about **0.08–0.19 ms per 1,000 calls**. These are observable costs of the availability check, not an optimization claim or an estimate of whole-application latency. No new performance threshold is introduced. Raw samples and paired aggregation are retained in the evidence.

The portable [helper reproduction](reproductions/ParameterTargetHelpers.php) preserves the measured loop body. Run `php -n -d opcache.enable_cli=0 docs/audit/reproductions/ParameterTargetHelpers.php <source-tree> <vendor/autoload.php> 500000`. Its short lint/smoke checks on both runtimes and both archives validate portability; they are not added to the performance sample.

### External registration

The [registration reproduction](reproductions/registration-benchmark.php) compares exact `4dc7721` and `ea49d78` archives using the same vendor and binary. Five serial ABBA rounds per runtime produce **40 measured processes and 655,360 registrations**: four scenarios, 4,096 registrations per scenario/process. The timer contains only `foreach` dispatch over batches of 128 prepared containers and `addContainer()`. Each call receives a fresh, already warmed Container; stateless external owners, configuration, definitions and base objects are reused. Construction, initial owner registration, warming, correctness/source checks and explicit cycle collection are outside timing. Automatic cycle collection is disabled during measured batches. OPcache CLI is off.

The table uses the **median of five round-level means**, in **microseconds per registration**. Each round averages its two baseline and two final processes separately; percentages are ratios of the resulting medians.

| Registration scenario | PHP 8.4 baseline → final | Change | PHP 8.5 baseline → final | Change |
| --- | ---: | ---: | ---: | ---: |
| No deferred dependency IDs | 0.880 → 1.099 | +24.85% | 0.913 → 1.188 | +30.11% |
| Candidate takes over eight IDs | 17.799 → 21.119 | +18.65% | 22.485 → 26.862 | +19.46% |
| Candidate owns none of eight IDs | 3.550 → 5.421 | +52.71% | 3.969 → 5.892 | +48.44% |
| Earlier container already owns eight IDs | 3.053 → 4.800 | +57.25% | 3.422 → 5.324 | +55.57% |

The two middle scenarios have an already registered empty external container; the takeover case invalidates eight warm dependents. Candidate `has()` counts remain **0 / 8 / 8 / 0** in both versions. Only takeover adds another existing-owner proof, changing existing-owner calls from 8 to 16. Other scenarios retain their existing-owner counts and still pay extra registration bookkeeping.

Every paired round is slower after the correction. Absolute median costs increase by **0.219–0.275 µs** without dependency IDs, **3.320–4.377 µs** for takeover, and **1.748–1.923 µs** for the two other eight-ID paths. These results establish a cost for correctness; they do not claim performance neutrality. They do not measure ordinary `get()`, complete construction/application startup, expensive callbacks or reentrant callbacks. Observed round ranges are not confidence intervals.

An independent reviewer recomputed all 160 per-process scenario measurements and eight summary rows from the 40 raw outputs and checked both immutable source trees. Every worker verifies all 117 loaded DI declarations and six functions against its selected archive; all 169 source files are checked separately. The measured worker has SHA-256 `b60491e191cda181f9af5792188b9abfaceb73be660f8c8159acaf68182fcc41`; the published portable copy only sorts its imports for project style, preserving the exact timed body. Both file identities are retained in the evidence. Run `php -n -d opcache.enable_cli=0 docs/audit/reproductions/registration-benchmark.php <source-tree> <vendor-directory> 4096` in the same serial ABBA order. No new performance threshold is introduced.

## Consumer integration

Fresh isolated integration uses the exact DI archive `ea49d78` and frozen App source [`4902567`](https://github.com/componenta/app/commit/49025670f902c596af886ee4493e114c8b44303b). App's 67 source/test/configuration files match the original frozen archive; original projects and dependency inputs remain unchanged.

| Check | PHP 8.4.23 | PHP 8.5.8 |
| --- | --- | --- |
| Complete App suite | **52 tests / 220 assertions**, random seed `20261115` | **52 tests / 220 assertions**, reverse order |
| Failures / errors / skips | 0 / 0 / 0 | 0 / 0 / 0 |
| No-dev authoritative-classmap consumer | Pass | Pass |

All **169 source files**, **168 classmap entries**, and actual loaded DI declarations/functions resolve to the selected archive. The smoke loads 151 DI declarations, App 137, with six DI functions in each process. Checks cover construction/config identity, raw and explicit definitions, custom strategy authority/snapshots, callbacks and `Transform`, simple-union selection/ambiguity and committed-retirement failure. The source-only fingerprint matches `ea49d78` at startup and in a separately recorded post-run readback.

The isolated App setup retains its diagnostic alias `dev-refactor/di6-definitions-callbacks as 5.0.99`. This proves runtime integration of the selected sources, not compatible published DI 6 Composer constraints. Consumer adoption of the major release still requires deliberate manifest migration.

## Result and remaining scope

Both confirmed production defects and the test-fixture follow-up are pushed to the existing refactoring branch. The review adds no public abstraction or replacement callback engine. No further confirmed defect remains in the reviewed, implementation-ready library scope. Conditional attribute-availability optimization and request-to-DTO callback design remain separately deferred. Support for the affected native trait-callable forms still requires E1 evidence; it does not defer the DI 6 release as a whole.
