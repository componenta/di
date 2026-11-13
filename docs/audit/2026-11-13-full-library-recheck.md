# Full library and specification recheck — November 13, 2026

[Full Russian translation](2026-11-13-full-library-recheck.ru.md).

**Result:** the fresh review found and corrected additional local defects in mutation reentry, parameter-plan coherence, late class discovery and native-callable cache identity. The whole production source tree was reviewed. The current 5.x implementation should retain its corrected availability polling; a broad performance rewrite is not justified by the measured workloads. Definition ownership does merit a next-major redesign, with one binding table in `CompositeResolver` and registration-free execution strategies. PHP 8.5 callback work should be assessed feature by feature rather than treated as one mandatory platform migration.

**Release status:** the published release remains **v5.0.5**, at `a56f3d70dd56bdd62947b6c322aa878e8a76ef4c`. The new fixes described here are on `main`; this report does not announce another release or claim that the proposed major architecture has been implemented.

## Evidence and source boundaries

The machine-readable index is [2026-11-13-full-library-evidence.json](2026-11-13-full-library-evidence.json). It records commands, runtime/dependency versions, source identities, regression results and raw measurements. The previous [audit](2026-11-13-di-audit.md) remains historical evidence; this report records the new review.

| Boundary | Identity |
| --- | --- |
| Released starting point | `a56f3d70dd56bdd62947b6c322aa878e8a76ef4c`, v5.0.5 |
| Final production correction | `7dc88a415ba40c3b99daf9ee64b656d2c9028b4b` |
| Verified production source tree | `a1e173e3bef2dbfe7c2d9370e4bfbece4a31445b` |
| Verified test tree | `f7fa2b22c648824d4b9ff2f87e124ae3fe5d2fab` |
| Workload measurement source | `fce3f176a2ab37b6ac7d595712c3fe5ff18564b0`, before the callable-case cache correction |
| Portable native-fixture source | `71a843082e7850672bc1fef87401e5697d433f66`; the same production source tree as the final correction |
| Benchmark bootstrap correction | `71a843082e7850672bc1fef87401e5697d433f66`; production/test trees unchanged |

Final local verification used an immutable source copy with published dependencies, including `componenta/config` v3.0.0, `componenta/reflection` v2.0.1, `componenta/validation` v3.0.1 and `componenta/caster` v1.0.0. The test tools were Pest 4.7.8, PHPUnit 12.5.33 and PHPStan 2.3.5. These results do not depend on unpublished companion-package changes.

## Review coverage

The combined review covered **all 161 files under `src/`**, with focused executable checks at the stateful and extension boundaries. Coverage means source inspection, targeted reproduction and existing-contract review; it is not a claim that every possible user callback was explored.

| Production area | Reviewed behavior |
| --- | --- |
| Container, construction, configuration and definitions | Canonical bindings, bootstrap, extension admission, factory ABI, ClassDefinition arguments/references, fresh/shared creation and protected services. |
| Alias/cache/delegator/external-container internals | Invalidation sets, revision publication, stable identity, deferred callable dependencies, reentry, cycles and Fiber ownership/recovery. |
| Attribute composition and object pipeline | Discovery, availability revisions, capability ordering, constructor/property/method phases, completed-work checks, lazy/proxy attempts and property staging. |
| Parameter and request resolution | Prepared slots, actual consumed plans, source conflicts, explicit/default/autowired values, private provenance, nested resolution and rejected/failing attempts. |
| Callable, target and metadata infrastructure | Native and magic callables, method/class casing, reflection identity, weak lifetime boundaries, variadics and supplied parameters. |
| Public attributes, interfaces and exceptions | Existing extension contracts, error projection, PHP 8.5 syntax boundaries and migration implications. |

The three architecture/specification pairs were reread using the implementation-specification workflow, with complete English/Russian versions and independent semantic reviews. Their normative desired states, migration obligations, evidence boundaries and blocked scopes are kept distinct.

## Published local corrections

