# DI 6: Full Codebase Re-audit and Test Evidence

[Русская версия](2026-11-15-di6-tdd-reaudit.ru.md)

**Date:** November 15, 2026, UTC. **Branch:** `refactor/di6-definitions-callbacks`.

This review started at [`114949c`](https://github.com/componenta/di/commit/114949ca0ccadcf68183f3aa9482d6a1ca46a05f). The final code and tests are at [`1e867ba`](https://github.com/componenta/di/commit/1e867ba11f91d6369dad23b1dea0f321a8b2c533). **This pass established no new reproducible implementation defects. It confirmed nine gaps in test assertions, strengthened nine existing test declarations, and added one new declaration.** A false positive in the expanded memory test, discovered during this work, was also corrected. All test changes were pushed as 11 separate commits to the existing branch.

All **169 production files are byte-for-byte identical to the starting snapshot**. Nine test files changed, with 162 lines added and 51 removed. These results require no new API, runtime behavior changes, or architectural redesign.

Documents from this review:

- [Complete file inventory and individual assessment of every test](2026-11-15-di6-tdd-scope.json): current hashes, contracts, actual assertions, considered faults, and mappings to dataset executions.
- [Execution evidence](2026-11-15-di6-tdd-evidence.json): meaningful mutations, commands, RED/GREEN results, the source files actually loaded, logs, hashes, excluded invalid experiments, and final checks.
- [Previous deep audit](2026-11-15-di6-deep-audit.ru.md): a separate investigation with its own starting snapshot, production fixes, and measurements. Its results are not presented as having been executed again in this review.

## 1. Completeness of the New Review

Review areas were reassigned among reviewers. Reading attestations from the previous audit were not reused: every current source file, test, setup, helper, and dataset definition was read afresh in full.

| Area | Files | Lines in the final snapshot | Reconciliation result |
| --- | ---: | ---: | --- |
| `src/**/*.php`, including files directly under `src` | 169 | 13,302 | All read; no production changes |
| `tests/**/*.php`, including bootstrap and the mutation harness | 229 | 28,728 | All read; initially 28,617 lines |
| Native `.php.fixture` files | 10 | 720 | All read; no changes needed |
| `benchmarks/*.php` | 6 | 941 | All read; no new performance figures claimed |
| Composer, PHPUnit, PHPStan, CS, CI/release, and the mutation guide | 7 | 695 | Execution requirements and measurement boundaries reviewed |

The AST inventory found **746** executable Pest declarations initially and **747** after the additions. A complete, unambiguous mapping to the actual execution list was also established: **1462 → 1467 runtime cases**. The increase consists of three variants of the new lifetime test and two additional variants of the callable cache test. Repeated experiments and runs on different PHP versions are not added to the count of unique tests.

All **747 declarations** have an individual record covering the stated contract, the result actually observed, a meaningful fault the test should detect, input variants, and limitations. Four overlapping review records were removed from the unique count. The final inventory has no unread files, unmapped declarations, or unmapped dataset cases.

Historical reproduction scripts under `docs/audit` and third-party packages under `vendor` are excluded from the DI source-file count. Dependencies were checked with Composer audit; this does not constitute a manual source audit of all third-party libraries.

## 2. What a TDD Check Means in This Report

For weak assertions found during the review, the process was to introduce a meaningful behavior fault in an isolated copy, confirm that the old test missed it, strengthen the observation, obtain a failure for the intended reason, and restore the original implementation to GREEN. The new lifetime test was also first run against a variant that retained the callable. No artificial `fail()` calls were inserted into tests merely to produce a nominal RED result.

**A meaningful RED was actually executed for 10 unique declarations.** The experiments covered **11 distinct deliberate production faults** and **6 native fixtures replaced with unrelated fatal errors**. The other **737 declarations** received an individual manual assessment of their contract, oracle, and counterexample, but a separate RED was not executed for each. A successful full suite and an aggregate mutation score do not turn this work into 747 individual RED/GREEN cycles or establish that every historical test was written first.

This distinction is preserved in the machine-readable records: `executed_meaningful_RED` and `reasoned_counterexample` are kept separate. References below to “every test reviewed” mean the complete individual review; they do not imply an execution history that does not exist.

The semantics of assertions in the installed Pest version were also checked. `toThrow(Class::class, 'text')` checks a compatible exception class and a message substring. `toThrow($exceptionObject)` checks a compatible class and an exact message, **but not exception object identity**. Overstated descriptions in the review were corrected. Where a test's contract does not require identity, this distinction was not treated as a new production defect.

## 3. Confirmed and Fixed Test Gaps

| ID | Gap in the previous assertion | What is now observed | Commit |
| --- | --- | --- | --- |
| T01 | Negative PHP 8.5 fixtures accepted any fatal error | The error reason matches the specific unsupported callback form | [`0a2cfbe`](https://github.com/componenta/di/commit/0a2cfbee3a19b823a686701c001e243fa6235b69) |
| T02 | Attribute declaration order already satisfied `after` | Unordered input is actually reordered according to the constraint | [`7d8bbf7`](https://github.com/componenta/di/commit/7d8bbf7632dedf4dd4b8e5ab751db69676d8231c) |
| T03 | The memory test varied only class-name casing in an array callable | Class-name, method-name, and static-string forms are checked independently | [`0678f8d`](https://github.com/componenta/di/commit/0678f8d8e9b93327fda1dfe840f49af39bb41b62), [`266e6ca`](https://github.com/componenta/di/commit/266e6ca24c09c101ebbabc48ccff58c05ad1b626) |
| T04 | Diagnostics could retain the callable while the exception remained alive | The captured object, rejected object, and method receiver are released while the exception remains alive | [`f2fb19e`](https://github.com/componenta/di/commit/f2fb19eee94a70354001dc65228f9cbe904cceed) |
| T05 | The attribute-isolation test passed when object handlers never executed | A counter requires one call, then two; the original isolation assertion is retained | [`12ae881`](https://github.com/componenta/di/commit/12ae88116ed37a3102380bd6b344fefe8c2ec5db) |
| T06 | A global guard for all external lookups appeared equivalent to protection for one service ID | An independent nested lookup succeeds, real recursion is rejected, and retry works | [`6ac9139`](https://github.com/componenta/di/commit/6ac9139ff82d132eb64569b8e832209a0573c572) |
| T07 | Late rejection of a protected delegator was sufficient, even after autoload | Rejection occurs before the loader runs or the class is declared | [`6261781`](https://github.com/componenta/di/commit/6261781cd32297cf949b8ecbcb06db285531201f) |
| T08 | Function and service-ID resolvers returned the same value; skipping the closure factory was also unobservable | Different fields require both resolvers; a separate counter requires closure materialization | [`7f3ab4a`](https://github.com/componenta/di/commit/7f3ab4afcae263f9344518b5101bafc58a29ad55), [`1e867ba`](https://github.com/componenta/di/commit/1e867ba11f91d6369dad23b1dea0f321a8b2c533) |
| T09 | Negative context-leakage assertions passed with an empty observation | Real public keys must be observed before checking that internal keys are absent | [`1d1a212`](https://github.com/componenta/di/commit/1d1a212bde4a7500f8d9c2f1f8110a4664b1eef0) |

### T01. A Native Error Must Have the Intended Cause

[Php85AttributeCallableContractTest.php](../../tests/Contract/Php85AttributeCallableContractTest.php) checked a nonzero exit code, `Fatal error`, and a compilation-phase sentinel. Replacing six fixtures with unrelated fatal errors still yielded **6 passed / 18 assertions**. The strengthened assertion against the same corrupted fixtures yields **6 failed / 24 assertions**. With the original fixtures, the complete file passes on PHP 8.5: **11 tests / 35 assertions**.

The checks now distinguish prohibited constant-expression operations, variable capture, a non-static closure, a dynamic function name, and an unsupported magic reference. This verifies the reliability of the platform-boundary test; it does not establish six defects in PHP or DI. The relevant native cases are intentionally skipped on PHP 8.4.

### T02, T05, T08, and T09. Establishing a Positive Observation Before a Negative Conclusion

In [AttributePlanSelectionContractTest.php](../../tests/Contract/AttributePlanSelectionContractTest.php), the fixture's initial order was deliberately changed from an already valid order to one requiring reordering. Removing the `after` relationships is now detected; the expected result is specified independently of the production algorithm.

In [AttributeRuntimeIsolationTest.php](../../tests/V5/AttributeRuntimeIsolationTest.php), obtaining two objects is insufficient: the handler must actually run on each. Removing dispatch previously produced GREEN; it now fails on the first-call counter. This preserves the purpose of the existing test, which detects reuse of a runtime attribute instance inside the handler.

In [DeferredExtensionSpecificationTest.php](../../tests/V5/DeferredExtensionSpecificationTest.php), two indistinguishable resolvers were replaced with two distinguishable routes through the existing DTO: an attribute value and a fallback. Skipping service-ID registration now causes a fallback resolution error. A separate mutation skips the closure attribute-definition factory. The resolver can still read the reflection attribute, so the test would pass without the new counter. The final test requires one factory call and both independent values.

In two declarations in [InternalContextLeakageTest.php](../../tests/V5/InternalContextLeakageTest.php), an empty key array made the prefix-checking loop empty. Both observers must now see `value` and `ServerRequestInterface::class`. Two different mutations—increasing the generic array resolver's priority and returning an empty public object context—pass the old assertions and are rejected by the strengthened ones.

For these six production faults, **48 isolated executions** were retained: four phases on PHP 8.4 and 8.5. This is a count of executions, not unique declarations. The complete modified files also pass on both versions.

### T04. Lifetime Verification While the Exception Remains Alive

The new declaration in [ExceptionPayloadContractTest.php](../../tests/Contract/ExceptionPayloadContractTest.php) retains `InvalidCallableException`, releases the input callable, and checks `WeakReference` after GC. Its three variants cover a closure capture, an invalid callable object, and the receiver in a method array.

Synthetically adding a private field that retained the original callable was missed by **the entire original PHP 8.5 suite: 1462 tests / 24,033 assertions**. The three new cases produce RED on both PHP versions; the original implementation passes the complete file: **14 tests / 61 assertions**.

The check temporarily sets `zend.exception_ignore_args=1` and restores the previous value in `finally`. The guarantee under test concerns DI's own payload. The native trace and the `previous` chain may own arguments and application data; this test does not promise to sanitize or release everything held by the complete `Throwable` object.

### T06 and T07. A Guard Must Reject Precisely the Action It Protects

[RuntimeMutationRecoveryContractTest.php](../../tests/Contract/RuntimeMutationRecoveryContractTest.php) now includes an independent nested external lookup with a distinct value and an observable call count. Replacing the guard scoped to a service ID with one global guard previously passed the full suite of the snapshot used in the experiment. It now violates the positive control. The original same-ID recursion scenario and successful recovery are preserved.

That old full-suite experiment was executed on the actual snapshot **`f2fb19e`**, not the initial `114949c`. Git blob bytes were checked for all 411 tracked source/test/configuration files in the reconstructed snapshot, with no mismatches. Review dates and baseline labels do not substitute for the provenance of the code actually executed.

In [RuntimeMutationContractTest.php](../../tests/Contract/RuntimeMutationContractTest.php), the loader declares a unique alias and counts calls. Weakening the early protected-ID check from `OR` to `AND` preserves the later exception but allows autoload to run first. The old test passed; the new test requires zero calls and the absence of the declared class. The loader is unregistered in `finally`.

Two broad runs of this experiment failed in a different memory test that had not yet been corrected. They were **excluded** from the evidence for T07. T07 instead uses separate targeted old-GREEN / new-RED / restored-GREEN runs on both PHP versions.

## 4. The Memory Check: Stronger Coverage Without a False RED

[CallableCaseCacheRetentionContractTest.php](../../tests/Contract/CallableCaseCacheRetentionContractTest.php) executes 8192 case variants for each callable form. It checks the exact sum of results as well as retained memory, so skipping execution cannot count as an optimization. Removing method-name normalization and string-callable normalization are two independent production mutations; the original test missed both.

The first expanded version exposed its own instability in the full suite. At seed `1794737895`, the correct implementation showed **2,228,024 bytes** against a limit of **2,097,152 bytes**. This reproduced with the standard bootstrap as well; GitHub CI also reported this failure cause. Raising the allowed limit on that evidence would have been unjustified.

An independent native-only control using `is_callable()` and `call_user_func_array()`, with no DI calls inside the loop, showed **1,245,088 bytes** on the first traversal of new spellings and **0 bytes** on the second. The hypothesis involving Composer's `missingClasses` was rejected: its size remained zero. The established source of measurement distortion was process-wide native lookup state being charged to the container cache.

The correction warms the complete set of spellings on a temporary container, releases it, runs GC, and measures a **new container**. Warming the container being measured could conceal a defect in its cache, so the containers are separate. The limit remains **2 MiB**. After this correction, both mutations still fail with **5,962,768 bytes** of growth, while the restored implementation passes the targeted and full runs. Release of the temporary container was verified by a separate WeakReference control.

This corrects the test's measurement method. The figures do not measure application memory savings or demonstrate a new DI speedup: production did not change in this review.

## 5. Architecture and Security

### State Ownership and Publishing Changes

The fresh review of [Container](../../src/Container.php), [CompositeResolver](../../src/Resolver/Entry/CompositeResolver.php), definitions, alias routing, and retirement preserves the established boundaries: validation, publishing a binding, invalidation, and releasing previous objects have distinct responsibilities. The review covered reentry through autoload/destructors, alias retargeting, current provider lookup, preservation of independent shared entries, and recovery after errors. T06 and T07 strengthen observation of these contracts; they do not demonstrate a vulnerability in the current implementation.

### Metadata and Data for the Current Invocation

[ParametersResolver](../../src/Resolver/Parameter/ParametersResolver.php), [AttributePlanBuilder](../../src/Attribute/Composition/AttributePlanBuilder.php), and [ObjectPipeline](../../src/Object/ObjectPipeline.php) were reviewed for separation between stable plans and current values. Closures and `ReflectionObject` must not have their runtime ownership retained by a shared named cache; mutable composition rules require fresh evaluation. Tests use concrete expected values, binding replacements, and WeakReference rather than relying solely on equality between cached and fresh paths.

Semantic arrays in [AttributeDefinition](../../src/Attribute/Composition/AttributeDefinition.php) and callable arrays in [PreparedCallable](../../src/PreparedCallable.php) snapshot PHP reference slots. The handler, rule, or receiver object itself retains its identity and current state. Deep cloning here would change the contract rather than provide a local fix.

### Request Mapping and Public Contexts

[MappedRequestParameterSourceGuard](../../src/Internal/Resolver/Parameter/Request/MappedRequestParameterSourceGuard.php) checks conflicting name/type keys before a custom resolver's `resolveParameter()`, a factory, or a callable body runs. Metadata preflight may call `supports()`; the guarantee does not prohibit every interaction with the resolver object before the guard. Tests include negative cases and side-effect counters. [InternalContextLeakageTest](../../tests/V5/InternalContextLeakageTest.php) now also rules out an empty observation.

Mapped HTTP transport, explicit programmatic parameters, and opt-in explicit `ClassDefinition` behavior have intentionally different rules. Application schema validation depends on the installed provider. PHP definitions, callbacks, attribute constructors, and autoloaders remain trusted executable application code; DI does not provide a sandbox for them. The presence of callback invocations in the library was not treated as evidence of attacker-controlled remote code execution.

### Late Loading and Completed Phases

Revision refresh and [CompletedAttributeGuard](../../src/Internal/CompletedAttributeGuard.php) were reviewed alongside cold/warm paths, cached/fresh paths, and late class discovery. An incompatible change to semantics that have already executed is rejected; completed handlers are not replayed. Eager/lazy/proxy paths use the shared pipeline, and failed deferred initialization receives separate state for a new attempt.

This does not provide transactional rollback of arbitrary application side effects or prove every possible Fiber/autoload schedule. The specific boundaries and counterexamples considered are recorded for each test in the inventory.

### Architectural Conclusion

This review established no new issue requiring an architectural decision. Every confirmed change improves tests within existing contracts. No separate API, cache, or lifecycle redesign plan is therefore proposed. Existing plans from other audits retain their own premises; the absence of a new issue here does not automatically close them.

## 6. Final Checks on the Published Code

The four main local checks below ran on **`1e867ba`**. Hashes of the entire source/test snapshot matched before and after each run. Composer checks ran on its immediate predecessor, `266e6ca`; the only subsequent change adds a positive observation in one test and does not alter metadata or dependencies.

| Check | Result |
| --- | --- |
| PHP 8.4.23, OPcache disabled, random seed `1794737895` | **1449 passed, 18 skipped, 23,961 assertions** |
| PHP 8.5.8, OPcache enabled, JIT buffer disabled, reverse order | **1467 passed, 24,069 assertions** |
| PHPStan `src tests`, max, `--debug`, 1G memory | **No errors** |
| PHP CS Fixer without cache | **404 files, no changes required** |
| `composer validate --strict` | Passed |
| `composer audit --format=json` | Empty advisories and abandoned-package results |
| GitHub Linux PHP 8.4.27 | **1449 passed, 18 skipped, 23,961 assertions**; checks and benchmark smoke passed |
| GitHub Linux PHP 8.5.12 | **1467 passed, 24,069 assertions**; checks and benchmark smoke passed |
| GitHub Windows PHP 8.4.27 | **1449 passed, 18 skipped, 23,961 assertions**; checks passed |

The 18 PHP 8.4 skips concern native PHP 8.5 features, not hidden regressions. The local static PHP build cannot load the optional PHPStan Turbo extension; that environment warning is preserved in the logs, and analysis completed successfully.

### Coverage and Mutation Gate

CI for the exact code/test HEAD: [run 43704400380](https://github.com/componenta/di/actions/runs/43704400380).

The run completed with **success** on the exact `1e867ba` snapshot. Fresh results:

| Metric | Result |
| --- | ---: |
| Coverage | **91.6%**; the 80% gate passed |
| Mutation score | **87.01%**; the 85% gate passed |
| Generated mutations | **4248** across 114 files |
| Pest classification | **3640 tested / 56 timeout / 552 untested** |
| Mutation execution | **1824.23 s**, four processes |

Timeout outcomes contribute to the tool's score, but do not prove that the intended assertion failed. **The 552 untested outcomes are classified neither as 552 confirmed bugs nor as necessarily harmless/equivalent mutations.** Their complete catalog is retained in the evidence as a remaining area of dynamic verification.

Compared with the saved earlier CI catalog at `4f0abe6`, which has identical production bytes, exactly four IDs disappeared from `untested`: both callable normalization faults, the global external lookup guard, and late protected-delegator rejection. No new `untested` IDs appeared. All four also have direct RED/GREEN evidence in this pass. This is a comparison with a historical catalog; the baseline mutation run was not freshly repeated.

Linux PHP 8.4, Linux PHP 8.5, Windows PHP 8.4, and the coverage/mutation job all succeeded. The separate `v4.0.10 vs v5 benchmark` job has the expected skipped status because that comparison is reserved for `workflow_dispatch`. Benchmark smoke checks in the ordinary Linux jobs passed.

The previous intermediate CI run on `6261781` failed in the memory oracle described above, including the mutation launcher's control suite. That failure was accounted for and corrected in commit `266e6ca`. Automatically cancelled CI runs from intermediate pushes are not presented as successful checks.

## 7. Limitations and Reproducibility

Complete manual reading, successful suites, coverage, and mutation testing serve different purposes. A green suite demonstrates consistency with the current assertions; positive counterexamples demonstrate that a specific fault is actually observable. None of these layers alone proves the absence of every possible bug.

The isolated proofs additionally checked the origins of loaded classes and eager functions. An optimized Composer classmap can point to a different checkout even when the working directory appears correct. The parameter/object experiments checked 159 existing dev-classmap mappings and six functions; the full lifetime experiment checked 168 loaded declarations and the same six functions. These are different metrics and must not be added together or substituted for one another.

The initial native-test isolation attempt, which symlinked the Pest package, loaded the original checkout; that bootstrap error was excluded from RED evidence. A stale classmap entry in the first parameter experiment was also corrected before valid control runs. A nonzero exit code alone was not accepted as evidence of the intended fault.

The evidence preserves mutations/patches, source/test hashes, commands, actual paths, and stdout. Absolute scratch paths record the provenance of the completed runs; reproduction on another machine requires substituting the local checkout paths and PHP binaries. The review records contain the current mapping of every declaration to its runtime cases and the results in both final JUnit reports.

No new no-dev consumer integration, `componenta/app` integration, or application latency/throughput measurements were run in this pass: production and dependencies were unchanged, and these test changes introduced no specific new risk to their behavior. Results from the previous audit remain available as historical evidence for its snapshots.

The PHP constraint remains **`^8.4`**. Native PHP 8.5/E1 retains a separate feature boundary; it does not become a gate blocking the entire DI6 release.
