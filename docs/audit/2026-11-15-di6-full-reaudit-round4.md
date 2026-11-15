# DI v6: fourth deep audit

Date: 15 November 2026. Repository: `componenta/di`. Branch: `refactor/di6-definitions-callbacks`.

Baseline: [`897f8b10ca6537e7b2f96d20b2e00ed52571ff6f`](https://github.com/componenta/di/commit/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f). Final code/test head: [`348330544d3d5b0bec7b158e0c2221927dc23643`](https://github.com/componenta/di/commit/348330544d3d5b0bec7b158e0c2221927dc23643). **Final full local checks and all CI gates, including coverage and complete mutation testing, passed for this head.** Earlier checks retain their actual commits; subsequent documentation commits preserve the final code/test bytes.

[Русская версия](2026-11-15-di6-full-reaudit-round4.ru.md) · [File review manifest](2026-11-15-di6-full-reaudit-round4-scope.json) · [Validation evidence](2026-11-15-di6-full-reaudit-round4-evidence.json) · [Lazy/Proxy design proposal](../plans/propertyless-lazy-lifecycle.md).

## 1. Outcome and priorities

**One production defect and one Windows test-fixture defect were fixed and pushed with concrete regression evidence. One further production defect was reproduced and requires an explicit lifecycle policy; its detailed design proposal is ready for that decision.** Nine independent implementation, test and CI units were published. The changes add **17 runtime cases and 70 assertions** relative to the freshly rerun baseline: the final identity strengthening adds one assertion to an existing case. The final complete-suite totals for `3483305` are recorded below. Existing test variants were retained.

| Finding | Priority and impact | Disposition |
|---|---|---|
| Native method classification retained duplicate metadata for case variants of one class/method | Medium: retained memory grows with rejected spelling history in a long-lived resolver | Fixed in `b0daea8`; actual original-source RED and fixed-source GREEN on both PHP versions |
| PHP 8.5 native callback fixtures rejected the correct Windows source paths | Medium: four supported Init/SetUp cases failed at fixture provenance validation | Fixed in `b6297a6`; actual Windows PHP 8.5.12 RED and GREEN, with callback expectations unchanged |
| Propertyless native Lazy/Proxy silently omits initialization | High: successful shared result can bypass constructor, setup, or a supplied product factory | Confirmed; **D1 open**. Recommend default-adapter rejection before allocation. No unsafe eager fallback applied |
| Weak test oracles at Cast, request protocols, callable retirement, alias rejection, magic closure and autoload exception identity boundaries | Coverage gaps: twelve exact catalogue mutations and one separate purpose-built replacement mutation passed the recorded old checks | Improved tests detect all thirteen witnesses; twelve are catalogue candidates and one is bespoke. These are not thirteen additional production bugs |
| Very long alias chains have order-sensitive cold construction cost | Conditional performance concern in a synthetic primitive workload | Recorded with limits; application evidence needed before a separate optimization |

The priorities are engineering judgments about the demonstrated behavior, not CVSS scores. No application-specific exploit was established. The successful verification gates do not resolve the open Lazy/Proxy defect or constitute DI 6 release approval.

## 2. Scope and method

Fresh source reading covers **169 of 169 production PHP files** and **all 247 files under `tests`**: 224 `*Test.php` files, 12 PHP support/entrypoint files, 10 fixtures and one document. Another **22 tooling/configuration files** were reviewed, for **438 unique files** overall. The merged manifest records individual paths, SHA-256 hashes, reviewer notes and overlapping reviews. The remaining test files were read completely, including fixtures, with checks of assertions, setup/teardown, state dependence and false-positive paths. The complete automated suite was run on the baseline, the earlier integrated head and final `3483305`.

The final `src` and `tests` snapshot contains **416 files**. Its fingerprint is `e633fcae11a2fdecabb4e38995821c05bc94ac166fb3f57e931d2d7ae7260926`: SHA-256 of Python `json.dumps({relative_path: SHA256(file_bytes)}, sort_keys=True).encode()` with the default JSON separators. The source-only fingerprint is `6237d2b929cb4caac36b2170c104105c6342840098db01a29177247059185dff`, unchanged from `a4cb684`; the later commits change CI or tests. Final local gate evidence records matching final fingerprints before and after execution. The source-origin checks in isolated reviews distinguish local Composer classmaps, eagerly loaded functions and selected source roots; green results from an unintended checkout are not accepted as evidence.

Source review was split across core registration/cache/alias/external-container behavior, callable/parameter/request behavior, and attribute/object/lazy lifecycle behavior. Root reviewed integration, exception/diagnostic contracts, internal metadata transport, tool entry points and final changed bytes. An independent second review examined the native metadata fix and the proposed lazy capability boundary.

Complete file reading does not establish fresh individual RED/GREEN for every existing test declaration, exhaustive Fiber interleavings, or a new reclassification of every historical mutation survivor. The [third audit](2026-11-15-di6-full-reaudit-round3.md) remains the historical per-test and survivor analysis. Here, twelve exact previously reasoned catalogue candidates acquired executed witnesses, and one additional bespoke mutation tests autoload exception identity. Their old-control scope is recorded separately: only the callable-retirement mutation was rerun against the entire old suite; the other eleven catalogue candidates and the bespoke candidate used the specified related complete test files.

## 3. Fixed: native method metadata retained case variants

### Failure and cause

`CallableResolver::resolveArray()` can inspect an existing non-static method after an exact service lookup fails. The private `isStaticMethod()` cache previously used the supplied class and method spelling as its key. PHP accepts case variants of those native names, so equivalent rejected inputs accumulated separate metadata entries for the same native operation.

The public regression repeatedly resolves **16,384** class-name variants or method-name variants through a resolver backed by an empty PSR container. It warms the native spelling lookups on a disposable resolver before measuring a fresh retained resolver, isolating process-wide lookup warmup from resolver-owned retention. All calls are still rejected; the bug concerns retained metadata, not successful unauthorized dispatch.

On the original source, both new memory cases fail on **PHP 8.4.23 and 8.5.8**, retaining **3,276,856 bytes each** against a deliberately generous 1 MiB workload budget. Root independently reproduced that RED before applying the fix: two failed and four passed in the case-cache file on each runtime.

### Correction and preserved contracts

The executable change is one line:

```php
$key = strtolower($class . '::' . $method);
```

Normalization is confined to the private native metadata key. Exact opaque service identifiers are looked up before this boundary and remain case-sensitive. Original class/method spelling remains available to diagnostics. Different class names, including parent/child declarations with different staticness, remain separate. The change introduces no cache of failed class availability and no arbitrary cache-capacity limit.

The regression preserves the original three successful-callable cases, adds the two rejected-case memory scenarios, and adds a control proving that `Opaque.Callable` and `opaque.callable` resolve to their original, distinct closures. Root independently checked the cache-fix bytes together with failure diagnostics and magic callable lifetime: **18 passed, 99 assertions** on each runtime. The original phase metadata records the pre-commit HEAD and measured bytes. The final full-suite and platform checks for `3483305` are listed below.

No application throughput claim is made.

Fix: [`b0daea8`](https://github.com/componenta/di/commit/b0daea8dcd804af774ebbd34ae561f3246cecdf9). [Exact phase evidence](evidence/2026-11-15-round4/callable-case-fix.json); root confirmation is in the validation evidence.

## 4. Open: successful Lazy/Proxy creation skips the lifecycle

### Demonstrated behavior

The default `ProxyFactory` returns the result of `ReflectionClass::newLazyGhost()` or `newLazyProxy()`. For native-eligible target classes with no backed instance properties, PHP returns an ordinary object. The initializer/backing factory is then never invoked, including after explicit `initializeLazyObject()`. Interfaces, abstract classes and unsupported internal classes retain their native errors; the ordinary-object return rule does not apply to them. This rule is documented by the PHP manuals for [ghosts](https://www.php.net/manual/en/reflectionclass.newlazyghost.php) and [proxies](https://www.php.net/manual/en/reflectionclass.newlazyproxy.php).

The [public observation script](reproductions/PropertylessLazyObservation.php) recorded **42 observations per runtime**, both in an isolated baseline checkout and independently on integrated code at `a4cb684`. The production bytes are identical at final head `3483305`; these are the recorded earlier executions, not newly claimed reruns at the final head. Cases include classes with no properties, static-only properties and virtual-only hooks, plus inherited private backed properties and backed hooks as positive controls. It uses production `ContainerFactory`/`Container`/`ProxyFactory`, verifies loaded paths and hashes, and fails closed for a mismatched source selection.

Confirmed effects include:

- `#[Lazy]` and `#[Proxy]` return and share an object with neither its constructor nor `#[SetUp]` executed.
- `#[NoConstructor]` correctly intends to omit the constructor, but the necessary setup is also lost.
- `#[Make('id'), Proxy(Target::class)]` can return a placeholder without calling the registered product factory.
- Inherited private backed state and backed property hooks retain genuine native laziness and invoke the callback once.

Twenty existing related test files pass on the baseline despite this behavior: PHP 8.4 has 132 passed / 4 skipped / 963 assertions; PHP 8.5 has 136 passed / 989 assertions. These are a coverage-gap control, not newly added regression tests. The reproduction records the defect without making broken initialization a desired test expectation.

### Why this requires a decision

Immediately calling the callback on an already ordinary object changes the public deferred-callback promise and exception timing. For proxies it also creates an unused placeholder and a factory product. The isolated rejected prototype records **one constructor and two destructors**, including destruction of the unconstructed placeholder before the returned product is released. No such prototype was placed in production.

The recommended policy is **A: fail in the default native adapter before allocating an unsupported target**. An alternative **B: deliberate eager support in high-level DI flows** requires explicit rules for direct versus high-level APIs, identity, callback timing, exception normalization, retry and custom implementations. The audit instruction requires a plan for that policy choice, so neither behavior is silently selected here.

The [complete proposal](../plans/propertyless-lazy-lifecycle.md) specifies the cause, affected callers, benefits, alternatives, ownership, migration, candidate change surface and public acceptance matrix. Option A includes inherited private storage, virtual/backed hooks, readonly/promoted properties, `stdClass`, native error precedence and unsupported internal ancestry. It preserves custom factory implementations and eager `ClassDefinition` behavior. It distinguishes DI payload ownership from PHP exception traces and avoids promising rollback of earlier autoload or extension effects.

**D1 remains open.** Once A is selected, the bounded implementation is to establish public rejection RED, add a shared preallocation check in the default adapter with contract documentation, and prove rejection/supported-laziness behavior across direct APIs and integration paths. The benefit is correct, diagnosable lifecycle behavior; no speedup is claimed. [Recorded native and DI observations](2026-11-15-propertyless-lazy-evidence.json).

## 5. Test contracts, fixture repair and CI

| Commit | Added runtime cases | Observable contract |
|---|---:|---|
| [`7a90569`](https://github.com/componenta/di/commit/7a9056912d7779d7399bff4afed3450e483fd342) | 4 | Cast input: native default, nullable fallback, attribute-default precedence, missing required input rejected before caster effects |
| [`6c3e58a`](https://github.com/componenta/di/commit/6c3e58a6fe95de9dc55b43cb94441aefef907d99) | 3 | Custom request protocols: partial overlap must not imply full mapping; readonly mapper needs no undeclared caster-provider property; current request values are used |
| [`2c52c28`](https://github.com/componenta/di/commit/2c52c285b806dbccffc7f43fba197aeeb01e4f6a) | 2 | Retired deferred callable destructors see all committed dependency changes; synchronous destructor failures use the mutation-retirement boundary |
| [`b0daea8`](https://github.com/componenta/di/commit/b0daea8dcd804af774ebbd34ae561f3246cecdf9) | 3 | Rejected native name variants retain bounded metadata; distinct opaque service IDs remain distinct |
| [`8002d2d`](https://github.com/componenta/di/commit/8002d2dff5bb1ac03bbe345290ba34ff40276f3d) | 3 | Empty default/runtime alias endpoints are rejected; warmed identity and decorator count survive rejection; subsequent valid retargeting recovers |
| [`a4cb684`](https://github.com/componenta/di/commit/a4cb684c67ff86224c639013cb9252fab84e0b2d) | 2 | Closures routed through `__call` for inaccessible declared methods preserve positional arguments; concrete internal-method closures still bind their real signature |
| [`8f47e9e`](https://github.com/componenta/di/commit/8f47e9e61bccfb32b1a82ae29292e5276a21f211) | 0 | Adds a Windows PHP 8.5 CI job so native callback fixtures are actually exercised on that platform |
| [`b6297a6`](https://github.com/componenta/di/commit/b6297a6ed9ed047c1c984b33afd857b434925d95) | 0 | Fixes canonical source-path checks in the two native callback fixtures; preserves strict source identity and all callback expectations |
| [`3483305`](https://github.com/componenta/di/commit/348330544d3d5b0bec7b158e0c2221927dc23643) | 0 | Strengthens the existing fallback-autoload test to assert the original exception object; adds one assertion |

### Windows PHP 8.5 fixture repair

Complete fixture reading found that `InitNative.php.fixture` and `SetUpNative.php.fixture` compared raw `realpath()` results with a source root plus forward-slash suffix. On Windows, the native backslash-separated path and the mixed-separator expected string differed even when Composer loaded the correct checkout. The existing Windows PHP 8.4 job skipped native PHP 8.5 syntax, leaving these four cached/uncached Init/SetUp cases unexercised on Windows.

The added Windows PHP 8.5 job established an actual platform RED before the fixture repair, followed by an actual platform GREEN:

| Phase / immutable head | Runtime | Complete job result | Platform evidence |
|---|---|---|---|
| Original source guards, `8f47e9e` | Windows PHP 8.5.12 | **4 failed / 1,539 passed / 2 skipped / 24,359 assertions** | [RED run 43759365970, job 132950546597](https://github.com/componenta/di/actions/runs/43759365970/job/132950546597) |
| Repaired source guards, `b6297a6` | Windows PHP 8.5.12 | **1,543 passed / 2 skipped / 24,363 assertions** | [GREEN run 43759684108, job 132951537102](https://github.com/componenta/di/actions/runs/43759684108/job/132951537102) |

The fix normalizes platform separators after `realpath()`, rejects an invalid selected root, and retains strict equality for both loaded class and eagerly loaded function origins. Ordinary callback-test expectations are unchanged. Twelve negative controls cover another checkout, a missing root and a file used as the root, for both fixtures and both cache modes; all reject before observation output. Local PHP 8.5 fixture tests also pass, but the platform claim rests on the two actual Windows jobs. This is a test-harness repair, with no production-code change. [Windows fixture proof](evidence/2026-11-15-round4/windows-php85-fixture-proof.json).

### Catalogue mutation witnesses

The twelve executed catalogue witnesses are:

| Area | IDs | OLD control |
|---|---|---|
| Cast fallback | `e4a8075381a95f16`, `991960b128e111b5` | Related attribute-composition file |
| Request protocols | `4c726edd6a83e89a`, `6f7306abc956a25b`, `d2cb52996814d1ec`, `53240def32ba1b47` | Two related request files |
| Deferred callable retirement | `3313a4fd6299c16e` | Entire old suite on both PHP versions |
| Alias endpoints | `6631a7603baf6ab4`, `d80142af1d372187` | Two related normalization/recovery files |
| Magic closure visibility | `17e601fbc47b94cc`, `ebb3f81c507f2b89`, `963ffa19a7476473` | Related magic-closure file |

The proof records distinguish original/old GREEN, exact-mutant/old GREEN, exact-mutant/improved RED, and restored/improved GREEN. Failure assertions and supported-call failures were inspected. An expected-exit mistake in the first retirement harness attempt was corrected and excluded as an extra proof. Message-only differences, infrastructure failures and timeouts are not counted as semantic RED. The [core proof](evidence/2026-11-15-round4/core-proof.json) and [parameter/request/magic diffs](evidence/2026-11-15-round4/mutations.json) retain commands, source/test hashes, concrete mutations and outcomes. Existing variants were not replaced merely to obtain a kill.

### Additional bespoke witness: original autoload exception identity

The existing fallback-autoload test in `AttributeAvailabilityRevisionContractTest.php` supplied an exception object to `toThrow()`, but its recorded old oracle accepted a replacement exception. The strengthened test catches the failure and directly asserts object identity with `toBe($failure)`, while retaining the type, message and two-autoload-attempt checks. Production behavior already preserves the original exception; this change closes a test-oracle gap.

A purpose-built replacement mutant, separate from the historical catalogue, was executed on both local PHP versions:

| Phase | Result on each PHP version |
|---|---|
| Original production / old test file | 13 passed / 94 assertions, GREEN |
| Replacement mutant / old test file | 13 passed / 94 assertions, GREEN |
| Replacement mutant / improved test file | 1 failed / 12 passed / 93 assertions, RED |
| Restored production / improved test file | 13 passed / 95 assertions, GREEN |

This is **one bespoke witness in addition to twelve catalogue witnesses**, not a thirteenth catalogue survivor or a new production defect. The OLD control is the related complete test file, not the entire old suite. [Autoload identity proof](evidence/2026-11-15-round4/autoload-identity-proof.json).

## 6. Architecture and security conclusions

The central definition registry with readonly binding snapshots (`DefinitionBinding`), revision-aware publication, snapshot composition, alias canonicalization and complete retirement remain coherent under the reviewed contracts. The new destructor tests specifically exercise a weakly covered ownership edge: the invalidated callable cache can be the last owner of a decorator returned by an external container. All stale entries must be detached before a destructor reenters resolution. The current implementation already does this; the tests now observe it directly.

Parameter execution continues to separate reusable declaration metadata from request/call-specific values. Review covered explicit versus omitted null/defaults, variadics, union acceptance, magic versus concrete closures, late attribute availability, promoted/private properties, staged writes, setup ordering and lazy retries. By-reference callable arguments remain outside the established DI parameter contract. The one-line metadata fix does not require a new abstraction or public API.

Security review covered request source authority and named-only DTO admission, collision handling, NUL-prefixed internal provenance and public-override filtering, exact service IDs, bootstrap/protected registrations, foreign-container exceptions, autoload reentry, Fiber ownership and detached diagnostics. No new exploitable request-source bypass was established. The installed dependency audit reports no vulnerability advisories or abandoned packages at the recorded time; CI repeats the advisory check on its installed dependency graph.

Two demonstrated defects still matter to security-sensitive applications: retained metadata can consume worker memory if callable input variation reaches this API, and the open lazy defect can skip validation or registration placed in a constructor. Neither observation proves an application attack path. Application callbacks, constructors, rules, factories and autoloaders are trusted PHP code. The component does not sandbox them. Native exception traces/previous exceptions follow PHP ownership rules, and arbitrary application side effects are not rolled back.

This is manual code/contract analysis with concrete reproductions, tests, static analysis and dependency auditing. No automated Codex Security scanner run is claimed.

The earlier questions about supported standalone `InstanceCreator`/`AttributeProcessor`/request-helper APIs and atomic versus partial by-reference configuration normalization remain in the [third-audit plans](2026-11-15-di6-full-reaudit-round3.md#architecture-decisions-and-implementation-plans). No downstream contract was inferred from the absence of internal callers. The existing conditional attribute-availability B2 proposal and feature-local PHP 8.5 E1 boundary are unchanged. The package still requires PHP `^8.4`; the new propertyless defect is independent of E1.

## 7. Performance evidence and limits

The material improvement demonstrated here is eliminating duplicate native classification retention across case variants. It is a retained-memory correction for the tested long-lived resolver workload. Measurements do not establish an application latency improvement or a capacity bound for all legitimate native declarations.

A bounded cache-parity replay on each local PHP executed **1,200 mutations and 28,800 comparisons** against fresh rebuilt configuration, with no mismatch. Those runs were recorded at `7a90569`, before the independent native classification key fix; core/cache source bytes remained unchanged through final code. This is evidence for the tested histories, not every possible mutation schedule.

All five existing benchmark entry points were smoke-run at `a4cb684` on both PHP versions: Runtime, GeneratedVsReflection, BuildPhases, ParameterPlan and AttributeAvailability. **Ten processes, 56 reported case rows, all exit 0**. Each source guard confirmed the selected declarations and six DI functions; all 169 source and six harness files remained unchanged during those runs. The production snapshot is identical at `3483305`; no new benchmark execution at that head is claimed. Selected iteration/round settings, outputs and provenance are in [benchmark evidence](evidence/2026-11-15-round4/benchmark-smoke.json). These are harness execution checks with recorded observations, not a baseline/final statistical comparison.

One cold-path concern remains documented. `AliasResolver` construction checks each inserted alias by walking the already inserted chain. For target-near-first ordering, a synthetic chain therefore incurs quadratic traversal. A local PHP 8.4.23 probe measured constructor plus one final canonical resolution, three observations per case:

| Alias count | Target-near-first median | Reverse-order median |
|---:|---:|---:|
| 100 | 1.19 ms | 0.12 ms |
| 1,000 | 108.39 ms | 1.21 ms |
| 3,000 | 851.77 ms | 3.61 ms |

The complete samples and probe are embedded in the core evidence. This is an internal primitive with unusually long chains, one runtime and one machine; it does not measure ordinary container build cost or application TTFB. If real configuration profiling shows material cost, a separate change should preserve cycle diagnostics, rejection order and runtime `set()` recovery while improving initial graph validation. No speculative graph rewrite or arbitrary alias limit is introduced by this audit.

## 8. Verification

### Recorded baseline and earlier completed gates

These results belong to the specified earlier commits. They are retained as evidence and must not be presented as the final complete gate for `3483305`.

| Recorded gate / head | Actual result |
|---|---|
| Fresh baseline PHP 8.4.23, `897f8b1` | 1,510 passed / 18 skipped / 24,189 assertions |
| Fresh baseline PHP 8.5.8, `897f8b1` | 1,527 passed / 1 skipped / 24,296 assertions |
| Earlier local PHP 8.4.23, `a4cb684`, random seed `2026111545`, CLI OPcache off | 1,527 passed / 18 skipped / 24,258 assertions |
| Earlier local PHP 8.5.8, `a4cb684`, reverse order, CLI OPcache on, JIT disabled | 1,544 passed / 1 skipped / 24,365 assertions |
| Earlier CI Linux PHP 8.4.27, `a4cb684` | 1,527 passed / 18 skipped / 24,258 assertions |
| Earlier CI Linux PHP 8.5.12, `a4cb684` | 1,544 passed / 1 skipped / 24,365 assertions |
| Earlier CI Windows PHP 8.4.27, `a4cb684` | 1,526 passed / 19 skipped / 24,256 assertions |
| Recorded PHPStan maximum-level gate at `a4cb684` | GREEN, no errors |
| Recorded full CS Fixer gate at `a4cb684` | GREEN, 0 of 411 files require changes |
| Recorded Composer metadata/dependency checks | `validate --strict` GREEN; no advisory or abandoned-package entries at the recorded time |
| Propertyless script, baseline and `a4cb684` | 42 observations per local runtime; supported controls and rejected fallback hazard reproduced |
| Benchmark smoke, `a4cb684` | 10/10 processes passed with source-origin checks; 56 reported case rows |

Earlier platform CI reference: [run 43757074043](https://github.com/componenta/di/actions/runs/43757074043), immutable code head `a4cb684`. The separate Windows PHP 8.5 RED at `8f47e9e` and GREEN at `b6297a6` are documented in section 5. The propertyless and benchmark runs apply to the identical production snapshot at `3483305`; neither is presented as a new run at that head.

### Final head `3483305`

| Final gate | Actual result |
|---|---|
| Complete local PHP 8.4.23 suite | **1,527 passed / 18 skipped / 24,259 assertions**; random seed `2026111552`, CLI OPcache off |
| Complete local PHP 8.5.8 suite | **1,544 passed / 1 skipped / 24,366 assertions**; reverse order, CLI OPcache on, JIT disabled |
| PHPStan maximum level, full configured scope | GREEN, no errors |
| CS Fixer, full configured scope | GREEN, 0 of 411 files require changes |
| Composer `validate --strict` and installed dependency audit | CI validation and advisory check pass; unchanged local manifest and installed graph retain the recorded clean audit |
| Final CI Linux PHP 8.4.27 | **1,527 passed / 18 skipped / 24,259 assertions** |
| Final CI Linux PHP 8.5.12 | **1,544 passed / 1 skipped / 24,366 assertions** |
| Final CI Windows PHP 8.4.27 | **1,526 passed / 19 skipped / 24,257 assertions** |
| Final CI Windows PHP 8.5.12 | **1,543 passed / 2 skipped / 24,364 assertions** |
| Source coverage | **92.3%** reported, minimum 80%; Clover has **4,453 / 4,823 covered statements** |
| Complete mutation accounting / MSI | **88.80%**, minimum 85%; **4,249 total: 3,716 tested, 57 timeout, 476 untested** |

Final CI: [run 43760317266](https://github.com/componenta/di/actions/runs/43760317266), immutable head `3483305`. All four platform jobs passed. JUnit comparison against the baseline confirms 17 additional cases, 70 additional assertions and no removed cases on each local runtime. Coverage, the complete mutation gate and coverage-artifact upload also passed; the entire run completed successfully.

Clover also reports 438 of 557 methods covered (**78.64%**). Its conditional metrics do not supply a branch-coverage measurement; the 92.3% figure must not be presented as branch coverage.

The mutation engine created 4,249 mutations across 114 mutatable source files under the configured `src` scope. Its MSI is `(3,716 + 57) / 4,249 × 100 = 88.80%`: timeouts contribute to the reported score. Counting only the engine's completed `tested` outcomes in the numerator gives **3,716 / all 4,249 = 87.46%**. This does not remove timeouts from the denominator and does not make every nonzero mutant-process result an individually inspected semantic RED. The 476 `untested` outcomes remain engine outcomes, not 476 confirmed production defects; their complete current diffs are preserved in the full log, without claiming a new individual reclassification. [Completed CI evidence](evidence/2026-11-15-round4/coverage-mutation-ci.json), [full Clover XML](evidence/2026-11-15-round4/coverage.xml), [original artifact ZIP](evidence/2026-11-15-round4/coverage-3483305.zip) and [complete compressed CI log](evidence/2026-11-15-round4/coverage-mutation.log.gz) are retained with SHA-256 checks. The ZIP digest matches GitHub artifact metadata, and its XML matches the preserved file byte for byte.

The recorded 18 local PHP 8.4 skips require native PHP 8.5 syntax. The PHP 8.5 skip covers a redundant contextual union declaration that PHP 8.5 rejects natively. Windows has one further skip because PDO SQLite is unavailable for its optional-parameter/variadic witness, giving two skips in the recorded Windows PHP 8.5 jobs. No new skip definition was added. Local PHPStan's optional turbo extension cannot load in the static CLI binary; the recorded analysis completes successfully using the supported fallback. All four final platform jobs also pass static analysis on their normal runtimes.

## 9. Completion boundary

All nine implementation/test/CI units are pushed to the requested branch. The production diff remains the single native cache-key correction. Other executable changes add or strengthen test assertions, repair two fixture source guards and extend the Windows CI matrix. Reports, detailed plans, review manifests and self-contained evidence are committed separately. No architecture-changing production fallback, API removal, release, tag or merge is part of this audit.

Final full local gates, all four platform CI jobs, source coverage and the complete mutation gate for `3483305` passed. D1 for unsupported native lazy targets remains open. The recommended choice is A, with the concrete implementation and acceptance plan already supplied. The audit leaves the confirmed lifecycle defect visible; successful verification does not resolve that policy decision.
