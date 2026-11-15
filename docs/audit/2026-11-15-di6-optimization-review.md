# DI optimization review — 2026-11-15

The library received three local optimizations after a complete production-source review. The changes remove temporary match arrays and redundant cycle-guard work while preserving the tested contracts. Full local checks, consumer integration and all four required hosted CI jobs pass. The combined comparison shows its clearest repeatable gains in external lookup and fresh factory chains; modest, mixed and adverse observations are retained alongside the completed targeted follow-up.

The [verification evidence](2026-11-15-di6-optimization-evidence.json) records source inventories, functional checks, provenance and CI. The [measurement evidence](2026-11-15-di6-optimization-measurements.json) retains the local qualification and combined comparison records, samples, commands and limitations.

## 1. Scope and exact source identity

Baseline: **`39eca842285ebbe650456a8fe0009102d353aa99`**. Final code: **`874d76fbebd4402adc6f2bdd9bc4ee61d21bd883`**. The three published code commits are:

| Commit | Change |
|---|---|
| `c3e170e0d4bc9a0593132596f00a5da523e3b962` | AttributePlan boolean and singular selection without collecting all matches. |
| `b0e75490dc58b61b2217d991e51cf5f15dbe29ef` | First parameter-attribute selection without a full match list. |
| `874d76fbebd4402adc6f2bdd9bc4ee61d21bd883` | Remove redundant CycleGuard context/stack work. |

The source review covered **169/169 production PHP files**: **59** core/bootstrap files, **37** parameter/request/callable files and **73** attribute/object-lifecycle files. An independent union check found no overlap or omitted path; every inventory hash matched the immutable baseline. Related tests and the three proposed changes received focused review. This inventory records complete source reading; behavioral assurance comes from the specific checks below.

The final source fingerprint is `21e51bc476091f6445e91a0b64e70bff188f8bd29bf4069147ed5ac80601d1f4`: SHA-256 of sorted UTF-8 `src/...` path, NUL, raw bytes, NUL for all 169 PHP files. The final **src/tests** fingerprint is `7f518da0185ae92ab45478ff1b3dca437afdc474036aad27cff0d60f9cc95e70`, covering 408 files under that recorded scope. Full local suites ran before the last commit was created, so their checkout-HEAD metadata still says `b0e7549`; their captured before/after source/test bytes match the final committed state. Immutable final archives are used for the combined measurement and consumer checks.

The base requirement remains **PHP `^8.4`**. The native PHP 8.5 feature policy is unchanged. No availability-cache shortcut, native callback rebinding, owner-policy redesign or lifecycle-instance pooling is included.

## 2. Behavior preserved by each change

**AttributePlan.** [`has()` and `one()`](../../src/Attribute/Composition/AttributePlan.php) now retain a boolean, or the first matching usage plus a count, while traversing the complete sequence. They preserve usage identity, ordering, inherited capability matching, ambiguity detection after traversal and exception timing. Query aliases can still become available later; no negative cache or eager attribute-argument evaluation is introduced. `has()` is used by parameter resolution; `one()` is also a public metadata helper, so its isolated improvement should not be represented as an automatic application gain.

**ParameterTarget.** [`firstAttribute()`](../../src/Resolver/Target/ParameterTarget.php) remembers the earliest match during discovery and always completes discovery before native instantiation. If discovery encountered a declaration that was not yet available, it conditionally repeats selection in declaration order with loaded-only checks that cannot invoke another autoloader, accounting for declarations or query aliases made available during discovery. When all declarations were already available, it retains the remembered reflector without this second pass. Native instantiation remains fresh, preserving constructor and error effects. `hasAttribute()` is unchanged.

**CycleGuard.** [The guard](../../src/Internal/CycleGuard.php) captures the current execution context once per operation, directly updates the main-context array and removes duplicate shared-entry cycle checks and stack reads. Local cycle precedence, actual-owner coordination, main/Fiber separation and failure recovery remain covered by characterization tests. The clone probe establishes separation of the main-context array only.

