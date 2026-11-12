# Componenta DI: deep container audit

Date: 2026-11-12. Repository: `componenta/di`. Audited baseline: `4e3a9607a9264b92ef3f92b98fe2829881c8e8c2` (v5.0.4). Fixed production code: `fa4c674`; final test/documentation alignment: `bf0047b`.

## Outcome

Three confirmed defects were fixed and pushed in separate commits. A fourth finding is a measured performance problem whose remedy touches the late-attribute-loading contract; it has a separate design plan and was not changed opportunistically. The PHP 8.5 callback work is specified as a DI 6 proposal, without implementing an architectural migration in this audit.

The final suite passes **1,012 tests / 3,615 assertions** on both PHP 8.4.23 and PHP 8.5.8. The same result holds with the current companion repository workspaces used by Linux CI. Static analysis, formatting and Composer dependency audit pass. This is evidence for the tested configurations, not a claim that every application path or every supported operating system has been exercised.

Machine-readable test/dependency records, all four harness outputs before and after the fixes, availability measurements, and the paired runtime comparison are preserved in [2026-11-12-evidence.json](2026-11-12-evidence.json). Workspace paths in that evidence are normalized to `<audit-workspace>`; numeric results and commit identities are unchanged.

| Finding | Impact | Disposition |
| --- | --- | --- |
| A1: nested parsed-body objects lose path resolution | Valid request data can be treated as missing or replaced by a default. | Fixed in [`af4c308`](https://github.com/componenta/di/commit/af4c308df3e617345b0ad900da0d79cc7c36692f). |
| A2: `ReflectionObject` in class-name caches | Retains instance graphs and mixes instance-specific metadata. Relevant to long-lived containers/extensions using object reflectors. | Fixed in [`0f317b6`](https://github.com/componenta/di/commit/0f317b643da15170c0056333baf1191575dd3b30). |
| A3: runtime registration leaks foreign validation/autoload exceptions | Callers cannot consistently catch container configuration failures; diagnostics lack the registration owner. | Fixed in [`fa4c674`](https://github.com/componenta/di/commit/fa4c674095e09134d97d70a647a02084872cdf2a); contract alignment in [`bf0047b`](https://github.com/componenta/di/commit/bf0047b3aebc891a24e0d747c376cd96b5d58d68). |
| A4: repeated probing of unavailable attributes | One unresolved optional attribute causes 22 extra autoloader calls per unrelated warm `make()` in the benchmark fixture. | Reproduced; [architecture/performance plan](../plans/attribute-availability-performance.md), no production change. |

## 1. Scope and method

The review covered public resolution and mutation behavior, explicit definitions, factories and invokables, aliases, delegators, external containers, bootstrap protection, parameter/callable resolution, native type acceptance, attribute composition and late loading, object lifecycle, lazy/proxy creation, property hooks, request extraction/mapping/provenance, and metadata ownership. These areas were checked against README contracts and the existing test suite, with independent review of the main subsystems.

Work combined source inspection, failing-before/passing-after regressions, targeted runtime probes, whole-suite checks on two PHP versions, companion-workspace integration checks, dependency advisory inspection, and the repository's benchmark harnesses. Architectural changes were separated from corrective patches. No compiled container, new request policy or resolver-priority rewrite was introduced.

The baseline suite was already substantial: **995 tests / 3,580 assertions** passed on both runtimes before changes. The new contracts add 17 test cases. No coverage percentage is inferred from that count. Dependency internals were exercised through integration, not exhaustively audited as separate repositories.

## 2. Confirmed defects

### A1 — `PayloadParam` fails through nested objects

**Location:** [`src/Attribute/PayloadParam.php`](../../src/Attribute/PayloadParam.php). Regression: [`PayloadObjectPathContractTest.php`](../../tests/Contract/PayloadObjectPathContractTest.php).

PSR-7 parsed bodies may be arrays or objects. The extractor normalized an object at the root, but traversed subsequent path components as arrays only. A body containing an object under `customer` could therefore fail to resolve a path such as `customer.email`, even though the field exists. The observable effect is a missing-value error or unintended default substitution.

The fix normalizes each object encountered during traversal with `get_object_vars()` before looking up the next component. It does not recursively convert the entire payload or clone a returned leaf object. It preserves numeric path segments, explicit-null versus missing distinction, and the distinction between a literal dotted key and an explicit `ConfigPath`.

Tests include mixed object/array nesting, object roots, nullable/default cases, numeric indices, dotted keys, leaf identity, private properties and magic getters. Native public property hooks retain the same behavior already used for root objects: `get_object_vars()` may execute such hooks. The fix does not introduce `__get()` or private-property access.

**Failure evidence:** three of the initial four regression cases failed on the baseline on both PHP versions. The completed five-case file passes, as do adjacent request extraction tests and the full suite.

**Compatibility:** nested public object data now behaves like equivalent array data. Code depending on the erroneous missing/default result changes as intended. There is no broad request-mapper rewrite.

### A2 — object reflectors escape into shared class caches

**Locations:** [`AttributePlanBuilder`](../../src/Attribute/Composition/AttributePlanBuilder.php), [`AttributeProcessor`](../../src/Resolver/Attribute/AttributeProcessor.php), [`ObjectPipeline`](../../src/Object/ObjectPipeline.php). Regression: [`ReflectionObjectLifetimeContractTest.php`](../../tests/Contract/ReflectionObjectLifetimeContractTest.php).

`ReflectionObject` extends `ReflectionClass`. Three caches accepted it under the same class-name identity as ordinary class reflection. A `ReflectionObject` retains its reflected instance, so cached plans/metadata could retain that instance and its reachable object graph after application references were released. In a persistent process, a single metadata preparation could extend the lifetime of request-scoped or otherwise temporary objects to the container's lifetime.

The same identity mistake affected correctness. Two instances of one class may expose different dynamic properties through `ReflectionObject`. A plan warmed using plain `ReflectionClass`, or another instance, could replace the current reflector's metadata and hide those instance-specific properties.

The fix excludes `ReflectionObject` from both reads and writes of class-name metadata caches in all three owners. It preserves the reflector for the current operation, so handlers see the actual object-specific metadata. Ordinary `ReflectionClass` and class-string resolution continue using the existing caches.

**Failure evidence:** five of eight new cases failed before the fix: three lifetime cases and two metadata-isolation cases. The remaining three cases are controls for ordinary class reflection. After the fix all eight pass on both PHP versions. `WeakReference` checks demonstrate object/payload release after references are dropped and GC runs; the tests do not rely on private cache inspection.

**Compatibility and cost:** object-reflector calls now recompute their instance-specific metadata. That is required for correctness and avoids retaining the instance. The usual container path uses class reflection and keeps its caching. This finding does not mean every ordinary `make(ClassName::class)` leaked objects.

### A3 — registration does not consistently normalize failures

**Location:** [`src/Container.php`](../../src/Container.php), definition registration in `set()` and delegator registration in `delegator()`. Regressions: [`RuntimeRegistrationExceptionContractTest.php`](../../tests/Contract/RuntimeRegistrationExceptionContractTest.php) and [`DelegatorRegistrationAtomicityContractTest.php`](../../tests/Contract/DelegatorRegistrationAtomicityContractTest.php).

Validation during runtime registration can trigger an autoloader. A foreign throwable from that loader previously escaped directly, while other configuration failures use Componenta's container exception hierarchy. This happens during registration, before executing the registered definition or decorator body.

The fix applies a narrow configuration boundary around validation/registration: existing Componenta exceptions preserve identity; other throwables become `InvalidConfigurationException` with the DI ID and original throwable as `previous`. Cache invalidation and binding replacement still occur only after registration succeeds.

Regressions verify both definition and delegator registration, exact previous-exception identity, the old shared value and old factory remaining usable after rejection, a successful retry once loading works, and unchanged propagation of an existing Componenta exception.

**Failure evidence:** two of four new cases failed before the fix and passed afterward on both runtimes. A complete-suite run also exposed an older atomicity test that asserted the former raw exception as incidental behavior. That assertion was deliberately updated to require the new wrapper and exact original cause, while retaining its core assertion that a rejected decorator leaves no partial pipeline entry. The README now states this boundary explicitly.

**Compatibility:** code catching a foreign autoloader exception directly around these registration methods should catch `InvalidConfigurationException` and inspect `getPrevious()`. The original exception is not discarded. Exceptions thrown after entering an explicitly invoked ordinary callable body still propagate unchanged. This distinction is covered by the existing exception contracts.

## 3. Architecture assessment

### Preserve the present division of responsibility

The current container has clear ownership boundaries worth retaining:

| Boundary | Assessment |
| --- | --- |
| `ContainerFactory` → sealed runtime services | Bootstrap protection and extension ordering are deliberate. Extension factories can depend on existing services while incompatible late semantics are rejected. |
| `Container` → alias/cache/delegator helpers | Mutation revisions prevent stale in-flight results from repopulating invalidated caches, including suspended Fibers. Keep this atomicity. |
| Explicit definition versus reflection creation | Explicit factories/definitions own their own lifecycle. Reflection creation owns attributes, constructor DI and setup. Combining them implicitly would change established behavior. |
| Parameter plan versus runtime resolution | Prepared metadata is separate from current provided/resolved values. Plan ownership, native omission and completion guards prevent several subtle cross-call failures. |
| Attribute composition versus execution | Capabilities/order are planned centrally, while isolated runtime attributes are materialized at execution. This is a suitable basis for PHP 8.5 callbacks. |
| Property chain versus final assignment | Pending writes allow multi-step conversion before one final readonly/setter assignment. Keep native setter semantics at the final boundary. |
| Public context versus private request provenance | Internal raw-source information remains outside user extension bags while conflict guards still receive it. Callback additions must respect this separation. |

There is no evidence here that a whole-container rewrite, a new service graph layer or restored AOT compilation is necessary. The larger coordination classes have nontrivial logic, but size alone is not a defect. The measured availability polling issue and callback binding semantics provide concrete reasons for narrowly scoped design changes.

### Observed behavior that should not be “fixed” by changing the contract

- `ClassDefinition` uses its explicit constructor/method path; it does not silently run the reflection attribute lifecycle. Its optional autowiring is intentionally narrower than reflection construction.
- An explicit factory owns its result and lifecycle; the container does not reprocess its returned object's attributes.
- Invokable definitions use native construction behavior rather than inheriting every reflection feature.
- An alias shares the base resolution, but the requested ID owns its delegator pipeline. Canonical delegators are not automatically inherited through every alias.
- `has()` may return false after checking a failing/unsupported candidate. It is not proof that a later `get()` can never fail, and is not a request to execute all factories.
- A bare PSR-7 type is not an implicit current-request source. Explicit request transport/source attributes and raw-provenance checks are intentional.
- Variadic parameters use an explicit collection or numeric tail under their existing rules. They do not enumerate all services of a type. By-reference parameter injection remains unsupported.
- Internal PHP optional parameters can require native omission rather than synthesized `null`; the resolver intentionally preserves that distinction.
- Union autowiring is currently absent from the named-type resolver. Adding it is a DI 6 feature with ambiguity semantics, not a silent 5.x bug fix.

A suspected `WeakMap<ParameterTarget, PreparedParameter>` value-to-key retention issue was tested with the real sealed parameter pipeline and weak references. The temporary targets were collected on the tested PHP runtimes. It was not classified as a confirmed leak. This is separate from A2's reproducible strong retention through named caches.

## 4. Performance findings

### A4 — unavailable attributes amplify work on unrelated objects

`AttributeDefinitionRegistry::revision` checks every previously unavailable attribute using autoload-capable `class_exists()` and `interface_exists()`. Several planning and guard layers read the revision during one resolution. After one unresolved annotation is encountered, even an unrelated empty class incurs these repeated probes.

The new [`AttributeAvailabilityBench.php`](../../benchmarks/AttributeAvailabilityBench.php) isolates clean and affected containers, counts only the benchmark's missing-attribute autoload requests, and includes warm shared-cache hits as a control. Seven rounds of 10,000 operations per case ran in separate PHP 8.4/8.5 processes with CLI OPcache off.

| Case | PHP 8.4.23 median | PHP 8.5.8 median | Extra autoload requests per operation on both |
| --- | ---: | ---: | ---: |
| Unrelated `make()`, no unavailable attributes | 7.64 µs | 8.74 µs | 0 |
| Unrelated `make()`, one unavailable attribute | 35.68 µs | 38.58 µs | 22 |
| Unrelated `make()`, eight unavailable attributes | 217.61 µs | 301.71 µs | 176 |
| Warm shared `get()`, same cases | 0.30–0.32 µs | 0.31–0.35 µs | 0 |

The stable result is the exact request count, reproduced in every round and both runtimes. The timings are local, instrumented microbenchmark observations; they do not establish an application throughput guarantee or a ranking of PHP versions. Real filesystem/composer autoload costs can differ.

**Why this is a plan rather than a quick patch:** late availability can change within one constructor, handler, callable or resolution attempt. Merely switching to `class_exists(..., false)`, polling only once per top-level call, or permanently caching a negative result can violate existing late-loading and no-replay contracts. The [dedicated plan](../plans/attribute-availability-performance.md) identifies the required semantics, candidate checkpoints, falsification tests and a bounded design gate before production changes.

### Baseline measurements and existing optimizations

The four existing harnesses were run on the baseline with 30,000 runtime operations, 1,000 container builds and CLI OPcache enabled. The runtime/build/parameter-plan harnesses use seven-round medians. Selected baseline results:

| Operation | PHP 8.4.23 | PHP 8.5.8 |
| --- | ---: | ---: |
| Build default container | 99.52 µs | 103.95 µs |
| Build representative container | 131.56 µs | 133.73 µs |
| `make()` with no arguments | 7.38 µs | 7.38 µs |
| `make()` with one autowired dependency | 14.14 µs | 16.65 µs |
| Call reused closure | 17.55 µs | 19.24 µs |
| Call fresh closure | 19.74 µs | 20.66 µs |
| Call method array | 8.79 µs | 9.04 µs |
| Resolve six targets with preparation | 30.61 µs | 33.29 µs |
| Resolve six already prepared targets | 22.55 µs | 26.97 µs |

Prepared parameter metadata measurably reduces repeated planning cost in this fixture. Method-array calls benefit from stable metadata; closure calls intentionally avoid sharing runtime closure reflectors by a class-like identity. Optimizing closures requires lifetime evidence, not just a smaller benchmark number.

In `GeneratedVsReflectionBench`, the explicit-factory fixture measured approximately 2.85/3.07 µs versus 23.95/24.85 µs for its reflection fixture. These are different construction paths with different responsibilities. They show that explicit factories can be useful for a genuinely hot path; they do not justify rebuilding AOT or claiming equivalent semantics. Its one-off container-build figures are affected by first-load order and should not be treated as a fair cold-start comparison.

### Comparison of the corrective patches

All four harnesses also completed successfully against the fixed code on both runtimes. The first unpaired before/after measurements showed a large PHP 8.4 closure latency increase, so a bounded controlled comparison was run before interpreting it.

The follow-up used exactly four processes per runtime in baseline → fixed → fixed → baseline order, identical dependencies, the same unmodified `RuntimeBench.php`, 30,000 runtime iterations, 1,000 builds, CLI OPcache on and JIT off. Each process reports its seven-round median. The following figures are medians of the two process medians per source/runtime, not confidence intervals or a pooled fourteen-round sample:

| Operation | PHP 8.4 baseline → fixed | Change | PHP 8.5 baseline → fixed | Change |
| --- | ---: | ---: | ---: | ---: |
| Build default | 106.33 → 98.26 µs | −7.59% | 104.87 → 102.93 µs | −1.84% |
| `make()` no arguments | 6.98 → 6.81 µs | −2.42% | 7.03 → 8.29 µs | +17.92% |
| `make()` autowire | 14.82 → 14.88 µs | +0.42% | 15.15 → 15.84 µs | +4.53% |
| Call reused closure | 20.52 → 18.15 µs | −11.57% | 19.10 → 18.68 µs | −2.21% |
| Call fresh closure | 17.59 → 18.00 µs | +2.34% | 19.59 → 19.04 µs | −2.81% |
| Call method array | 8.51 → 8.96 µs | +5.32% | 9.40 → 9.13 µs | −2.93% |

The large original PHP 8.4 closure increase did not reproduce. This does **not** establish that all overhead is absent. The PHP 8.4 method-array result has a small positive signal in both adjacent pairs. PHP 8.5 no-argument creation is heterogeneous: fixed process medians were 9.392 and 7.183 µs versus baseline 7.048 and 7.007 µs, producing pair deltas of +33.25% and +2.50%. A stable +18% effect is not demonstrated, but the observed values must not be hidden. The JSON includes full ranges and pair deltas. No cause such as scheduling was independently measured, and two processes per source are insufficient for a statistical-significance claim.

The comparison explicitly overrode the optimized Composer classmap and root `autoload_files` for the selected source snapshot. Reflection checks verified `Container` and internal functions before timing, and all 130 loaded DI declarations after timing. Changing only `COMPONENTA_DI_BENCH_SOURCE`/PSR-4 configuration while sharing an optimized vendor would not prove that the intended source was measured. For independent reproduction, use separate checkouts with matching generated autoloaders, or verify equivalent source redirection.

## 5. PHP 8.5 refactoring direction

The full proposed contract and implementation slices are in [DI 6 callbacks and union autowiring](../plans/php85-callbacks.md). The recovered intent is supported by the current architecture, with several corrections:

1. Keep `Init`'s existing implementation. Direct PHP 8.5 probes already execute an inline static closure and a private static first-class callable with injected dependencies through the real container.
2. Add repeatable `Transform` as `ValueTransformer` on parameters/properties. Providers still own acquisition; properties still use pending writes and native setter semantics.
3. Extend `SetUp::$method` to `string|Closure`, retaining the string method path and argument name. A callback receives the constructed object explicitly; do not bind `$this`.
4. Add one narrow internal first-argument binding seam to the existing parameter resolver. Passing `[0 => $value]` through ordinary `call()` alone does not protect the semantic argument from authoritative attributes or custom resolvers.
5. Implement union autowiring with zero/one/multiple-candidate behavior. Do not select the first union arm or instantiate alternatives to compare identities.
6. Preserve native callback scope and lifetime. Inline closures, named-function wrappers and closure-valued class constants have different state behavior.

The callback plan also closes a future integration gap before implementation: a named-function setup callback has no declaring class on its reflected parameters. Its private mapped-request provenance therefore needs an explicit internal owning class at the existing source boundary. This is a requirement of the new callback path, not an additional confirmed DI 5 defect.

The old plan's arrow-function examples are invalid in PHP 8.5 attribute arguments. Official references: [closures in constant expressions](https://wiki.php.net/rfc/closures_in_const_expr), [first-class callables in constant expressions](https://wiki.php.net/rfc/fcc_in_const_expr), and [PHP 8.5 migration features](https://www.php.net/manual/en/migration85.new-features.php).

The audit also reproduced a PHP-engine issue on 8.5.8 involving trait-relative first-class references. The upstream [fix `44a2a2c3`](https://github.com/php/php-src/commit/44a2a2c3db9e4e06c41a0603c3826fac3b1b99f3) must be accounted for when choosing the released minimum supported PHP patch. The inspected NEWS places it in the future-dated November 19 / 8.5.13 section; this report does not claim that release already exists. This is a release gate for complete trait-relative callback support, not a reason to emulate PHP scope inside DI.

## 6. Verification record and limits

| Configuration / check | Result |
| --- | --- |
| Baseline, published dependencies, PHP 8.4.23 | 995 tests / 3,580 assertions passed. |
| Baseline, published dependencies, PHP 8.5.8 | 995 tests / 3,580 assertions passed. |
| Fixed code + aligned contracts, published dependencies, PHP 8.4.23 | 1,012 tests / 3,615 assertions passed. |
| Fixed code + aligned contracts, published dependencies, PHP 8.5.8 | 1,012 tests / 3,615 assertions passed. |
| PHPStan max, `src` and `tests` | No errors. |
| CS Fixer dry run | No changes required, 343 source/test files at the checked commit. |
| Composer dependency audit | No reported vulnerability advisories; this is not a comprehensive security certification. |
| Companion-workspace Linux reproduction, PHP 8.4.23 | Full `composer check` passed; same 1,012 / 3,615 result. |
| Companion-workspace Linux reproduction, PHP 8.5.8 | Full `composer check` passed; same 1,012 / 3,615 result. |
| Companion-workspace Composer metadata/dependencies | `validate --strict`, install and audit passed. |
| CI benchmark smoke harnesses, companion workspaces, PHP 8.4 | All four passed at the workflow's 10,000 / 1,000 settings. |
| Four existing measured harnesses after fixes, published dependencies | Passed on both runtimes at 30,000 / 1,000 settings. |
| New attribute-availability harness and preservation contracts | Both runtimes: benchmark/lint passed; 84 targeted tests / 357 assertions passed. |
| Bounded paired runtime check | Eight valid processes total; source identity verified; results and limits above. |

The isolated companion-workspace reproduction used the actual path repositories configured by `.github/workflows/ci.yml`, with `symlink: false` and these pinned source revisions:

| Companion | Source SHA | Composer path version |
| --- | --- | --- |
| `componenta/config` | `dcd218904f7336a61d059363ae1b336f54ad7453` | `3.0.0` |
| `componenta/validation` | `8f888784bbe12069f8f9dc75703c5dd66c43e53f` | `3.0.1` |
| `componenta/reflection` | `62aca6575934f119277236bb5dec7953063d69da` | `2.0.1` |

The table above records the original local Linux verification. The follow-up [cache parity recheck](2026-11-12-cache-parity-recheck.md) retrieved the push workflow that the original PR-only workflow lookup had not exposed: [CI for `e75aa6a`](https://github.com/componenta/di/actions/runs/43249462750) passed Linux PHP 8.4/8.5, Windows PHP 8.4, coverage (90.3%) and mutation (85.38%) gates. Those remote measurements belong to this first audit's final source, before the additional recheck fixes. The local static PHP binaries do not include PCOV/Xdebug. PHPStan's optional dynamically loaded accelerator could not load in the static binary, but analysis completed successfully; that warning was not a source-code analysis error.

### Reproduction

Use the repository's supported PHP runtime and installed Composer dependencies:

```bash
composer validate --strict
composer install --no-interaction --no-progress --prefer-dist
composer check
composer audit --no-interaction
```

For the existing measured harnesses, set `COMPONENTA_DI_BENCH_AUTOLOAD` to the installed `vendor/autoload.php` and `COMPONENTA_DI_BENCH_SOURCE` to this checkout's `src`; use `DI_BENCH_ITERATIONS=30000`, `DI_BUILD_ITERATIONS=1000`, and `php -d opcache.enable_cli=1`. Run `RuntimeBench.php`, `GeneratedVsReflectionBench.php`, `BuildPhasesBench.php` and `ParameterPlanBench.php` separately. Use the availability harness's documented controls for A4; its measurements use OPcache off and are not directly comparable with the other table.

## 7. Prioritized next work

1. Review the measured availability-polling plan and settle its explicit observability/checkpoint design gate. Preserve late-loading correctness before optimizing the hot path.
2. Review the DI 6 callback/union specification, including the deliberately explicit first-argument and transformation-source policies. Implement its small slices only after that architecture review.
3. Keep platform/coverage/mutation gates mandatory for subsequent changes and confirm the PHP patch floor against native trait-scope regressions before the planned major release.

The three corrective patches are already on the branch. The proposed architecture remains reviewable in the two linked plans; no feature migration or release was performed as part of this audit.