Each correction was reproduced before changing the affected code, then checked on PHP 8.4.23 and 8.5.8. Source and regression changes were published in coherent commits. The parameter-coherence work needed two related commits; these rows are change slices, not a claim of seven unrelated defect families.

| Commit | Confirmed failure | Corrected behavior and durable proof |
| --- | --- | --- |
| `4621f9e` | An external `has()` retargeted an alias, after which local `get()` could permanently cache the old source under the new route; an external owner could also bypass a newly selected protected target. | Refresh canonical routing after the ownership callback and recheck protected eligibility. [ExternalLookupMutationContractTest](../../tests/Contract/ExternalLookupMutationContractTest.php) covers direct and ancestor aliases, repeated lookups and protected-core identity. |
| `fedf953` | Attribute discovery during parameter work could leave prepared resolver slots inconsistent with the plan used for execution. | Synchronize execution with the actual discovery state. [ParameterExecutionRevisionContractTest](../../tests/Contract/ParameterExecutionRevisionContractTest.php) exercises cold/warm and cached/non-memoized paths. |
| `5fe8cc0` | An external-container registration callback added and warmed another deferred delegator dependency, which the initial dependency snapshot omitted. | Discover newly added dependency IDs without querying an already inspected ID again; invalidate against the completed current graph. [ExternalRegistrationMutationContractTest](../../tests/Contract/ExternalRegistrationMutationContractTest.php) includes multiple generations, unaffected identity and failure before publication with nested changes preserved. |
| `7da5163` | A fallback interface probe could load a class while both existence-call return values remained false, causing valid ClassDefinition or attribute/selector registration to be rejected. | Recheck already loaded classes without another autoload attempt. [FactoryClassAvailabilityContractTest](../../tests/Contract/FactoryClassAvailabilityContractTest.php) and [AttributeDefinitionAvailabilityContractTest](../../tests/Contract/AttributeDefinitionAvailabilityContractTest.php) retain missing-name and throwing-loader controls. |
| `eb7818d` | The same later-probe discovery pattern could hide a request DTO source conflict before factory invocation. | Recognize the discovered source and preserve conflict rejection. [RequestSourceAvailabilityContractTest](../../tests/Contract/RequestSourceAvailabilityContractTest.php) proves the factory is not used to bypass the source boundary. |
| `fce3f17` | A later refresh could mark attribute semantics as completed even though the corresponding handler plan had not actually been consumed. | Retain and report the consumed attribute plan; reject incompatible completion instead of silently skipping a source. The expanded [ParameterExecutionRevisionContractTest](../../tests/Contract/ParameterExecutionRevisionContractTest.php) covers prior-handler variants. |
| `7dc88a4` | Case variants of one native static class callable retained duplicate execution plans for the same PHP declaration. | Canonicalize the native class portion of the plan key while preserving callable behavior. [CallableCaseCacheRetentionContractTest](../../tests/Contract/CallableCaseCacheRetentionContractTest.php) checks 8,192 equivalent spellings, correct returned values and bounded retained memory. |

These changes do not introduce a definition registry rewrite, alter the PHP `^8.4` requirement, or replace callback/attribute APIs. Opaque service-ID resolution and native PHP callable semantics remain separate responsibilities.

## Cache and stability verification

The tests compare observable values, exception ownership, callback/handler execution, object identity and lifetime. Expected negative cases are explicit; unexpected exceptions in the deterministic mutation-parity diagnostic are not converted into equal-looking results.

| Check | PHP 8.4.23 | PHP 8.5.8 |
| --- | --- | --- |
| Final complete suite, immutable final source and published dependencies | 1,139 passed; 2 PHP-8.5-only skips; 22,570 assertions | 1,141 passed; 22,578 assertions |
| Additional focused entry/definition/bootstrap/Fiber review | 102 passed; 394 assertions | 102 passed; 394 assertions |
| Deterministic mutation parity | 600 operations; 14,400 comparisons | 600 operations; 14,400 comparisons |
| PHPStan maximum level | No errors on final source | Covered by the final static-analysis gate |
| Formatting | 366 files checked; no fixes needed | Source remains compatible with the PHP 8.4 floor |

