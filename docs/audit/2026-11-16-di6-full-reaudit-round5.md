# DI 6 full repository re-audit — round 5

[Русская версия](2026-11-16-di6-full-reaudit-round5.ru.md)

Date: 2026-11-16. Branch: `refactor/di6-definitions-callbacks`.
Baseline: `406c8ea563d2fa304434a695c4b6b43f1f269e6f`.
Reviewed implementation and tooling: `42b62afd9c6a57caa0db3916585e823ab6ebaafa`.

## Outcome

The fresh whole-repository review confirmed and corrected **two library defects and one audit-tooling defect**. It also strengthened public-contract tests, restored missing Russian README guidance, and reviewed the current EN/RU migration and architecture plans. Each completed unit was pushed to the same branch. No new architecture decision was necessary for these corrections.

The final library suite contains **1,648 runtime cases**, an increase of **28 cases and 179 assertions** on each local runtime. The comparison of JUnit case identities found no removed cases or changed existing skip status; four existing factory cases gained object-identity assertions. Full local checks passed on PHP 8.4.27 and 8.5.12. The exact final-source CI results, coverage and mutation accounting are below.

The review does not establish that all possible defects have been excluded. It records source coverage, executed witnesses, remaining mutation hypotheses and the limits of historical and current measurements separately. Evidence is indexed in the [verification record](evidence/2026-11-16-round5/verification.json), [file ledger](evidence/2026-11-16-round5/scope.json), [survivor review](evidence/2026-11-16-round5/survivors.json) and [raw evidence archive](evidence/2026-11-16-round5/raw-evidence.tar.gz).

## 1. Scope and evidence identity

The immutable baseline contains **563 tracked files**. Review methods were selected by file role:

| Baseline scope | Files | Fresh review |
|---|---:|---|
| Production `src/` | 169 | Complete file reading, ownership/control-flow review, contract and test mapping |
| Existing `tests/`, including fixtures and infrastructure | 249 | Complete file reading, assertions, negative controls, lifecycle and platform conditions |
| Maintained documentation, configuration, workflows, benchmark/reproduction code and other active files | 50 | Complete reading and current-contract review; all 14 EN/RU migration/plan files included |
| Historical audit/release reports and archived evidence | 95 | Dated status, source/run provenance, links, file hashes, structured formats and archive integrity; old experiments were not relabelled as fresh runs |
| **Total baseline** | **563** | **No unassigned file** |

Five new test files were reviewed as additions. The implementation/tooling commit has **568 tracked files: 169 source files, 254 test/fixture/infrastructure files and 145 other files**. A second reviewer checked the complete 13-file change set; the record identifies the two tests authored by that reviewer so that authorship is not confused with independence.

Source and tests at the final commit have Git trees `886c58afff5c667852122748a1003be3bd52dabc` and `decc32b4fc92f5119b573ed16efcd58e718ea9c9`. Their 423-file SHA-256 manifest fingerprint is:

```text
b1fb6aff09a904f5b081ebf6693e2c9cf07f920672cc4425dd76f401dced7b79
```

The algorithm is SHA-256 of UTF-8 `json.dumps(path_to_sha256_map, sort_keys=True)` using Python's default separators. The final local gates ran immediately before the last test-only commit; their recorded working-tree bytes match the final committed source/test trees exactly. The later benchmark-input correction changes only two documentation/tooling files and has separate regression evidence. Every accepted local gate has unchanged before/after source/test fingerprints.

All recorded JSON/XML files parsed; compressed archives passed their relevant CRC/format/path checks. The link scan's four apparent missing targets were callable examples inside inline code, not Markdown links. Actual relative file-link targets existed. Historical evidence remains attached to its original code and runtimes.

## 2. Corrected defects

### INV-EX-1 — an invokable constructor erased owned DI exceptions

**Problem.** `InvokableResolver::resolveDefinition()` caught every constructor throwable and wrapped it in `ResolutionException`. If the constructor re-entered the same service, the inner `CircularDependencyException` lost its public subtype and object identity at this outer boundary. Callers catching the specific DI exception could no longer handle it consistently with other creation paths.

