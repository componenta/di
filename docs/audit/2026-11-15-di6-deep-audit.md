# DI 6: deep source, architecture and test audit

[Русская версия](2026-11-15-di6-deep-audit.ru.md)

**Date:** November 15, 2026, UTC. **Branch:** `refactor/di6-definitions-callbacks`.

The audit found and fixed **two production defects** and **three weaknesses in test assertions**. The fixes were published as separate commits on the existing branch. Both production defects can be corrected within the existing state owners; neither requires an architectural change.

This pass started at [`8759427`](https://github.com/componenta/di/commit/87594273fbfb92167e0aa191d219dfe491ddcf7e). The final code and tests are at [`4f0abe6`](https://github.com/componenta/di/commit/4f0abe6314d31ce6936a5eb40dec9ed41b3ec133). The report commit does not change those bytes. Earlier audits remain evidence for their own snapshots; their coverage, mutation scores and performance measurements are not presented here as new results.

The complete [machine-readable evidence](2026-11-15-di6-deep-evidence.json) contains commands, results, source and log hashes, independent checks, measurements and limitations. The [review inventory](2026-11-15-di6-deep-scope.json) records coverage for each file.

## 1. Scope and method

The review covers **169 production PHP files, 13,302 lines**, and **229 PHP test and infrastructure files, 28,617 lines**, at the final HEAD. These are all `src/**/*.php` and `tests/**/*.php`, including files directly in those directories. Overlapping reviewer scopes are deduplicated in the unique file counts. Separate `.php.fixture` files, documentation, configuration and benchmarks are excluded from these totals.

The production review was divided into core and registration behavior; parameters, callbacks and request mapping; and attributes and object lifecycle. A separate pass then assessed every test file for independent expected results, positive controls, exception handling, global-state cleanup, reasons for skips, sensitivity to execution order and the ability to detect deliberately introduced defects. The root reviewer additionally covered exceptions, public interfaces, bootstrap and the mutation harness. Composer metadata, PHPUnit/PHPStan/CS configuration and CI/release workflows were read; architectural conclusions were checked against the current specifications.

A separate appendix covers full-file reviews of **10 native `.php.fixture` files, 720 lines**, and **six benchmark files, 941 lines**. These are supplemental areas and do not increase the primary 169/229 counts. No new confirmed defects were found there; benchmark methodology limits are recorded below and in the evidence.

Each production bug was first reproduced against the original implementation and then verified after correction. The three test gaps used a different control: the original assertions had to accept a deliberately broken implementation, and the strengthened assertions had to reject it for the intended reason. A passing test alone was not treated as sufficient evidence of test quality.

## 2. Corrected production defects

### DI6-AUDIT-01. The current lookup missed an external owner registered during `has()`

**Severity:** medium, routing correctness. **Commit:** [`9dfb4e1`](https://github.com/componenta/di/commit/9dfb4e1ee958299b6be6fd9f92610dba1ddf237d).

**Scenario.** The container has a local fallback value or would otherwise report the service missing. During `get()` or `has()`, the first external provider registers another external container from inside its own `has()` and returns `false`. The newly registered container owns the requested ID. The same mutation can occur while the first callback is suspended in a Fiber.

**Defect.** `ExternalContainerRegistry::findOwning()` traversed the initial PHP array with `foreach`. Appending a provider changed the registry, but not the traversal already in progress. Consequently, the first `get()` could return the local value and the next one the external value; the first `has()` could report absence even though an owner had already been registered. Routing after the user callback did not reflect the completed registration.

**Fix.** After checking a complete batch without finding an owner, the registry checks for new entries and visits only the appended suffix. Previously executed `has()` calls are not repeated. The first owner found retains precedence; an unvisited suffix is not executed after an owner is found. The registry remains append-only, with no new public API, index or persistent registry field.

**Verification.** The new [ExternalLookupRegistrationContractTest](../../tests/Contract/ExternalLookupRegistrationContractTest.php) covers cold and warm `get`/`has`, two waves of registration, first-owner precedence, a newly added provider that fails and later recovers, and both Fiber paths. Before the fix: **7 failures and 1 passing case**. After the fix, the verification run with neighboring contracts reports **30 tests / 154 assertions** on each PHP runtime; the broader isolated selection reports **38 / 182**.

Traversal remains linear when registry membership does not change. A finite chain of newly appended providers is handled without replaying completed callbacks. Termination is not promised for arbitrary user code that continually adds new work; an arbitrary retry cap would change the contract. Ordinary `ContainerFactory` construction keeps `null` instead of an empty external registry, so local `get()`/`has()` without external containers never call this method.

### DI6-AUDIT-02. `PreparedCallable` retained mutable references to its owner and method

**Severity:** medium, callback correctness and lifetime. **Commit:** [`cd772f1`](https://github.com/componenta/di/commit/cd772f1c20fc35366cee4b25a2e895a8aa0d4345).

**Scenario.** An adapter receives a valid callable array, `[&$owner, &$method]`. The caller changes the original variables after creating the adapter. Declaring the adapter property `readonly` does not detach references inside the array.

**Defect.** The adapter could invoke a different object or method, or fail after the external method variable was changed to a nonexistent method. This contradicted its promise to preserve the original callable for already prepared arguments and invoker decorators.

**Fix.** [PreparedCallable](../../src/PreparedCallable.php) copies the callable-array entries by value, preserving keys, order and object identity. External references to the array slots are detached; the object itself is not cloned. Later changes to that object's business state remain visible when it is invoked. Closures, strings and invokable objects retain their callable form.

**Verification.** Before the fix, the new routing check produces **1 failure / 3 passing cases**. After the fix, the related callback/cache contract selection reports **33 tests / 598 assertions** on PHP 8.4 and 8.5. Checks also cover ordinary native defaults, mutable object state, invocation through the container and directly, and preservation of the selected route after the caller makes its method variable invalid.

This correction concerns references in the callable description. It does not broaden support for by-reference parameters of the invoked method; the existing DI contract already excludes that ABI.

## 3. Stronger tests with demonstrated fault detection

All three findings below concern test quality: the current production implementation met the contract, but the original assertions failed to detect a specific regression.

| Gap | Why the original test missed the defect | What is asserted now | Commit |
| --- | --- | --- | --- |
| Strategy deduplication when importing child composites | The first strategy accepted a supported definition, ending dispatch before a duplicate occurrence could be reached | An unsupported definition forces classification across the complete list; a shared strategy is classified exactly once and validation is not called. The successful case is retained | [`4d9bbe8`](https://github.com/componenta/di/commit/4d9bbe83cf298a725bab46f7dfa35dcfb6da1ba1) |
| Source guard before a custom resolver | Only the eventual exception was checked; that exception could still be thrown after a user side effect | Zero `resolveParameter()` calls on a conflict; a subsequent permitted direct `make` proves that the same resolver is active | [`7ab5ac4`](https://github.com/componenta/di/commit/7ab5ac47b03af587e0a35db64d73186225f916ae) |
| Complete inherited attribute inventory | The expected instance count was derived from observed events; cached and uncached paths could both omit the same private parent method | An independent complete multiset of `(declaring class, member)` for class, property, method, trait and alias usages; instance counts derive from the expected inventory | [`4f0abe6`](https://github.com/componenta/di/commit/4f0abe6314d31ce6936a5eb40dec9ed41b3ec133) |

The deliberate-defect controls establish the following:

- With deduplication removed, the original file reports **11 passed / 41 assertions**. The strengthened version reports **2 failures**, because the classification count is 2 instead of 1. The correct implementation reports **11 passed / 48 assertions** on both PHP runtimes.
- With DTO creation inserted before the unchanged source-conflict guard, the original test remains **1 passed / 1 assertion**. The strengthened test fails its `calls === 0` check with an actual count of 1. The correct `RequestMappingTest.php` reports **17 passed / 38 assertions** on both runtimes. Calls to `supports()` are deliberately permitted: they classify metadata, whereas this guarantee concerns resolver execution.
- Deliberately omitting a private ancestor method when the descendant declares a private method of the same name survived **the full unstrengthened PHP 8.4 suite at the `cd772f1` test baseline: 1444 passed, 18 skipped**. The new inventory assertion fails specifically because `ParentFixture::sameMethod` is missing, on both runtimes. The correct implementation reports **17 passed + 2 skips / 353 assertions** on PHP 8.4 and **19 passed / 361** on PHP 8.5. Sorting the multiset avoids making unspecified Reflection enumeration order part of the contract.

The isolated checks record the actual paths of loaded classes. Preliminary attribute-mutant attempts in which Composer's optimized classmap incorrectly selected the main checkout are excluded from the evidence. The final RED/GREEN results use a verified isolated source file.

No further confirmed regression-detection gaps, silently swallowed failures or test-order dependencies were found in the reviewed tests. Several locally weak assertions have independent coverage in neighboring contracts; they are documented in the evidence without an unsupported rewrite of the suite.

## 4. Architectural conclusion

**Decision: retain the current architecture.** This pass did not establish a defect that requires changing state-ownership boundaries or the public contract.

| Area | What was checked and why the current boundary matters |
| --- | --- |
| Definitions and strategies | `CompositeResolver` owns bindings; strategies classify, validate and execute descriptors without a separate mutable registry. Importing child composites creates an independent snapshot. Deduplicating strategy roles does not remove separate fallback occurrences |
| Mutation authority | An attached root rejects direct bypasses of container publication. A prepared binding is tied to its owner and identity authority. The attached root's public operations cannot bypass the container's publication and invalidation boundary |
| Publication and retirement | Registration changes and invalidation happen before obsolete roots are released. Revision checks prevent a completed old resolution from republishing a replaced result into the cache. A destructor failure has a separate committed outcome |
| Metadata and runtime values | Memoized plans retain structure and order; current callback arguments, requests, object identity and mutable user-rule state do not become permanently cached results. The nonmemoized path is checked as an independent execution |
| Callback binding | `Transform` and callback `SetUp` use the shared parameter pipeline. The semantic first argument is protected from resolver overrides, suffix parameters receive their own context, and `PreparedCallable` prevents a second DI pass |
| Object lifecycle | Property values are staged through the chain and committed by the existing owner. Native assignment controls setter-hook semantics. Lazy/proxy retry, constructor policy, private inheritance and cleanup are checked separately |
| Late metadata | Completed guards track work already performed: newly discovered incompatible semantics fail instead of replaying handlers or callbacks. A single snapshot at operation entry cannot transparently replace these checks |
| Union autowiring | A simple union selects the sole available candidate and detects ambiguity before construction. Sequential `has()` calls may have side effects; globally atomic availability is not promised |

Keeping these boundaries has concrete benefits: the fixes introduce no second mutation owner, duplicate parameter-resolution loop, cache of user results or implicit rollback of side effects. The regressions check call ordering in the reproduced and neighboring scenarios; the patch intentionally introduces no other ordering changes.

Other directions require a separate justification:

1. **Reducing polling for missing attributes.** The existing [availability plan](../plans/attribute-availability-performance.md) retains conditional option B2: explicit refresh and pure generation observation within the existing owners. Adoption requires a real application profile, a chosen discovery/failure-timing contract and evidence for each removed checkpoint. This audit does not justify introducing it as a transparent optimization. Renaming revision reads alone demonstrates no benefit.
2. **Request-to-DTO callbacks and header-key normalization changes.** These introduce new input/output and provenance semantics. The defects found here do not establish a need for that API; current mapping and casing rules remain in place.
3. **Broader native callback ABI or trait-relative PHP forms.** These need their own contract and platform evidence. Detaching referenced callable-array slots does not establish such a contract.

The existing detailed plans remain the source for those decisions. No new architectural overhaul plan was created for an unconfirmed problem.

## 5. Security and guarantee boundaries

No new confirmed exploitation path from untrusted input was established in this pass. This conclusion concerns the reviewed source, contract tests and reproductions; it does not promise the absence of every vulnerability. A separate formal security-plugin scan and attacks against a deployed application were not performed.

**Trust boundary.** HTTP bodies, query parameters, headers, cookies and uploaded files are input data. `Header` selects a source; it does not authenticate that source. A DTO type comes from a PHP declaration, not a request string. Configuration, definitions, attributes, callbacks, resolvers, external providers and autoloaders are executable application code.

| Boundary checked | Result |
| --- | --- |
| Request-to-DTO mapping | Numeric keys are rejected before binding; conflicts between mapped name/type keys and declared sources are checked before factory dispatch, including supplied `null`, late loading and classless setup |
| Field merging and renaming | Conflicting values from selected sources are rejected by default; a rename collision cannot silently overwrite another target. An explicitly selected `FirstWins` policy retains its documented meaning |
| Internal metadata | Public extensions receive a filtered parameter bag; setup overrides cannot replace internal provenance. This protects the supported API's integrity; it does not sandbox PHP code that can use Reflection |
| Protected core services | Requested and canonical IDs, aliases, delegators and mutations after external `has()` are checked. Contracts verify that external `get()` is not called after an alias is redirected to a protected service |
| Definition admission | Only `DefinitionInterface` triggers descriptor registration. Callable-shaped raw values remain literal shared entries; an attached root cannot bypass publication authority |
| Diagnostics and retention | DI's diagnostic fields use names, types and positions rather than request/service objects. Once the exception is released, the container does not retain the request or closure. The complete `Throwable` is not universally sanitized for secrets |
| Dependencies | Fresh `composer audit` runs for the main tree and the new production consumer installation report no advisories or abandoned packages, exit 0 |

A complete `Throwable` can contain the original `previous` message, caller-supplied IDs/keys and a native stack trace. The application owns decisions about exposing it to HTTP clients and about logging/redaction. Scalar diagnostic fields alone do not make the entire exception chain safe to disclose.

`MutationRetirementException` means that the new registration **has already been published**. Its guarantee covers releasing directly retained obsolete roots and synchronously delivered failures. It does not promise rollback of every side effect, traversal of arbitrary object graphs or forced garbage collection. Callbacks run with the PHP process's authority. The confirmed bugs in this audit are classified as correctness, lifetime and ownership defects; remote exploitation is not claimed.

## 6. Performance: the measured cost of the fixes

No general throughput improvement is claimed. Measurements compare the original `8759427` implementation with the corresponding corrected source using the same dependencies, binaries and settings. These are local CLI microbenchmarks, with `-n` and CLI OPcache disabled. All samples, ranges and source-origin checks are retained.

### External lookup

Each runtime used nine rounds with randomized baseline/candidate order, 30,000 operations and 3,000 warmup operations per case. Cases cover the direct registry and public `get()`, with no owner or an owner in the last position among 1/8/64 providers. The table shows median **ns/public get**, baseline → corrected traversal:

| Case | PHP 8.4.23 | PHP 8.5.8 |
| --- | ---: | ---: |
| No owner, 1 provider | 740.3 → 799.5 | 791.4 → 927.7 |
| Last owner, 1 provider | 710.2 → 757.2 | 783.6 → 802.6 |
| No owner, 8 providers | 993.9 → 1040.1 | 1050.5 → 1169.4 |
| Last owner, 8 providers | 952.5 → 1010.8 | 1031.2 → 1065.5 |
| No owner, 64 providers | 2772.1 → 2936.0 | 3031.1 → 3119.3 |
| Last owner, 64 providers | 2779.4 → 2838.0 | 2984.6 → 2931.1 |

Positive differences are approximately **19–164 ns**. The single-provider miss on PHP 8.5 above costs **17.2% more**. A negative difference in one case does not establish a speedup; ranges and scheduling noise matter. Observing newly registered providers is necessary for correct results. The ordinary path without external containers does not enter the changed method; its control measurements and source evidence are recorded in the JSON.

The existing `GeneratedVsReflectionBench.php` reports two one-off container build times affected by first-autoload order. The [earlier audit](2026-11-12-container-audit.md) already documents this limitation; those figures are not a fair cold-start comparison and are not used for this report's performance conclusions. Its warmed make loops are measured separately.

### `PreparedCallable`

Each PHP runtime used seven rounds with randomized baseline/current order, three modes and 200,000 operations per process. The construction mode measures **adapter creation plus one invocation**, not constructor time alone.

| Mode | PHP 8.4.23, ns/op | PHP 8.5.8, ns/op |
| --- | ---: | ---: |
| Callable array: creation + invocation | 307.6 → 509.5 | 313.8 → 574.6 |
| Closure: creation + invocation | 260.3 → 283.2 | 258.0 → 286.8 |
| Repeated invocation of a prepared array adapter | 167.8 → 160.1 | 168.5 → 174.8 |

Snapshotting the array adds approximately **0.20–0.26 μs to the construction-and-invocation case**, or **65.6% / 83.1%** in this small synthetic workload. A closure is not copied, but it passes through the new `is_array` check; the measured difference is approximately **23–29 ns**. The repeated-invocation algorithm for an existing adapter is unchanged, and the small differences in opposite directions do not support a stable performance conclusion.

These values are not converted into HTTP-request or application latency: no such workload was measured here. Side effects and live native object state are preserved; a cheaper invocation of an externally redirected callable would have different semantics.

## 7. Final verification

The final suite and static checks below ran at `4f0abe6`. Mutation-history parity was recorded at `cd772f1`, whose production bytes are identical to the final tree: the following three commits change tests only. The supplementary attribute-order and object-lifecycle diagnostics retain their `8759427` snapshot in the evidence. All 86 production files in that attribute/lifecycle review inventory are unchanged, but those diagnostic runs are not presented as fresh reruns against the entire final production tree.

| Check | Result |
| --- | --- |
| PHP 8.4.23, random order, seed `2026111506`, OPcache disabled | **1444 passed, 18 expected skips, 23,931 assertions** |
| PHP 8.5.8, reverse order, OPcache enabled, JIT buffer disabled | **1462 passed, 24,033 assertions** |
| PHPStan `src tests`, max, `--debug`, 1G memory | **No errors**, without reusing the result cache |
| PHP CS Fixer, cache disabled | **404 files**, no changes required |
| Native production syntax lint on each PHP runtime | **169/169**, no errors |
| Composer metadata | `validate --strict` passed |
| New no-dev consumer, PHP 8.4/8.5 | Installation, platform requirements and all smoke scenarios passed |
| Mutation-history parity on each PHP runtime, `cd772f1` production snapshot | **1,200 operations / 28,800 warm/fresh comparisons**, no mismatches |
| Independent attribute-order oracle on each PHP runtime, `8759427` snapshot | **300 checks**, 60 graphs of 5 attributes, including 35 cyclic graphs; the oracle enumerates permutations instead of repeating the production algorithm |
| Object lifecycle matrix on each PHP runtime, `8759427` snapshot | **272 checks**, 12 combinations of eager/lazy/proxy/NoConstructor and cache/rules, including retry, private readonly inheritance, identity and release |

The 18 PHP 8.4 skips concern PHP 8.5 syntax/features. The combined **57,600 parity comparisons** and **1,144 additional diagnostic checks** are not counted as extra Pest tests or a mutation score.

The first local PHPStan run exhausted the default 128 MB memory limit. It was repeated with an explicit 1G and completed successfully; the initial outcome is retained in the evidence. The optional Turbo extension is unavailable in the static PHP binary, which does not prevent successful analysis.

### Installed-package verification

A new production consumer was created using `composer install --no-dev --no-scripts`, current published companion packages and a separate DI snapshot. **23 production packages** were installed. DI test helpers are not loaded. The authoritative classmap was checked for **168 declarations**, and all **169 production files** match the final source. After the smoke run, **152 loaded DI declarations and 6 functions** originate from the installed package.

The smoke checks cover config/environment identity, factory ABI, shared `get` and fresh `make`, raw `set` with a retained fresh definition, `ClassDefinition`, direct strategies, custom definition snapshots/authority, callbacks and `Transform` suffix isolation, union selection/ambiguity, committed retirement, and both corrected bugs. Class origins are captured at bootstrap and shutdown.

The retained `componenta/app` snapshot from the preceding audit, labeled as commit `49025670f902c596af886ee4493e114c8b44303b`, was checked in a new isolated copy. All 66 source/test files were compared with that frozen snapshot; its declared commit provenance was not independently reverified through Git in this pass. Each PHP runtime passes **52 tests / 220 assertions**. All 169 installed DI source files match the final source; 168 class mappings were verified, and 137 named DI types plus six functions loaded from the new installation during the tests.

The diagnostic Composer alias `dev-refactor/di6-definitions-callbacks as 5.0.99` was used because the frozen App requires DI `^5`. This verifies execution of that App snapshot with the new code; it **does not establish** that `6.0.0` or the raw development branch satisfies `^5`, and it does not change App's dependency policy. A real migration requires a separate consumer constraint update.

Source checks also caught and corrected an installation problem: with the same development version and `reference: none`, an ordinary `composer update` changed the lock URL but retained the previously installed DI files. The package was explicitly remirrored through Composer and byte-verified before the final tests. A temporary `reference: config` was then removed; the final manifest and lock differ from the frozen snapshot only in the DI path URL and its related content hash. An initially over-strict origin check for nine anonymous fixture types was corrected in the diagnostic bootstrap; production App and DI were unchanged.

## 8. PHP 8.5 platform: retained E1 limitation

The independent `tests/php85-platform.php` runner was executed at `cd772f1`; the runner, fixture and production files are unchanged afterward. It again reports **2/10 passing native semantic cases**, exit 1, on the local **8.5.8** binary. Some trait-relative `self`/`parent` and private callable references in defaults/attributes select the wrong scope; the fixture reproduces this without DI. The checked public named reference and inline static closure pass.

This limits particular native forms on the tested binary. It is not a new DI defect or a statement about every later PHP patch release. This pass does not claim a new stable-release provenance check for that binary. Historical RC results do not replace the fresh result.

**The package requirement remains PHP `^8.4`. E1 does not block the entire DI 6 release.** The precise feature-level contract and supported forms are documented in the [callback plan](../plans/php85-callbacks.md) and [migration guide](../migrations/di6-callbacks.md). There is no basis here to raise the overall platform floor or rebind callback scope to work around the limitation.

## 9. GitHub CI

The exact final-code run is [43697207118](https://github.com/componenta/di/actions/runs/43697207118), HEAD `4f0abe6314d31ce6936a5eb40dec9ed41b3ec133`. It completed **successfully**; GitHub's final update was **2026-11-15 09:43:39 UTC**. All four required jobs passed.

| Job | Result |
| --- | --- |
| Linux PHP 8.4.27 | **1444 passed, 18 skips, 23,931 assertions**; metadata, dependency audit, style, PHPStan and benchmark harness smoke checks pass |
| Windows PHP 8.4.27 | **1444 passed, 18 skips, 23,931 assertions**; metadata, style and PHPStan pass |
| Linux PHP 8.5.12 | **1462 passed, 24,033 assertions**; metadata, style, PHPStan and benchmark harness smoke checks pass |
| Coverage / mutation, PHP 8.4.27 | **91.6% coverage** against an 80% threshold; **86.91% mutation score** against an 85% threshold |

The mutation campaign reports **3636 tested, 56 timeout and 556 untested**, or **4248 mutations** in total. The tool's categories are preserved: a timeout is not presented as a completed assertion check. Its reported mutation-run duration is **2365.28 seconds**; timestamps for the entire step span approximately **2485.54 seconds**, including preparation. Both coverage and mutation controls report 1444 passed / 18 skips / 23,931 assertions.

The commands were `composer test-coverage -- --coverage-clover coverage.xml` and `composer test-mutation -- --parallel`; the coverage artifact uploaded successfully. The historical `v4.0.10 vs v5 benchmark` job was skipped under its `workflow_dispatch` condition, not because of a failure. Hashes of full decoded logs, selected result lines, job IDs and run status are preserved in the evidence.

Publishing this report changes documentation and evidence only. The verified code remains exactly `4f0abe6`; the automatic run triggered by the documentation push is a separate run and is not represented as already complete here.

## 10. Rejected hypotheses and practical limits

The following observations were not turned into fixes that would change the existing contract:

- An earlier union arm becoming available inside a later `has()`/autoload does not establish an atomic-snapshot guarantee for all candidates. Repeating every callback would change observable behavior and could fail to terminate.
- Retention of a callable target through a WeakMap was not confirmed as a permanent leak: the 100 diagnostic targets are released after GC, and the existing lifetime tests pass.
- `Init` on a promoted property does not declare a new constructor-parameter source. Adding it to request-source policy would change the attribute's meaning.
- Preserving the original casing of `MapHeaders` keys is consistent with the current mapping API; the individual `Header` attribute uses PSR-7 lookup. Universal normalization requires a chosen API contract.
- A destructor of a user-supplied argument can mutate metadata after DI's final creation guard, during argument retirement or later caller-controlled release. Library-wide atomicity against arbitrary destructors is not promised and does not justify a new lifecycle engine.
- Raw `set()` retains the fresh definition; an in-flight factory may finish with its old value but cannot republish it into the updated shared cache; snapshots do not clone business objects. These are deliberate distinctions covered by contracts.

The audit does not replace an application-specific profile or cover every possible sequence of arbitrary PHP code. A mutation score also cannot prove complete semantic coverage: the private-parent survivor shows why independent expected results and review of the tests themselves matter.

## 11. Reproduction

From the final checkout root with development dependencies installed:

```bash
php -n -d opcache.enable_cli=0 vendor/bin/pest --compact --order-by=random --random-order-seed=2026111506 --do-not-cache-result
php -d memory_limit=1G vendor/bin/phpstan analyse src tests --level=max --no-progress --memory-limit=1G --debug
php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
composer validate --strict
composer audit --format=json
```

The complete PHP 8.5 suite is run separately with `-n -d opcache.enable_cli=1 -d opcache.jit_buffer_size=0` and `--order-by=reverse`. The platform probe is separate: exit 1 on the specified 8.5.8 binary represents the E1 semantics described above and is not combined with the Pest outcome.

Isolated deliberate mutants, raw samples, source origins, consumer/App data and per-file review assessments are preserved in the evidence. Commands with absolute audit paths record a particular execution; reproduction requires selecting the corresponding commit and the appropriate local PHP/vendor paths. Replacing PSR-4 mappings alone after loading an optimized Composer classmap does not prove isolation.