The measured CycleGuard prototype has SHA-256 `0b853fba45656817b10adf8094f7e7eb5fb4ab6012dae135435a5ae94dff60cd`; the published file is `897cad0ad73eae675638dcb86fdb7daf3b047de2a62be04823fdbe566b82c3eb`. The only difference is the private `Fiber<mixed, mixed, mixed, mixed>|null` PHPDoc annotation required by static analysis. The retained proof establishes exact bytes after removing that annotation and identical **674 PHP tokens after excluding comments and whitespace**, hash `b0010544fd7d2bc6a3fc65115ff69bab8b13ddfec64fa40fae4aaa234094ed39`. The final combined benchmark uses the published annotated source.

## 3. Functional and integration validation

The baseline full suites passed on both local runtimes. The final suite adds **six cases and 47 assertions**:

| Check | Recorded result |
|---|---|
| PHP 8.4.23 baseline | 1,428 passed; 18 skipped; 23,837 assertions; random seed `1584151`. |
| PHP 8.5.8 baseline | 1,446 passed; 23,939 assertions; reverse order; CLI OPcache enabled, JIT disabled. |
| PHP 8.4.23 final | **1,434 passed; 18 skipped; 23,884 assertions**; random seed `1584155`. |
| PHP 8.5.8 final | **1,452 passed; 23,986 assertions**; random seed `1585155`; CLI OPcache enabled, JIT disabled. |
| Final PHPStan | `src tests --level=max`: no errors. |
| Final configured style check | 0 fixes among 403 checked files. |
| Final PHP 8.4 syntax | All 169 production files pass lint, with matching before/after file hashes. |
| Final Composer validation | `validate --strict`: pass. |
| Mutation/owner parity reproduction | 1,200 operations and 28,800 comparisons on **each** runtime. This is the parity fixture, not a mutation score. |

Characterization is explicit. AttributePlan baseline/candidate pass the same **31 public-helper checks** on both runtimes; a deliberately invalid first-only `one()` implementation fails three controls each. The focused repository selection tests pass **16 / 40** before and after the optimization on both runtimes. ParameterTarget baseline/candidate pass **15 / 63**; its two new discovery controls pass on baseline, while an illegal early-return mutant fails both. CycleGuard baseline/candidate pass **38 / 123**, plus the separate main-context array clone-separation probe; an ownership-first mutant fails both selected cycle-precedence controls. These deliberately broken candidates demonstrate sensitivity to the guarded boundaries. They do not constitute a full mutation campaign.

Intermediate records remain visible: the first core static run found the missing Fiber generic annotation; the final annotated run passes. Earlier launcher/style invocation problems are retained with their corrected runs. Their values are not relabeled as successful or attributed to a baseline production defect.

### Consumer and App

The runtime consumer and App use an archive of exact final DI source with frozen dependencies. The runtime consumer has an authoritative no-dev classmap and passes **eight checks** on each runtime. App passes **52 tests / 220 assertions** on each runtime: random seed `20261115` on PHP 8.4 and reverse order on PHP 8.5. Six execution records retain commands, exit codes and log hashes. Separate startup and shutdown records rehash the full 169-file installed DI trees and check the 168 class mappings, loaded declaration origins and six internal functions; post-run readback agrees. Both installed DI copies match the final source fingerprint.

App was pinned to `49025670f902c596af886ee4493e114c8b44303b`. Its diagnostic installation uses the temporary alias `dev-refactor/di6-definitions-callbacks as 5.0.99`. This validates runtime substitution with the frozen dependencies. It does not establish that a future published DI 6 package satisfies App's original Composer constraint. Original source/dependency inputs remain unchanged.

### Hosted CI