**Correction.** [InvokableResolver](../../src/Resolver/Entry/InvokableResolver.php) now rethrows `ExceptionInterface` before the existing foreign-throwable normalization. The original exception, chain and diagnostic owner survive. Unrelated application constructor errors retain a `ResolutionException` with the original `previous` throwable. The direct zero-argument constructor contract and absence of attribute-driven construction remain unchanged.

**Proof.** [InvokableExceptionContractTest](../../tests/Contract/InvokableExceptionContractTest.php) exercises real constructor recursion through shared `get()` and fresh `make()`, exact exception identity, the cycle chain, invocation counts and successful recovery after failure. Foreign exceptions are negative controls. On each stable runtime, the unmodified baseline produced **2 failed / 2 passed / 16 assertions**; corrected source produced **4 passed / 28 assertions**. Related tests and full-suite gates passed. Commit: [`5067bf7`](https://github.com/componenta/di/commit/5067bf78d3b949531b807f572d000319e7e14c7e).

### PROXY-CTOR-1 — proxy eligibility incorrectly required a public constructor

**Problem.** [MakeHandler](../../src/Resolver/Attribute/Handler/MakeHandler.php) used `ReflectionClass::isInstantiable()` while validating a virtual proxy target. That rejected a concrete class with a private or protected constructor even when an application factory owned creation of the backing instance, or the established `NoConstructor` policy applied. The precheck rejected a supported creation strategy before it could run.

**Correction.** The check rejects interface, trait, abstract and enum kinds explicitly. Constructor access is left to the selected backing-object creation path. Type compatibility, backing-result validation, default native-adapter restrictions and custom-adapter delegation remain in place. The factory still runs during deferred initialization, and D1 propertyless rejection still occurs at the default adapter boundary.

**Proof.** [NonPublicConstructorProxyContractTest](../../tests/Contract/NonPublicConstructorProxyContractTest.php) covers private/protected constructors through parameter/property injection, deferred factory creation, backing identity, `NoConstructor` with setup, four unsupported kinds, and two propertyless controls. On the final test bytes, each runtime produced **8 failed / 4 passed / 24 assertions** before the production fix and **12 passed / 82 assertions** after it. Commit: [`ce0c0a6`](https://github.com/componenta/di/commit/ce0c0a6414d649eb86bf099cc88f2559b63baa1e).

Full CI subsequently found an unused-trait diagnostic in this new fixture that targeted PHPStan had missed. [`eefedd0`](https://github.com/componenta/di/commit/eefedd038090e686c06bca44f1dfa4e496af4280) uses that trait in the already exercised abstract fixture. No analyzer suppression was added. Full PHPStan and the same final-byte RED/GREEN witnesses passed. The failed intermediate CI and superseded evidence are retained and labelled.

### REPRO-RUNTIME-ID-1 — repeated PHP series overwrote benchmark logs

**Problem.** [run-cycle-guard-benchmark.py](reproductions/run-cycle-guard-benchmark.py) accepted repeated `--php` arguments but named worker logs using only major/minor. Two 8.4 binaries reused the same paths. Repeating the same version also mixed its statistical groups, leaving recorded hashes inconsistent with overwritten files.

**Correction.** The runner probes all selected runtime versions before output-directory creation, source walking or timed work and rejects repeated major/minor series with both conflicting binary/version values. One 8.4 plus one 8.5 remains supported. Patch-version comparisons require separate fresh output directories. The [reproduction guide](reproductions/OptimizationReproduction.md) now states this constraint and no longer claims whole-file identity between the updated driver and the historical one. Worker/timed bodies and old results were not modified.

**Proof.** A deterministic subprocess-protocol fixture runs the real old/new Python driver with synthetic PHP worker responses. The old driver ran eight workers but retained four unique stdout files; four saved hashes no longer matched. The corrected driver rejects duplicate inputs with exit 2, zero worker calls, zero source walks and no output creation. The unique 8.4/8.5 control keeps eight files with no overwritten hashes. Separate actual PHP 8.4.27 and 8.5.12 duplicate-input checks confirm rejection before nonexistent sources are inspected. This is driver correctness evidence, not a PHP benchmark. Commit: [`42b62af`](https://github.com/componenta/di/commit/42b62afd9c6a57caa0db3916585e823ab6ebaafa).

## 3. Strengthened test contracts

These changes expose incorrect mutated behavior while the original implementation remains GREEN. They are not counted as additional production defects.

| Contract | Added/strengthened oracle | Executed mutation witness |
|---|---|---|
| Direct single-parameter resolution | Return the reflected default, reject unavailable native defaults, preserve the distinction from batch omission, and detect a DI attribute revealed by validation-time autoloading | Exact `ff68328d9d27c7f8`, `5c2be6392ac9f4c2`, `13886b8fae4e7683` become RED; 3 cases / 12 assertions GREEN on restored source |
| Malformed request transport | Six invalid exact PSR transport-key values produce the owned diagnostic before the body runs; a later valid call recovers | Exact `c685bde216825f99` becomes RED for five malformed non-null controls; 6 cases / 42 assertions GREEN |
| Callable factory ownership | Shared and fresh products retain the identical original factory object, including bootstrap/runtime and cold/warm paths | A synthetic owner-cloning mutant that passed the old value-equality assertions fails all four strengthened cases |
| Built-in setup ordering | A later-declared extension selected `before: [LifecycleHook::class]` runs before `SetUp`, including repeated creation | Exact `e28e3d4eea2832d6` becomes RED; one added case / two assertions |
| Setup descriptors | Missing environment values use the descriptor default; explicit nested `ConfigPath` wins over deliberately conflicting parameter keys and retains its default | Exact `2d3da5b42f6aaf02` and `989628a219306c29` become RED; 2 cases / 5 assertions GREEN |

The five test-only subjects plus the two production-fix suites account for all 28 new cases and 179 added assertions. The archive retains commands, original/mutated/restored hashes, raw output and JUnit where produced. Exact mutant probes were run separately on both stable PHP versions; a focused old-suite GREEN is labelled with its actual file scope.

## 4. Validation and CI

| Local check | Result |
|---|---|
| PHP 8.4.27, `-n`, OPcache absent, random seed `2026111606` | **1,629 passed, 19 skipped, 24,951 assertions** |
| PHP 8.5.12, `-n`, CLI OPcache enabled, JIT off, reverse order | **1,646 passed, 2 skipped, 25,058 assertions** |
| PHPStan, configured complete `src tests`, max level | No errors |
| PHP CS Fixer, complete configured scope, no cache | 0 / 418 files need changes |
| Composer strict validation | Passed |
| Composer audit of the recorded 101 installed packages | No advisory, abandoned, filter or cooldown entries at the recorded check time |
| Five benchmark programs on both runtimes | All ten sequential smoke processes passed their source-origin checks |
| Python benchmark-runner input checks | Syntax and actual duplicate-input checks passed; synthetic before/after protocol evidence retained |

PHP 8.4 skips 18 native PHP 8.5 cases; PHP 8.5 skips one PHP 8.4-specific alias case. Local builds and Windows additionally lack the PDO SQLite prerequisite for one internal-callable test. Linux CI executes that case and adds two assertions. These are explicit environment conditions; no new skip was introduced by the changes.

### Exact final-code CI

[Run 43857035628](https://github.com/componenta/di/actions/runs/43857035628), attempt 1, completed successfully for `42b62afd9c6a57caa0db3916585e823ab6ebaafa`. All five required jobs passed. The optional historical-comparison benchmark job runs only for manual workflow dispatch and was skipped for this push.

| CI job | Passed | Skipped | Assertions | Result |
|---|---:|---:|---:|---|
| Linux / PHP 8.4 | 1,630 | 18 | 24,953 | Success |
| Linux / PHP 8.5 | 1,647 | 1 | 25,060 | Success |
| Windows / PHP 8.4 | 1,629 | 19 | 24,951 | Success |
| Windows / PHP 8.5 | 1,646 | 2 | 25,058 | Success |
| Coverage and mutation / PHP 8.4 | 1,630 | 18 | 24,953 | Success |

All four platform jobs passed the complete configured PHPStan max-level and style checks; the latter reported 0 / 418 files requiring changes. Full job logs, run/job metadata and independent metric checks are in the raw archive under `audit-round5/ci-final/`. The final report/evidence commit changes documentation and preserves the tested source and test trees.

**Coverage.** The downloaded Clover artifact reports **4,498 / 4,848 statements = 92.78%** and **441 / 558 methods = 79.03%** across all 169 production files. File totals reconcile with project totals, and the source-file set matches the tested commit. Pest prints **92.7%** because its formatter floors to one decimal place. These are statement and method measurements; the artifact does not establish branch/path coverage. Artifact `14224202628` was checked against its API SHA-256 digest, ZIP CRCs and exact extracted XML bytes. Its complete identity is in [ci.json](evidence/2026-11-16-round5/ci.json); the [lossless Clover copy](evidence/2026-11-16-round5/coverage.xml.gz) is retained.

**Mutation.** The completed run created **4,276 mutations in 115 files**: **3,749 `tested`, 59 `timeout`, 468 `untested`**. Pest's reported **MSI is 89.06%**, using `(tested + timeout) / total`, or `3,808 / 4,276`. The completed `tested` share alone is **87.68%** (`3,749 / 4,276`); here the denominator still includes all mutations. In plugin v4.0.1, `tested` means the worker completed with a non-success exit. It is not independent proof that each such worker failed a semantic assertion. `untested` means the worker completed successfully and the mutation survived this run. The categories reconcile with the total, with no pending or uncovered remainder.

The fresh 468-entry survivor catalogue contains **458 retained IDs, 10 added IDs and 19 IDs absent from the prior catalogue**. All ten additions have unique printed-mutation predecessors in the two changed production files: nine entire diff contexts are identical, and one has identical changed lines with an updated neighboring guard. Those ten pairs were reviewed against the final source. The remaining nine absent old entries are no longer reported as `untested`; absence alone does not establish whether a worker was classified `tested` or `timeout`. The fresh catalogue, correspondence table and individual review overlays are archived separately from the historical 477-entry catalogue.

### Mutation interpretation

All **477 survivors from the previous completed catalogue** were reviewed against the baseline, with individual diffs, reasons and limits. The three ownership scopes were 227 core, 146 attributes and 104 parameters/callables/request entries. Static current-path equivalence, redundant guards, performance-only changes, unused/native helper boundaries and candidate behavioral gaps remain distinguishable in the ledger.

Of those prior entries, **eight exact mutants** were executed in focused checks on both stable runtimes: seven now fail under the new public tests and one remains GREEN under finite lifetime controls. The remaining **469 entries** have static reasoning only. The factory-owner clone is one additional synthetic mutation, outside the catalogue. This accounting does not infer a full-suite mutation score from focused probes.

One explicitly executed lifetime-related mutant, `090c1a0b20df101a`, remained GREEN under the existing **9 tests / 19 assertions** on both runtimes. It changes metadata ownership and WeakMap writes while the cache-hit path remains gated. Those finite controls neither reproduce a leak nor establish global equivalence or bounded retention for all graphs. Unexecuted candidates concerning intermediate side effects, revision-source interleaving and parameter-qualified diagnostics remain hypotheses about mutated code, not confirmed original-source bugs.

Fresh review also narrowed several earlier static explanations. `Proxy` is final and readonly, so a witness based on a stateful derived `Proxy` cannot be valid; repeated `newInstance()` now retains only the documented, unexecuted native-argument/materialization hypothesis. A PHP class alias can have a name that cannot appear in an ordinary class declaration, so the invokable sentinel-string mutant is classified as an artificial literal edge rather than blanket equivalence. These are corrections to review reasoning, not newly reproduced library defects. The combined ledger exposes the corrected current explanation while preserving the original record explicitly as superseded.

The fresh full-CI mutation totals above are separate from those historical 477-entry classifications. Timeouts are separate from the tool's completed `tested` results; a passing MSI threshold does not turn every survivor into an equivalent program.

## 5. Security review

This was a manual source, trust-boundary and dependency audit. The managed deep-security coordinator was unavailable in the exposed tools, so no result from that service is claimed.

**Trust model.** Definitions, callable specifications, attributes, autoloaders, extension handlers and proxy/caster/validator implementations are application-controlled code. Request values crossing PSR extraction and mapping are untrusted data. Review traced authoritative source precedence, raw-field provenance after mapping, name/type-key conflicts, callback suffix transport, private DI metadata, constructor/property boundaries, guard placement relative to application side effects, and failure recovery.

No new exploitable path was confirmed. Request fields do not choose callback/class/service names in the reviewed paths; those are selected by application metadata. This layer does not parse raw streams/JSON or deserialize request-supplied programs. Mapping an object may invoke its public PHP property hooks, so the parser-provided object itself remains a trusted application extension boundary. A custom validator that ignores the protocol's throw-on-failure requirement is not evidence that the default pipeline bypasses validation.

Request size/depth limits and the cost of application callbacks remain application policies. The audit does not promise secret redaction of arbitrary application exceptions or traces. Dependency-audit results concern the recorded installed set at the stated time, not every future Composer resolution. Workflow permissions and pinned action references were read as part of the 50 active non-source/test files.

## 6. Architecture and performance decisions

### Current ownership remains coherent

The review followed definition admission/snapshots, attached-root publication, invalidation and post-publication retirement; alias/deferred dependencies and Fiber ownership; callable identity and metadata lifetime; the shared parameter loop and reserved semantic-first callback slot; request provenance; property staging; and lazy/proxy initialization. The corrections retain existing owners and public contracts. D1/A remains implemented at the default native adapter. Custom proxy factories retain their supported mechanism and deferred-callback responsibilities.

The current EN/RU migration and plan documents were read in full: **14 files / 3,440 lines**. No additional contract correction was required. The Russian README did need the missing native-lazy eligibility guidance; that was delivered in `fb3f8e0`, followed by matching descriptions for the two library corrections.

### B2 — unresolved optional-attribute polling remains a conditional architecture task

The final-source availability smoke observed the following instrumented workload. Each case used 10,000 operations, three rounds and 2,000 warmup operations per round. Timings include the counting autoloader; runtimes had different OPcache modes and are not being compared as a language-speed ranking.

| Operation | Missing-name autoload calls/op, both runtimes | PHP 8.4.27 median | PHP 8.5.12 median |
|---|---:|---:|---:|
| Clean plain `make()` | 0 | 4.360 µs | 3.985 µs |
| `make()` after observing one unavailable annotation | 24 | 18.468 µs | 17.432 µs |
| `make()` after observing eight unavailable annotations | 192 | 108.282 µs | 105.808 µs |
| Warm shared `get()`, all three controls | 0 | 0.131–0.133 µs | 0.119–0.138 µs |

This establishes a cost in a specific workload. It is not a before/after optimization result, an application-latency estimate or a memory-growth proof. The current bug fixes were not justified by these timings.

**Problem and possible benefit.** A long-lived container that has observed absent optional annotations repeatedly probes those names at later resolution boundaries. If real application profiles show material cost there, B2 could reduce redundant polling by separating explicit availability refresh from pure generation observation inside the existing owners. Clean paths and warm shared retrieval do not have that missing-name work to remove.

**Why this needs a decision.** Autoloading is observable application code: reducing polls can change when a class/alias becomes available, when loader exceptions occur, and whether incompatible metadata changes are detected after some work has already completed. A global negative cache or implicit freeze would alter those contracts.

**Conditional plan and acceptance criteria.** First establish the affected application workload and cost share. Before G2, explicitly revise or supersede the affected G1 compatibility constraints: name the permitted changes to discovery timing, loader exceptions and count-sensitive side effects, and update the normative contract and expectations. The current G1 decision retains polling; workload evidence alone does not reopen it. Then settle the owner/checkpoint/failure contract in G2, including same-poll discovery, completed-work rejection, bootstrap, request/callback suffixes, object phases and deferred initialization. Only then qualify an isolated B2 candidate in G3 with public behavior oracles, source-origin checks and serial paired measurements covering clean, missing-name, shared/fresh graph, custom-rule and startup controls. The expected gain is reduced measured redundant work with the chosen semantics preserved. If the workload need or replacement contract is not established, retain the current implementation. The detailed [B2 plan](../plans/attribute-availability-performance.md) remains conditional; G2/G3 are blocked and no production slice is selected.

### Large cold alias graphs — a separate optional optimization

Fresh reading of [AliasResolver](../../src/Internal/AliasResolver.php) confirms that the constructor validates each insertion by walking the already assembled target chain. For a long chain supplied target-near-first, the total walk can be quadratic. Successful resolution uses path compression; this observation concerns cold construction. The prior round's measurement included construction plus one resolution and must not be presented as pure lookup cost. No new alias benchmark was run in this round.

If application profiles justify this work, first measure construction separately from cold and warm resolution with realistic graph sizes. Then design a whole-graph visited/color validation pass with an O(V+E) target, preserving the chosen error precedence and deterministic cycle-chain diagnostics, opaque/numeric-string IDs and empty/self-reference validation. Keep alias mutation through `AliasResolver::set()`, as used by public `Container::alias()`, transactional: failed cycle checks must not publish a new map or invalidate a valid cache; successful changes must preserve descendant/deferred invalidation. Qualify the candidate with chains in both insertion orders, branching graphs, cycles, invalid IDs and mutation/reentrancy controls, followed by bounded paired measurements and memory checks. The potential benefit is lower startup cost for unusually large alias configurations; no hot shared-get gain is promised. This remains an optional design investigation, not a new correctness blocker or an implemented optimization.

### E1 — native PHP trait-callable expressions retain their support limit

A fresh standalone probe on the recorded stable PHP **8.5.12** passed **2 of 10** cases and failed eight affected scope/identity cases. It does not load DI. The passing controls are the named public callable default and inline attribute closure. Affected `self`, `parent` and private-reference forms can retain the first trait user's scope/identity. Exact expectations and observed values are archived.

This result supports retaining the existing E1 limitation. It does not require a DI callback rebinding layer or a package-wide PHP-floor increase. The library retains `^8.4`; the native expression syntax itself requires 8.5. Before widening support, test a qualified released runtime against the complete native acceptance fixture, record binary/source provenance and verify all affected forms. The [E1 plan](../plans/php85-callbacks.md) records that process. Its historical source-only observations are preserved with their original identity. The fresh binary's release archive checksum is separately verified in the build records; the semantic probe's own provenance flags are not silently changed to true.

## 7. Delivery and remaining limits

| Commit | Completed unit |
|---|---|
| `fb3f8e0` | Russian README native-lazy eligibility and migration guidance |
| `122e240` | Single-parameter defaults and late-attribute contract tests |
| `5067bf7` | Invokable constructor DI-exception preservation, tests and docs |
| `aa22449` | Malformed request-transport diagnostic and recovery tests |
| `ce0c0a6` | Nonpublic-constructor proxy correction, tests and docs |
| `eefedd0` | Full-PHPStan-compatible trait fixture and final-byte proof |
| `03454ba` | Factory-owner identity and setup capability ordering tests |
| `5365677` | Setup Env default and explicit Config descriptor tests |
| `42b62af` | Benchmark runtime-series collision prevention and reproduction guidance |

The report/evidence commit is documentation-only. The [evidence index](evidence/2026-11-16-round5/README.md) explains archive contents, exact source identities and excluded intermediate harness attempts.

Remaining limits are concrete: E1 still fails on the tested native runtime; B2 needs workload and contract decisions; mutation survivors include unexecuted extension/helper hypotheses; local/Windows SQLite coverage comes from Linux CI; historical artifacts were checked by role rather than rerunning every old experiment. These limits remain visible alongside the completed fixes and fresh verification.
