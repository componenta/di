# DI v6: third full re-audit

Date: 15 November 2026. Repository: `componenta/di`. Branch: `refactor/di6-definitions-callbacks`.

Reviewed code: [`19584c03f77036045184d5eef4b992a9e86aaad6`](https://github.com/componenta/di/commit/19584c03f77036045184d5eef4b992a9e86aaad6). Baseline: [`2eb36241787a971e1a8185149aaa08b90304d426`](https://github.com/componenta/di/commit/2eb36241787a971e1a8185149aaa08b90304d426). Final CI: [run 43715494696](https://github.com/componenta/di/actions/runs/43715494696).

This is the English companion to the [detailed Russian report](2026-11-15-di6-full-reaudit-round3.ru.md). The [scope manifest](2026-11-15-di6-full-reaudit-round3-scope.json) contains individual file/test reviews and survivor decisions. The [evidence bundle](2026-11-15-di6-full-reaudit-round3-evidence.json) preserves commands, outputs, source origins, exact proofs and CI results.

## Outcome and scope

The entire executable codebase was freshly read: **444 unique files**, comprising 169 production PHP files, 233 test/support PHP files, 10 native fixtures, six benchmark files, 18 reproduction scripts and eight configuration files. The current AST contains **762 Pest declarations**, expanded to **1528 runtime cases** per local PHP version. The baseline had 747 declarations and 1467 cases; this pass adds 15 declarations and retains the original variants of strengthened tests.

Confirmed fixes concern the benchmark median, Windows portability of two audit CLI entry points, and two incorrect production PHPDoc contracts. No new executable production defect was confirmed in `src/`. All 169 production token streams, excluding comments and whitespace, match the baseline on both PHP 8.4 and PHP 8.5. Test improvements therefore establish previously weakly observed contracts rather than claim runtime bugs in the current container.

Every current test has an individual contract, actual oracle, distinguishable counterexample and limitation. **37 current declarations have newly executed meaningful Pest RED/GREEN evidence.** This is not a claim that all 762 declarations were independently mutation-validated. Two separate PHPDoc defects also have actual PHPStan RED/GREEN proofs.

Meaningful test proofs retain four phases: old tests/original source GREEN, old tests/exact mutant GREEN, improved tests/same mutant RED, improved tests/restored source GREEN. The bundle records which controls ran a whole test file and which actually ran the full baseline suite. Composer classmaps, eager DI functions, loaded Reflection paths, test bytes and Pest roots were checked. Setup failures, fixture errors, timeout classifications and message-only differences are not presented as semantic RED.

After deduplicating shared controls, the canonical local census contains 408 phase outputs (208 on PHP 8.4 and 200 on PHP 8.5), plus two Windows CI RED/GREEN pairs. They cover 57 baseline catalogue mutants, one bespoke semantic trait-consumer mutant and three actual tool defects. The archive preserves 65 exact test-path/SHA versions and all 240 original test/support/fixture files for the six actual full-baseline-suite controls. Assertions from separate runs are not summed.

## Confirmed fixes

### Even-round median

`WorkloadNecessity::median()` selected the upper middle sample for even counts. A full JSON-report regression supplies deterministic namespace-local clock ticks and checks the actual samples and independent medians for one, two and three rounds. Samples 100 and 300 now produce 200. The original even-round case failed on both supported PHP versions; all three cases passed after [`b687d521`](https://github.com/componenta/di/commit/b687d521ad57e0b0e2f7ad853838dec50f466b68).

Published historical results from this worker used five rounds, so the previous measurements were not retroactively changed or presented as new runs.

### Windows source-origin comparisons

Native Windows `realpath()` separators were compared with forward-slash prefixes in `autoload-selected-source.php`. The defect affected classmap remapping, eager-function remapping, exact Reflection prechecks and both final provenance loops in `WorkloadNecessity.php`. Actual Windows CI on `141feff3` failed the three unchanged full-report regression cases. All passed after [`e4ba52e`](https://github.com/componenta/di/commit/e4ba52e790f038aceec3f2bac9df68215c1abed9).

All relevant paths now use consistent native-separator normalization. Strict origin equality and directory boundaries remain enforced. Independent local controls loaded 135 declarations and six functions from a separate selected source copy, while a preloaded other source remained rejected. No guard was removed and no Windows test was skipped.

The documented portable `AttributeAvailabilityObservations.php` had the same defect. A new public CLI test was published first in [`fddf029`](https://github.com/componenta/di/commit/fddf029c272b836b82ff0548a8e12343ca2f6d01); both default-path and explicit-matching-path cases failed on actual Windows with the original script. The minimal fix normalizes both exact prechecks and the final declaration loop. The unchanged test verifies successful JSON observations 0 → 1 across resolutions, selected-source identity, two constructors and one handler. Final CI supplies Windows GREEN.

Historical POSIX/checkout-layout benchmark protocols were not silently broadened into general-purpose cross-platform tools.

### Generic `ObjectPipeline` reflection inputs

The PHPDoc input `ReflectionClass<object>` incorrectly rejected a concrete `ReflectionClass<Concrete>` at maximum PHPStan level. Public `prepare`, `canCreate`, `constructorTargets`, `create`, and the common metadata method now use a method-level `T of object` and `class-string<T>|ReflectionClass<T>`. The exact same caller bytes fail before the change and pass afterward. Runtime tokens are unchanged. Fix: [`3ca6ca3`](https://github.com/componenta/di/commit/3ca6ca3ac987ebc71ddedcfdc7fa6f6696941f85).

### In-place configuration typing

`DependencyConfiguration::assertShape()` canonicalizes its by-reference array, including invokable definitions and scalar delegators. Its old `@phpstan-assert DependencyShape` incorrectly described this as narrowing an already compatible value, producing `staticMethod.impossibleType` for valid literal inputs.

The false assertion was removed and the in-place operation documented. `normalize()` remains the API returning a statically typed `DependencyShape`. A naive `@param-out` replacement was tested and rejected by PHPStan because the current helpers receive intermediate, partially canonicalized arrays by reference. No ignore or false helper annotation was added. Fix: [`cc8ee82`](https://github.com/componenta/di/commit/cc8ee82b65d3b066e3d02af148d832f848d2bfdc). See the official [PHPDoc guidance](https://phpstan.org/writing-php-code/phpdocs-basics) and [by-reference output checking](https://phpstan.org/blog/enhancements-in-handling-parameters-passed-by-reference).

## Strengthened runtime contracts

The new semantic proofs cover exactly-two attribute ambiguity, registry revision ABA, public policy list shape, class/method and inherited class/property identity boundaries, source-handler continuation after metadata/transformers, promoted-member discovery, invocation-only and Proxy composition rules, phase graph branches and late bootstrap definition identity.

Callable and parameter improvements cover native magic `__invoke` spelling, class/method classification cache separation, service-method diagnostics, first semantic callback position/type metadata, positional null precedence, later union arms, key/type agreement, variadic collection keys after a fixed prefix and the native optional-omission/variadic rebinding hazard.

Request tests now observe a null parsed body, unique fields after an accepted duplicate, declared source precedence with selected request attributes, exclusion of unselected attributes and continuation past the canonical HTTP transport type. State/admission tests cover nested main/Fiber stacks, cache-publication ABA, numeric-looking string IDs, valid-first/invalid-later configuration entries, ineligible entry kinds, a valid concrete trait consumer and the supplied standalone ProxyFactory.

Two initial improvements had replaced old variants. Root review restored the original inline-decorator and pure-variadic cases alongside the additions in [`4757867`](https://github.com/componenta/di/commit/4757867c9c820ca1497a77ae721b7826372ab4ad), then repeated proofs on final bytes. Three alias-shape kills based solely on alternate exception wording were excluded. An intermediate unused trait fixture failed whole PHPStan CI; it was repaired with a concrete positive consumer and the failure retained as audit evidence, not relabelled as an original production defect.

## Final validation

| Gate | Result |
|---|---|
| Local PHP 8.4.23, CLI OPcache on, JIT buffer 0 | 1510 passed, 18 skipped, 24189 assertions |
| Local PHP 8.5.8, CLI OPcache off | 1527 passed, 1 skipped, 24296 assertions |
| PHPStan maximum level, full `src tests` | GREEN |
| CS Fixer full configured scope and changed reproduction scripts | GREEN |
| Composer metadata validation | `validate --strict` GREEN |
| Fresh installed dependency audit | No advisory or abandoned-package entries; graph/hash preserved |
| MutationParity | Each PHP: 1200 operations / 28800 comparisons against fresh replay, exit 0 |
| CI Linux PHP 8.4 | PHP 8.4.27: 1510 passed, 18 skipped, 24189 assertions |
| CI Linux PHP 8.5 | PHP 8.5.12: 1527 passed, 1 skipped, 24296 assertions |
| CI Windows PHP 8.4 | PHP 8.4.27: 1509 passed, 19 skipped, 24187 assertions |
| Coverage | Pest Total: 92.0% (minimum 80%); Clover: 4441/4823 statements |
| Mutation testing | 88.42% MSI (minimum 85%); 4248 total, 3699 tested, 57 timeouts, 492 untested |
| Benchmark smoke | Four standard entry points passed on both Linux PHP versions |

Coverage and mutation percentages apply to `src/` under the repository configuration. The corrected reproduction scripts have separate public CLI regressions. The archive also preserves the exact Clover XML and ZIP, with the ZIP digest verified against GitHub artifact metadata.

Platform skips remain explicit: native PHP 8.5 fixtures on PHP 8.4, one contextual-union compiler boundary on PHP 8.5, and the PDO witness where SQLite is unavailable. A skip is not a mutant kill. The engine includes 57 timeouts in MSI. Completed kills alone represent 87.08% of all mutations (3699/4248); a timeout is not a semantic RED proof. The previous published run had 87.01% MSI, 4248 mutations, 3640 tested, 56 timeouts and 552 untested. Those historical numbers are not presented as the current result.

All 492 final `UNTESTED` entries reconcile to the baseline catalogue: 462 retain their IDs and 30 are exactly reidentified after PHPDoc line shifts in `DependencyConfiguration` and `ObjectPipeline`. There are no ambiguous or new unmatched entries. All 57 catalogue mutants with new semantic witnesses are absent from final `UNTESTED`; three additional baseline entries also disappeared but are not promoted into the 57 individually proved faults. Absence from the survivor list alone does not identify an individual mutant as `tested` rather than `timeout`.

## Security and performance conclusions

No new exploitable vulnerability was confirmed. The review examined request authority and type provenance, configuration/admission boundaries, service identifiers, autoload/reentrancy, detached diagnostics, lazy/proxy lifetime and source selection in tools. This is not an application sandbox assessment: trusted constructors, callbacks, rules and autoloaders can execute arbitrary application PHP. Native Throwable traces and `previous` retention follow PHP behavior; detached DI fields do not sanitize the complete exception chain or roll back arbitrary side effects.

The deep-security-scan instructions were read, but its required Codex Security server tool was unavailable. No automated Codex Security scan is claimed. Manual review, concrete reproductions, static analysis, dependency audit and tests are the actual evidence.

No throughput or memory improvement is claimed because production executable code did not change. Cache ownership/revision, stale publication, retention, request-specific recomputation and benchmark measurement boundaries were reviewed. Smoke checks are not a new application ABBA campaign. Cache-reuse survivors are not called performance-equivalent without measurement, and private cache counters or arbitrary timing thresholds were not introduced merely to raise MSI.

## Architecture decisions and implementation plans

### Supported low-level API surface

Public `InstanceCreator` wrappers, the eager-validation meaning of `AttributeProcessor::prepare()` and protected `ExtractsRequestData::extractSharedData()` have uncertain downstream contracts. Absence of in-repository callers is not proof of external dead code. Tests directly pinning these surfaces would themselves create a compatibility promise.

First inventory published and downstream consumers. Then choose supported standalone APIs with documented behavior and public-contract tests, or implementation APIs with explicit boundaries and a compatible deprecation transition. Tests for a supported creator must cover constructorless/argument-bearing creation, initialization, omitted/supplied callbacks, callback timing and errors. Processor preparation must specify validation versus optional warmup. Mapper helpers should be exercised through a real subclass's public extraction method.

The benefit is a smaller set of ambiguous promises and maintainable extension contracts. Costs are consumer research, compatibility commitments and possible migration. There is no measured runtime gain from removing unused wrappers. No API was removed, made private or redesigned in this audit.

### Precise by-reference canonicalization

Keep the present compatible solution and use typed `normalize()`, or design typed intermediate section results and assemble a final `DependencyShape`. Before the latter, decide whether a failure may leave partial mutation or must publish atomically on success. This is an observable semantic decision.

Acceptance tests must cover invokable/scalar delegator canonicalization, numeric IDs, retained resolver priorities, empty sections, late-invalid sections, the original array after failure, aliases/cycles/defaults and identity. Measure representative large configuration time and peak memory only if the redesign is chosen. Benefits are precise downstream typing and clearer boundaries; risks are copying costs, changed failure state and overlapping APIs.

### Mutation evidence policy

All **552 baseline survivors** were individually classified against exact source/diff provenance. The baseline classification is 172 meaningful candidates, 179 equivalent-under-contract, 96 redundant paths, 46 defensive/outside-supported-input, 54 needs-decision and 5 diagnostic-specificity-only. Of the 172 meaningful candidates, 57 have a newly executed witness and 115 remain reasoned/unexecuted. These are baseline classifications, not substituted counts for the final CI survivors. In the core domain alone, 67 meaningful candidates remain reasoned and unexecuted; they are not confirmed production bugs or completed RED proofs.

Keep engine outcomes, timeouts, completed-kill fraction, semantic proofs and undecided API contracts separate. Resolve supported boundaries before writing tests solely to change a score. A useful test should demonstrate loss of result, identity, authority, lifetime, ordering or structured diagnostic information.

### Feature-local native PHP 8.5 E1 gate

The separate pure-engine matrix on available PHP 8.5.8 passed two of ten required E1 cases (`named_public_default`, `attribute_inline`); eight trait scope/visibility cases failed. Release/source provenance of that binary remains unverified. A verified runtime and 10/10 matrix are required before enabling the dependent native form, followed by integration/lifetime checks. This remains local to that feature and does not change the package requirement from `^8.4` or block unrelated DI v6 paths.

## Completion boundary

Code and test changes are published in the requested branch. Later audit-only commits add reports and evidence without changing the validated code bytes. This report does not claim exhaustive proof of all schedules, individual RED for all 762 declarations, safety of untrusted PHP, performance equivalence of every survivor, support for native E1 on every PHP 8.5 release, or execution of an unavailable automated security scanner.

Full raw evidence archive: [2026-11-15-di6-full-reaudit-round3-evidence.tar.gz](2026-11-15-di6-full-reaudit-round3-evidence.tar.gz). SHA-256: `45234a65680731de407fc1c29f3b9133cd89b36a6c28450853ca260ba07bcd6f`. It contains 1916 files; round-trip extraction and every manifest hash were verified.