[Run 43657065521](https://github.com/componenta/di/actions/runs/43657065521) completed **successfully** for exact final code `874d76fbebd4402adc6f2bdd9bc4ee61d21bd883`, attempt 1. GitHub recorded completion at **2026-11-15 00:53:35 UTC**. All four required jobs passed:

| Job | Result |
|---|---|
| Linux PHP 8.4.27 | 1,434 passed; 18 expected skips; 23,884 assertions. Static analysis, style, Composer metadata and dependency audit pass. |
| Linux PHP 8.5.12 | 1,452 passed; 23,986 assertions. Static analysis and style pass. |
| Windows PHP 8.4.27 | 1,434 passed; 18 expected skips; 23,884 assertions. Static analysis and style pass. |
| Coverage and mutation, PHP 8.4.27 | **91.6% coverage** against the existing 80% requirement; **86.88% mutation score** against the existing 85% requirement. |

The mutation summary contains **3,625 tested, 58 timeout and 556 untested mutations**, **4,239 total**, with **four parallel processes**. Its reported duration is **2,268.41 seconds**. The coverage suite and mutation baseline each report 1,434 passing tests, 18 expected skips and 23,884 assertions. Commands were `composer test-coverage -- --coverage-clover coverage.xml` and `composer test-mutation -- --parallel`. Full log hashes, exact job IDs, commands and timestamps are retained in the verification evidence. The historical v4.0.10-versus-v5 benchmark job was skipped as configured for this push event; it is restricted to `workflow_dispatch`.

## 4. Isolated qualification measurements

Each selected change was qualified separately against baseline: five serial **ABBA** rounds per runtime, **40 measured processes per campaign**, common vendor and one binary per runtime. AttributePlan used three initial rounds and the recorded extension rule to add two unchanged rounds. No failed or slower result is silently substituted.

All percentages below are **100 × (candidate median / baseline median − 1)**, using ten process samples per variant/runtime. Negative means a shorter measured operation. Paired-round statistics separately compare the means of the two A and two B samples within each round; they are not interchangeable with the ratio of overall medians.

| Isolated operation | PHP 8.4.23 | PHP 8.5.8 |
|---|---:|---:|
| `AttributePlan::has`, six cases | −6.32% to −40.59% | −7.12% to −42.88% |
| `AttributePlan::one`, five normal cases | −9.30% to −50.42% | −9.87% to −52.66% |
| `ParameterTarget::firstAttribute`, three loaded cases | −18.34% to −23.75% | −23.87% to −31.53% |
| CycleGuard: resolved `has` | −30.31% | −28.78% |
| CycleGuard: reflection leaf `make` | −4.83% | −5.13% |
| CycleGuard: eight-factory fresh chain, main context | −19.68% | −18.61% |
| CycleGuard: eight-factory fresh chain, one Fiber | −9.32% | −9.31% |
| CycleGuard: first shared factory `get` on prepared containers | −25.64% | −20.02% |

Across the isolated cases and both local runtimes, the per-call reductions between the baseline and candidate medians are **26.45–167.48 ns for `has()`**, **41.35–116.42 ns for normal `one()`**, and **64.28–243.21 ns for `firstAttribute()`**. These absolute differences give the scale behind the helper percentages.

AttributePlan uses **100,000** normal calls per sample, with **500** calls for the separate exception diagnostic. ParameterTarget uses **500,000** calls with one already-loaded declaration; successful `firstAttribute()` includes native allocation. All 30 paired firstAttribute changes favor the candidate. Its unchanged `hasAttribute()` controls have mixed signs, providing a noise reference. The core qualification uses **scale 8192**, with per-case counts retained in its records; the unchanged resolved `get` is a control. Core PHP 8.5 main-depth and cold-get cases each contain one slower paired round. These measurements justify the selected local changes within their fixtures.

## 5. Combined final workloads

The immutable baseline and final trees were compared with the reviewed [worker](reproductions/OptimizationWorkloads.php) and [driver](reproductions/OptimizationComparison.py): **40 serial processes**, **480 case samples**, **five ABBA rounds per runtime**, no concurrent benchmark worker. Every process exits 0 and passes source-origin checks. There are **10,000 operations** per ordinary case and **100 fresh builds** per process, with warmup 1,000/10 respectively. CLI OPcache and JIT are disabled for timing.

The table preserves all **12** workloads and both runtimes. It uses the same ratio-of-medians definition as above. Exact samples, paired-round signs and full precision remain in the [measurement evidence](2026-11-15-di6-optimization-measurements.json).

| Workload | PHP 8.4.23, ns/op: baseline → final | Change | PHP 8.5.8, ns/op: baseline → final | Change |
|---|---:|---:|---:|---:|
| `get/shared` | 196.71 → 196.85 | +0.07% | 203.13 → 200.29 | -1.40% |
| `get/external` | 921.70 → 732.93 | -20.48% | 1001.34 → 782.00 | -21.90% |
| `make/plain` | 5598.46 → 5382.09 | -3.86% | 6156.14 → 5797.27 | -5.83% |
| `make/autowire` | 11873.87 → 11668.72 | -1.73% | 12638.09 → 12362.13 | -2.18% |
| `make/fresh-factory-chain-9` | 12146.02 → 9980.02 | -17.83% | 13522.59 → 10768.33 | -20.37% |
| `call/reused-closure` | 17396.87 → 17441.57 | +0.26% | 18789.61 → 18380.66 | -2.18% |
| `call/method-array` | 7396.75 → 7328.54 | -0.92% | 8211.39 → 7909.03 | -3.68% |
| `transform/one` | 17019.15 → 17433.32 | +2.43% | 18030.37 → 17963.82 | -0.37% |
| `transform/three` | 33823.94 → 32494.67 | -3.93% | 35286.56 → 34029.34 | -3.56% |
| `setup/method` | 22453.45 → 22278.79 | -0.78% | 23708.53 → 23652.21 | -0.24% |
| `fiber/make-autowire` | 12342.23 → 12318.42 | -0.19% | 13256.34 → 12926.37 | -2.49% |
| `build/default` | 117836.99 → 121555.54 | +3.16% | 128240.55 → 100960.63 | -21.27% |

External `get` improves by **20.48% / 21.90%**, and the explicit nine-factory fresh chain by **17.83% / 20.37%**, with all five paired rounds favoring final on both runtimes. Other workloads have smaller or mixed changes. The unchanged shared-get control is near flat. PHP 8.4 `transform/one` is **2.43% slower** and all five paired rounds are slower, which prompted the separate targeted follow-up below. The original 40-process campaign is retained unchanged.

`build/default` has **+3.16%** on PHP 8.4 and **−21.27%** on PHP 8.5, but each 100-build timed interval is only about **8–20 ms** and varies substantially. The PHP 8.5 number is not presented as a stable build speedup.

### Completed targeted follow-up

The separately named [targeted worker](reproductions/TargetedOptimizationWorkloads.php) and [targeted driver](reproductions/TargetedOptimizationComparison.py) follow a protocol recorded and independently reviewed before execution. The completed series has **56 serial workers**, **seven ABBA rounds per runtime**, **168 case samples** and **1,120 retained build batches**. Every worker exits 0 and passes the recorded result, runtime and source-origin checks. Each ordinary case uses **50,000 calls**; each build sample sums **20 batches of 100 builds** and divides the total elapsed time by 2,000. The unchanged `measure()` performs warmup, GC and verification separately outside each batch's timer, retaining the original 100-build accumulation boundary.

The timer, fixture declarations, factory/setup, verifier and original case table are byte-identical to the main worker. An untimed filter selects `get/shared` and `transform/one`, followed by the batched builds; preceding workload and cache history therefore differ from the original 12-case run. The original 40-process campaign remains a separate result and is not pooled with or replaced by this follow-up.

| Targeted case | PHP 8.4.23, ns/op: baseline → final | Change | Faster pairs | PHP 8.5.8, ns/op: baseline → final | Change | Faster pairs |
|---|---:|---:|---:|---:|---:|---:|
| `get/shared` | 197.21 → 198.22 | +0.5131% | 3/7 | 202.57 → 200.85 | -0.8503% | 6/7 |
| `transform/one` | 17464.32 → 17488.14 | +0.1363% | 3/7 | 18399.52 → 18184.26 | -1.1699% | 3/7 |
| `build/default` | 76842.41 → 77074.44 | +0.3020% | 4/7 | 79205.94 → 78771.13 | -0.5490% | 5/7 |

The **1,120 build batches** are nested observations within process measurements, not 1,120 independent trials. The unit of paired comparison remains **one ABBA round**.

“Faster pairs” counts rounds whose two candidate samples have a lower mean than the two baseline samples. Percentages use the ratio of candidate and baseline medians, each computed from 14 process samples per runtime. PHP 8.4 `transform/one` is **+0.1363%**, with final faster in **3/7** pairs; PHP 8.5 is **−1.1699%**, also **3/7**. In that PHP 8.5 case, the median of the seven paired-round changes is **+0.3981%**, despite the negative ratio of medians. The two aggregates therefore do not establish a stable gain from the negative headline alone. This series does **not reproduce a stable adverse transform direction**. It establishes a bounded result under the stated filtered workload; exact performance equivalence and application-level effects remain unproven. The unchanged shared-get control is retained without using it to normalize the other cases.

Batched build changes are **+0.3020%** on PHP 8.4 (**4/7** faster pairs) and **−0.5490%** on PHP 8.5 (**5/7**). These observations do not support promoting the original −21.27% PHP 8.5 build figure to a stable gain. All samples, batch times and signed paired changes remain available in the measurement evidence.

Setup, verification, metadata warmup and explicit GC are outside the timers. The loops include operation/closure dispatch and replacement of the previous result. Fresh default builds run in an already running warmed PHP process; the cold shared-get qualification uses prepared containers. A single uninterrupted Fiber is measured, without interleaving or concurrent throughput claims. Local numbers do not predict application latency.

## 6. Provenance and reproducibility

The combined driver retains exact stdout/stderr bytes and hashes, command/cwd, sample arithmetic, selected binary/vendor and runtime settings. Whole-source manifests and source-loader/runtime/worker/driver/Composer-metadata identities are compared before and after the campaign. Each worker verifies loaded production declarations and functions against the selected source. These per-process checks are origin checks; they are not separate hashes of all source files at every startup. Dependency hashes cover generated Composer metadata rather than the entire vendor content.

The [reproduction instructions](reproductions/OptimizationReproduction.md) provide the worktree commands, shared-vendor setup, isolated-file variants and five-round ABBA invocations for each worker. They also distinguish repository-root, `src`-directory and vendor-directory arguments.

Use a fresh output directory and one existing **DI checkout vendor** for both variants. The driver requires identical `benchmarks/autoload.php` in the two selected trees. For individual slices, create another baseline worktree and copy only the corresponding file from final with `git show <final>:<src/path> > <candidate>/<src/path>`. Preserve the baseline HEAD and record that single changed file's hash.

The [CycleGuard runner](reproductions/run-cycle-guard-benchmark.py), [worker](reproductions/cycle-guard-benchmark.php) and [source loader](reproductions/source-loader.php) must stay together. Its CLI takes repository roots plus a **vendor directory**, repeated `--php` arguments, `--rounds 5 --scale 8192`. It intentionally accepts only a 169-file baseline with exactly `src/Internal/CycleGuard.php` changed and records the `39eca842` baseline; use the isolated single-file candidate rather than the full final tree. Its preserved historical logs use text-mode decoding/re-encoding. Keep a new output directory and retain the earlier checkpoint and saved logs if a run fails.

[AttributePlanSelection.php](reproductions/AttributePlanSelection.php) takes `--source=<tree>/src --autoload=<vendor>/autoload.php --loader=<checkout>/benchmarks/autoload.php`, with defaults 100,000 normal / 500 exception calls. Its loop functions, cases and timer body match the measured worker; the untimed portable CLI and source observer differ, so whole-file identity with the historical worker is not claimed. [ParameterTargetHelpers.php](reproductions/ParameterTargetHelpers.php) takes a repository root, vendor/autoload.php and 500,000 iterations. Run either helper in five serial baseline/candidate/candidate/baseline rounds per runtime and retain every JSON/log pair. Their output schemas differ from the combined worker.

For the original six-file reproduction set, the four PHP files pass PHP 8.4/8.5 lint and both Python drivers pass compilation and CLI-help checks. Direct baseline/final worker smoke and both one-round small-count driver smokes pass, including source checks; those small-count timing values are excluded from performance conclusions. The targeted pair has separate evidence: PHP 8.4/8.5 worker lint, a successful Python AST parse and the successful actual 56-worker campaign. Its validation is not folded into the earlier six-file smoke record. The core loader assumes Composer's DI checkout layout for eager internal functions, so arbitrary consumer vendor layouts are outside its intended scope.

## 7. Deferred work

Passing the already-computed authoritative-provider boolean to the private parameter initialization helper remains a **source-reviewed, unmeasured, unapplied opportunity**. No user-code boundary separates the two computations, but it is outside these three changes and has no claimed speedup. Availability polling and owner-proof shortcuts remain unchanged because effectful autoloading and graph mutations require their current observation points. The code commits preserve the expected API and platform policy; this report records optimization evidence without creating a new release condition.

## 8. Publication scope

The three code commits use the existing `refactor/di6-definitions-callbacks` branch. Reports, evidence and reproduction scripts form a separate change under `docs/audit` only. The production sources, tests, Composer configuration, CI workflow and standard benchmark files remain byte-identical to the successfully tested code head `874d76f`. The hosted result above belongs to that exact code head, independently of the later documentation commit. All measurements and verification results retain their original source identities.

## 9. Follow-up recheck of the published changes

A further review on 15 November examined published head **`736b7f15f6eafe9428f889174d580bd542517888`**, whose production code is still **`874d76f`**. All **169 production files** were read again across disjoint core, parameter and attribute inventories (59/37/73). Related contracts and tests were inspected; this is not a claim that every test file was manually reviewed. The [follow-up evidence](2026-11-15-di6-optimization-recheck.json) retains the inventories, commands, logs, diagnostic programs, source identities and limitations.

**No new production defect or regression was confirmed.** No additional optimization acquired sufficient evidence to justify a code change. The existing unmeasured opportunity in §7 remains unimplemented. Production files and repository tests were unchanged during this pass.

| Fresh check | Result |
|---|---|
| PHP 8.4.23, randomized order, seed `202611151` | 1,434 passed, 18 expected PHP-8.5-only skips; 23,884 assertions. |
| PHP 8.5.8, reverse order, CLI OPcache enabled/JIT disabled | 1,452 passed; 23,986 assertions. |
| PHPStan max, `src tests`, `--debug` without result-cache reuse | No errors. |
| PHP CS Fixer, cache disabled | 0 fixes among 403 files. |
| PHP 8.4 production syntax | 169/169 files passed. |
| Strict Composer validation | Passed. |
| Mutation-history parity, each runtime | 1,200 operations and 28,800 warm/fresh comparisons passed. |

The before/after fingerprint of all 408 recorded `src/tests` files is unchanged in every broad command. The current and pre-optimization source trees used for diagnostics were also independently compared with their Git archives.

Ten additional standalone processes executed **322 diagnostic assertions**, separate from the Pest counts above:

| Area | Runs | Behavior checked |
|---|---|---|
| Core | 48 assertions × baseline/current × PHP 8.4/8.5 | Numeric string IDs, exact cycle paths, bounded factory calls, recovery after `Fiber::throw()` while the terminated Fiber is still strongly referenced, abandoned Fiber cleanup, null/false values and alias retargeting. |
| Parameters | 16 assertions × baseline/current × PHP 8.4/8.5 | An autoloader reveals a query alias without defining its requested name; reentrant discovery reveals an earlier attribute; two Fibers finish through one warm named plan in reverse order; variadic order, native default-object identity and closure-capture release. |
| Attributes and objects | 33 assertions × current source × PHP 8.4/8.5 | Discovery from destructors and property hooks, completed-handler ordering, fresh setup arguments on lazy/proxy retries, `ReflectionObject` lifetime and strict request-source conflicts. |

All ten processes passed. Core and parameter observations agree between pre-optimization baseline `39eca84` and current source. The attribute diagnostic initially retained its caught exception, which retained the failed attempt's argument through the trace. Releasing that caller-owned reference corrected the diagnostic; production code did not change. The evidence records this fixture correction.

The previously completed [hosted run `43657065521`](https://github.com/componenta/di/actions/runs/43657065521) was read back as successful for exact code commit `874d76f`. The only 13 changed paths between that commit and the reviewed head are under `docs/audit`; runtime, test and workflow bytes are identical. Its coverage and mutation results remain the earlier results in §3, not new measurements from this follow-up. A separate snapshot of the documentation-only head's still-running CI is retained without labeling it completed. App integration and the full mutation campaign were not rerun locally in this pass.

No new performance numbers or broader throughput claim are introduced. PHP remains **`^8.4`** and the release policy is unchanged. This follow-up adds only the evidence file and this section in both report languages; it creates no production patch, test-suite additions, release or tag.