The final PHPStan run used its PHP implementation; the optional turbo extension could not load in the static CLI runtime. The analysis itself completed successfully. The exact logs and commands are indexed in the evidence JSON.

With the selected checkout and published dependencies installed, the corresponding repository checks are:

```sh
php -n vendor/bin/pest --compact --colors=never
php -n vendor/bin/phpstan analyse --level=max --no-progress --memory-limit=1G
php -n vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
```

Use each supported PHP binary for the test command. Keep the immutable source/dependency identities when comparing a rerun with this evidence.

**Final hosted CI:** [run `43454980253`](https://github.com/componenta/di/actions/runs/43454980253), on production correction `7dc88a4`, completed successfully at 2026-11-13 16:12:23 UTC. Linux PHP 8.4.27, Linux PHP 8.5.12 and Windows PHP 8.4.27 jobs passed their full suites, static analysis and formatting. Dependency audit passed in its Linux PHP 8.4 job. The later benchmark/documentation commits preserve this production and test tree; the benchmark bootstrap has the separate smoke evidence described below.

The completed PHP 8.4 coverage/mutation job reports **90.6% coverage** against the 80% minimum and **86.06% mutation score** against the 85% minimum. Its 3932 mutations are reported as **3347 `tested`, 37 `timeout`, and 548 `untested`**, in 1774.21 seconds with four processes. These are the runner's own categories; passing the threshold is not a claim that every mutant was detected. The evidence records completed-job log excerpts, final status and separate local published-dependency results. No earlier commit's score is substituted for this run. The optional historical-version benchmark job was skipped because this was not a manually dispatched benchmark run.

Confirmed controls include null/false shared values, raw-service replacement preserving the underlying fresh definition, sibling identity, mutation while a factory/delegator is suspended, cycle recovery and abandoned Fiber ownership cleanup. An in-flight call may legitimately return its old value; the required property is that it cannot restore an invalidated shared cache.

## Performance: practical necessity, not a synthetic speedup claim

The fresh workload study is reproducible with [WorkloadNecessity.php](reproductions/WorkloadNecessity.php) and its [selected-source autoload adapter](reproductions/autoload-selected-source.php). It measured **66 cases × 5 rounds per runtime**, separately counting missing-name autoload work. Warm cases used 500 operations per round, container-cold cases 40, and count controls 20.

The measured source is **`fce3f17`**, not final `7dc88a4`. Both versions ran on Linux x86_64 with CLI OPcache enabled and JIT mode 0. Latency runs did not install the observing loader; counts were collected separately. “Cold” means a new container and new metadata/service state in an already running process with fixture classes loaded. It is not a fresh-process startup measurement.

Selected default-cache medians follow. Each cell is **PHP 8.4 / PHP 8.5, microseconds per operation**. These are baseline workload observations, not a before/after optimization comparison.

| Workload | Clean | Loaded foreign attribute | One missing name | Eight missing names |
| --- | ---: | ---: | ---: | ---: |
| Fresh plain object | 5.474 / 5.268 | 4.931 / 5.387 | 17.417 / 19.088 | 91.121 / 101.237 |
| Fresh object with core DI attributes | 23.567 / 25.901 | 24.241 / 26.353 | 65.137 / 70.758 | 305.883 / 332.499 |
| Reused closure invocation | 16.616 / 17.701 | 17.119 / 19.081 | 39.512 / 43.294 | 169.944 / 183.569 |
| Fresh root, shared autowired children | 15.902 / 17.031 | 15.364 / 16.223 | 38.824 / 41.790 | 178.608 / 193.561 |
| Explicitly fresh five-object graph | 43.070 / 47.223 | 43.735 / 46.761 | 128.059 / 141.158 | 654.452 / 706.572 |
| Warm shared root retrieval | 0.181 / 0.188 | 0.181 / 0.192 | 0.182 / 0.191 | 0.186 / 0.188 |
| New container, observation and first root | 190.356 / 212.346 | 202.400 / 211.217 | 358.403 / 396.658 | 1,316.168 / 1,432.186 |

The missing-name counts explain the specific cost. One/eight missing names produce 22/176 probes for plain creation, 70/560 for the attributed object, 38/304 for the reused closure, 40/320 for the shared-child root, and 152/1,216 for the explicit fresh graph. Warm shared retrieval produces zero in every case. Loaded foreign attributes also produce zero persistent missing-name probes. Existing DI attributes alone are not the cause of this multiplier.

Custom composition rules deliberately disable semantic plan memoization. The clean plain-object control then measures 16.937 / 17.438 µs, while warm shared retrieval remains 0.181 / 0.190 µs. That is a separate semantic cost, not evidence that all such work can be cached away. Shared children remain shared; multiplying a plain-object microbenchmark by every historical graph node would misstate ordinary repeated `make()` work.

**Decision:** no broad availability-performance refactor in current 5.x. The measured pathological workload is real, but the study does not establish its prevalence or fraction of application latency. Preserve local correctness fixes. If an application demonstrates material unresolved-name polling cost, B2—explicit refresh at defined user-code boundaries plus pure generation observation—is the preferred direction. Its synchronization contract and candidate proof remain blocked; merely splitting getters does not establish a speedup.

C, an index of unresolved names relevant to active targets, is not selected. It adds metadata lifetime and invalidation obligations and still needs coherent generations and consumed-plan semantics. Reconsider it only if a qualified B2 leaves measured irrelevant-name scanning that justifies the additional state. Historical prototype A is not a transparent optimization: it changes second-attempt discovery and exception timing. See the complete [availability plan](../plans/attribute-availability-performance.md) and [Russian translation](../plans/attribute-availability-performance.ru.md).

The benchmark bootstrap correction covers all five existing harnesses: select both optimized classmap entries and autoloaded functions from the requested source. Ten one-operation smokes passed across both runtimes with a different optimized vendor root. Windows path separators were reviewed and normalized in the adapter; this is not a claim that these benchmarks ran on Windows.

## Definitions: justified next-major refactoring

The three previously documented architecture defects were independently reproduced again:

1. Switching from a factory to an invokable leaves the old factory and captured payload owned by the former resolver.
2. A throwing old-factory destructor during same-owner replacement leaves `get()` old and `make()` new.
3. A throwing cached wrapper destructor can stop alias-family invalidation midway.

The [definition lifecycle specification](../plans/definition-owner-lifecycle.md) and [full Russian translation](../plans/definition-owner-lifecycle.ru.md) now select **one authoritative `CompositeResolver` binding table**. Each immutable binding contains the chosen strategy and validated definition data. Factory/invokable strategies no longer own registration maps. Bootstrap and runtime definitions use one admission path; deferred factories remain deferred, and public definition constructors remain unchanged.

The previous required `removeDefinition()` plus public retirement-receipt proposal is superseded. It could coordinate the old design, but it preserves multiple authorities and adds recursive detach, shared-leaf rules and extension-removal error handling. A separate generic registry/compiler is also unnecessary. Central ownership removes those synchronization responsibilities rather than making every extension implement them.

Central storage alone does not fix the two destructor failures. One **internal** holder retains retired bindings, base/resolved values and callable chains through `validation → publication → complete invalidation → release`. A dedicated committed-retirement exception distinguishes post-publication failure from rejection. No rollback of application effects, implicit GC, service shutdown or general transaction framework is promised.

The major migration is explicit: registration-free custom strategies, snapshot semantics for nested composites, standalone versus attached mutation authority, and one container owning an attached root's cache/invalidation boundary. Tests must prove old payload collection, coherent alias families, unaffected identity, nested mutation preservation and suspended invocation/retirement behavior. This is a correctness/ownership investment; no performance gain is claimed before implementation and measurement.

## PHP 8.5: separate language support from API decisions

The native study ran **14 fixtures on each runtime: 28 subprocess runs**. Some fixtures intentionally fail compilation or materialization; those failures establish language/compatibility boundaries and are not failing library tests. The [portable fixture generator](reproductions/Php85Callbacks.py) and [exact native evidence](2026-11-13-php85-native-evidence.json) use `71a8430`, with the same production source tree as the final correction. The current-`Init` fixtures assert both class and internal-function source mappings before running cached/non-memoized controls through the real container; pure-engine fixtures do not load DI.

| Feature | Evidence and recommendation |
| --- | --- |
| Existing `Init` | Already accepts callable values. On PHP 8.5, supported native closure/first-class-callable attribute syntax works through the existing pipeline. Inline closure state, named/static callable state, shared identity, lazy initialization and release behavior match native/cached-control expectations. No new `Init` API is needed. |
| Proposed `Transform` | An optional additive feature. A PHP-8.4-compatible implementation can support runtime closures while PHP 8.5 users use richer attribute syntax. It can be assessed for a future minor release after its semantic-input/suffix and source contracts are implemented and verified; it is not part of this audit's production changes. |
| Callback `SetUp` | Widening the public `string $method` property to `string|Closure` breaks subclasses that redeclare/promote the narrower property. The native probe confirms this on both versions. This requires an explicit major migration even though constructor-only forwarding has different compatibility. |
| Union autowiring | An independent behavior choice, not a prerequisite for native callbacks. It must preserve selected-candidate checks, ambiguity/error policy and warmed-plan behavior across all callers, including ClassDefinition. Do not bundle it into a syntax-only claim. |
| Trait-relative first-class callable engine fix, E1 | The tested PHP 8.5.8 runtime reproduces wrong consuming-class/parent/visibility behavior in native fixtures without DI. Verify the released fixed patch before promising that affected trait-relative form; this does not block all existing `Init` use, ordinary callbacks or unrelated 5.x fixes. |
| Request-to-DTO callbacks | Separate source/lifecycle/API questions remain. They are not silently authorized by either native syntax support or the other callback feature contracts. |

The [callback/union specification](../plans/php85-callbacks.md) and [full Russian translation](../plans/php85-callbacks.ru.md) own the precise feature contracts and readiness. DI must preserve native lexical scope; emulating it by rebinding closures would create a different behavior rather than fix the engine.

## Excluded false positives and limits

- Replaced and rejected closure factories and captured payloads are collectible before clearing reflection caches on both runtimes. The actual factory-registration path does not reproduce the proposed reflection WeakMap leak; obsolete resolver-owned registrations are a different, confirmed problem.
- `AliasResolver` does not cache arbitrary identity IDs: unmapped names return before its alias-path cache. Native callable casing duplication belonged to the callable-plan key and was corrected there.
- Native function/static-method static state differs from a freshly materialized inline closure's state. The native control establishes that distinction; differing counters alone do not prove a DI cache bug.
- ClassDefinition intentionally skips DI attributes, leaves runtime overrides literal and preserves its own explicit construction contract. ReferenceDefinition remains an argument descriptor. These are preserved contracts, not missing architecture.
- Failed creation retries, no-op custom-rule controls, late availability and Fiber recovery pass their relevant suites. Passing these checks is evidence for their tested boundaries, not a proof over arbitrary extension side effects.
- No application latency target, production workload frequency, generic shutdown behavior or full property-graph destructor order was inferred from synthetic fixtures.

## Recommended order

1. Retain and release the verified local corrections through the normal package process; keep the existing PHP 8.4 compatibility floor and distinguish `main` from the already published v5.0.5 tag.
2. Preserve corrected availability polling. Use application evidence before opening B2's synchronization contract; do not implement A or C as an assumed transparent speedup.
3. Implement centralized definition ownership with the complete internal retirement boundary only as the specified next-major change. Do not ship central storage without the adjacent cache-release guarantees.
4. Treat callback work by feature: existing `Init` support/documentation first, optional `Transform` separately, callback `SetUp` with its major migration, and union autowiring as an explicit behavior decision.
5. Keep the completed hosted CI and benchmark/native evidence tied to their actual source and runtime. Future implementations must converge with the EN/RU specifications rather than merely pass a broad test count.
