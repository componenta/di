# DI full recheck and v5.0.6 release audit — 2026-11-14

[Русская версия](2026-11-14-release-audit.ru.md)

## Scope and outcome

The recheck started from `main` at `1a1a0a8772eaabc2a80016744b123ebcd98975ca`; the last published release was `v5.0.5` at `a56f3d70dd56bdd62947b6c322aa878e8a76ef4c`. Independent code and test reviews covered entry selection, definitions, aliases, shared caches, deferred factories/delegators, external containers, prepared and native callable execution, parameter resolution, attribute composition and phases, HTTP mapping, exceptions, object lifetime and Fiber/reentrant mutation behavior.

Four additional defects were reproduced with failing public-behavior tests, corrected without changing architecture, and pushed in separate commits. The existing seven post-release runtime fixes remain included. The combined release is a compatible patch: PHP `^8.4`, dependency constraints and the supported public API are unchanged. See the complete [v5.0.6 notes](../releases/v5.0.6.md).

## New defects and TDD evidence

| Commit | Failure and correction | Regression evidence |
| --- | --- | --- |
| [`4276091`](https://github.com/componenta/di/commit/4276091) | Native callable preparation leaked foreign autoload failures outside the DI exception boundary. Normalize preparation failures with their original cause; leave callable-body exceptions unchanged. | [NativeCallableInvokerContractTest](../../tests/Contract/NativeCallableInvokerContractTest.php): 2 failing pre-fix cases; native-body controls preserved. |
| [`bfc2968`](https://github.com/componenta/di/commit/bfc2968) | External ownership queries could mutate aliases after `has()` selected its route. Refresh that route and enforce protected-target behavior on the resulting ID. | [ExternalLookupMutationContractTest](../../tests/Contract/ExternalLookupMutationContractTest.php): 8 initial failing cases plus a protected-target case; final file 13 tests / 69 assertions. |
| [`5fb06b7`](https://github.com/componenta/di/commit/5fb06b7) | A later metadata autoload could reveal a source skipped earlier while HTTP mapping still dispatched conflicting raw data to a DTO factory. Reinspect loaded declarations before dispatch without additional autoload attempts. | [RequestSourceDiscoveryContractTest](../../tests/Contract/RequestSourceDiscoveryContractTest.php): 8 failing cases across cold/warm, cached/non-memoized, same/later parameter; with fallback-discovery controls, 10 tests / 86 assertions on both runtimes. |
| [`6a44868`](https://github.com/componenta/di/commit/6a44868) | A reentrant external `has()`/`get()` could replace a deferred factory while the outer resolution subsequently read the new binding's validation flag. Capture definition and flag before owner lookup. | [DeferredFactoryRecoveryContractTest](../../tests/Contract/DeferredFactoryRecoveryContractTest.php): 8 failing cases; final file 12 tests / 94 assertions on both runtimes. |

The source-discovery regression verifies that the factory is never entered for conflicting data. The deferred-factory regression distinguishes the in-flight outer registration from a committed nested replacement. These tests exercise behavior through public entry points rather than asserting private array layout.

## Verification

| Check | PHP 8.4.23 | PHP 8.5.8 |
| --- | --- | --- |
| Full suite | 1,168 passed; 2 PHP-8.5-only skips; 22,731 assertions | 1,170 passed; 22,739 assertions |
| Warm/fresh mutation-history parity | 600 operations; 14,400 comparisons | 600 operations; 14,400 comparisons |
| PHPStan maximum level | No errors | Covered by the shared source-analysis gate |
| Formatting | 368 files; no changes required | PHP 8.4-compatible source retained |

Composer strict validation passed; dependency audit reported no advisories or abandoned packages. PHPStan completed using its PHP implementation because the optional turbo extension cannot load in the static CLI runtime. This startup limitation did not prevent analysis.

The local vendor graph uses workspace/path manifests for Config 3.0.0, Reflection 2.0.1 and Validation 3.0.1. Their source references (`dcd2189`, `62aca657`, `8f88878`) match the retained published-package release-check installation. A fresh published-dependency installation belongs to the hosted release gate below and is not claimed as a completed local check.

The [evidence JSON](2026-11-14-release-evidence.json) records exact source identities and fingerprints, runtime versions, commands and results. The parity diagnostic compares returned values after a history of runtime mutations and fails on unexpected exceptions. Dedicated contract tests separately verify exception ownership and shared identity. Its comparison count is not a mutation-testing score, and passing this finite suite is not proof over arbitrary extension side effects.

**Hosted release gates are pending at report preparation.** The [Release workflow](../../.github/workflows/release.yml) must check the exact release commit with published dependencies on Linux PHP 8.4/8.5 and Windows PHP 8.4, then pass coverage ≥80% and mutation score ≥85% before publishing `v5.0.6`. Prior hosted coverage/mutation results are historical evidence only and are not reused as new results.

## Included earlier fixes and excluded false positives

The [November 13 recheck](2026-11-13-full-library-recheck.md) describes the seven runtime commits between `v5.0.5` and the starting revision: `4621f9e`, `fedf953`, `5fe8cc0`, `7da5163`, `eb7818d`, `fce3f17`, `7dc88a4`. They correct alias routing, parameter discovery/execution, nested external dependency discovery, fallback class/source availability, consumed-plan tracking and native callable cache identity. `71a8430` fixes selected-source benchmark loading.

The current review found no additional confirmed local defect in the remaining audited paths. Shared `get()` versus fresh `make()`, explicit `ClassDefinition` lifecycle, unsupported by-reference binding, and native callback static-state differences remain intentional contracts. Cold/warm and non-memoized controls preserve current inputs, source conflicts and exception boundaries; metadata caching must not substitute runtime dependency values.

## Architecture decisions carried into the new branch

The [definition ownership specification](../plans/definition-owner-lifecycle.md) is implementation-ready for the next major. Its central immutable binding table and internal retirement barrier address three still-reproducible 5.x failures: retained obsolete factory configuration after changing owner type; divergent shared/fresh behavior after a throwing retired-factory destructor; and incomplete alias-family invalidation after a throwing cached-wrapper destructor. None is claimed fixed in `5.0.6`.

The [callback specification](../plans/php85-callbacks.md) also has ready contracts for the shared first-argument binder, `Transform`, callback `SetUp` and simple-union selection. Library implementation can proceed independently of E1. E1 still blocks the final supported PHP patch floor and the affected trait first-class-reference guarantee: the reviewed stable `8.5.12` source lacks the correction, and `8.5.13RC1` is not a stable release. The plan owns the bounded source/binary verification required to lift that gate.

The [availability performance plan](../plans/attribute-availability-performance.md) does not yet authorize B2 production implementation: application cost evidence and its synchronization contract remain required. Prototype A changes observable autoload behavior; C is not selected. The ownership work is justified by correctness and lifetime failures, not an unmeasured performance promise. All three plans retain their full Russian translations.

The requested sequence is therefore: publish the verified compatible patch through its release gates, then create the separate refactoring branch and implement only the ready plan slices with regression coverage and convergence review. No next-major API is included in this release audit.
