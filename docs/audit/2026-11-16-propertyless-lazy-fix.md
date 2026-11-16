# D1/A completion: native lazy targets without backed instance state

**Status: D1/A implemented, verified and closed.** The lifecycle defect identified by the [fourth DI 6 audit](2026-11-15-di6-full-reaudit-round4.md) is corrected by [commit `299ee93f6be6d91e3a4fc1372389f1fff953d61b`](https://github.com/componenta/di/commit/299ee93f6be6d91e3a4fc1372389f1fff953d61b) on `refactor/di6-definitions-callbacks`. The instruction to finish the recommended repair accepted option D1/A: reject unsupported native laziness before object allocation. [Русская версия](2026-11-16-propertyless-lazy-fix.ru.md).

This report closes that specific audit item. It records the implementation, its compatibility boundary, direct and integration regression proof, and current validation. It does not relabel the preceding full-library audit as a new audit. Its baseline is `fe435db72d60fc3b042f1a5def815bf1fdb91980`; historical audit reports and observation evidence are unchanged. The report date is November 16 in Europe/Kaliningrad; raw execution timestamps remain UTC.

## 1. Defect, consequence and chosen repair

For a native-eligible class without a backed instance property, PHP's native ghost/proxy allocation can return an ordinary object without ever invoking the supplied initializer or backing factory. Empty classes, static-only classes and virtual-hook-only classes all exhibit this behavior. The original [observations](2026-11-15-propertyless-lazy-evidence.json) and [decision record](../plans/propertyless-lazy-lifecycle.md) explain the native behavior and the alternatives.

Through DI, successful resolution could therefore publish a shared object whose constructor, `SetUp` hooks, or explicit `Make` product factory never ran. `NoConstructor` does not justify losing setup: it suppresses only the constructor. Application initialization or validation placed in those callbacks could be skipped, although this audit did not establish an application-specific exploit.

The default [`ProxyFactory`](../../src/ProxyFactory.php) now checks the class before calling `newLazyGhost()` or `newLazyProxy()`. An otherwise native-eligible class without a backed instance property raises `ResolutionException` with the canonical class in `serviceId`, the requested strategy in its message, and no fabricated previous exception:

```text
Cannot create native lazy ghost for class "Example": no backed instance properties; use eager construction instead.
```

The proxy message substitutes `virtual proxy`. No target placeholder is allocated, so the adapter cannot trigger an extra target destructor or silently lose its callback. Repeated failed resolution does not publish an incomplete shared value. Class autoload and extension effects that occurred before this adapter boundary are not rolled back.

An immediate eager fallback was not selected. It would change deferred callback and error timing, while an already allocated ordinary proxy placeholder can introduce an additional destructor. The accepted approach resolves the lifecycle failure locally without imposing a new global policy on custom factory implementations.

## 2. Implementation and architecture boundary

There is one executable production change: 41 added lines in `src/ProxyFactory.php`. Both entry points share one private metadata check. Four additional source files change PHPDoc only: the two lazy-factory interfaces and the `Lazy`/`Proxy` attributes. Token comparisons on both local runtimes confirm that those four files have unchanged executable tokens. No public signature, container registry, strategy, feature flag or cache was added.

| Boundary | Implemented behavior |
| --- | --- |
| Property capability | A non-static, non-virtual instance property qualifies. Readonly, promoted and backed-hook properties remain eligible. |
| Inheritance | Inspect the complete parent chain, including private properties missing from the leaf reflection. A backed property inherited only from a grandparent remains supported. |
| `stdClass` | Empty `stdClass` and empty descendants are rejected; a user descendant with a backed instance property remains supported. Potential future dynamic properties do not provide a native lazy slot. |
| Other native restrictions | Missing classes retain reflection-construction failure. Interfaces, traits, abstract classes, enums and unsupported internal ancestry retain native allocation failure and its existing DI normalization with native `previous`. A backed property does not override unsupported internal ancestry. |
| Constructor visibility | Do not use `isInstantiable()` as an eligibility shortcut. Private/protected constructors alone do not prohibit supported native lazy allocation. |
| Metadata inspection | Do not read property values, invoke hooks, construct an object, or evaluate custom attribute arguments. |
| Supported callbacks | Preserve deferred invocation, callback exception identity, retry behavior and native ghost/proxy identity. |
| Custom factory | Keep delegation to the selected `ProxyFactoryInterface`. The new restriction belongs to the default native adapter. |

The property scan runs on a request for a new native wrapper. It does not run on every shared `get()` hit or property access. Once a backed property is found, repeated property scans stop, while the ancestor walk still checks for unsupported internal ancestry. No numerical latency or memory improvement is claimed; the benefit is lifecycle correctness and a diagnosable capability boundary. Introducing a persistent eligibility cache without a demonstrated need would add state and invalidation concerns to a small metadata check.

Diagnostic fields contain the target and strategy, not callback or service objects. PHP exception traces remain a separate ownership mechanism: with `zend.exception_ignore_args=0`, retaining an exception can retain callback arguments. The collection regressions therefore set `zend.exception_ignore_args=1`, restore its prior value afterward, and test capture release while the exception remains retained. This change does not sanitize PHP traces.

## 3. Regression proof

Two new contract files contribute **75 cases and 515 assertions per runtime**. Existing test cases were not removed or rewritten. Comparison of the complete JUnit case identities confirms 75 additions, zero removals, and no assertion-count or result changes among the 1,545 existing cases on either local runtime. Tests' ordering and execution times are deliberately excluded from that comparison.

| New contract | Cases | Assertions after repair | Public behavior covered |
| --- | ---: | ---: | --- |
| [`NativeLazyEligibilityContractTest.php`](../../tests/Contract/NativeLazyEligibilityContractTest.php) | 64 | 417 | Direct factory and container requests; unsupported layouts; aliases; inherited private state; backed hooks; readonly/promoted state; constructor visibility; native error precedence; callback failure/retry; retained-exception capture lifetime |
| [`PropertylessLazyLifecycleContractTest.php`](../../tests/Contract/PropertylessLazyLifecycleContractTest.php) | 11 | 98 | Attributed object lifecycle with/without `NoConstructor`; `Make` plus `Proxy` on parameters and properties; recovery through ordinary eager definitions/factories; backed lifecycle controls; custom-adapter delegation |

Both suites were first run against the implementation baseline in independent checkouts with their own vendor/autoload copies. Only the production patch to `ProxyFactory.php` was then applied. Loaded-class origins were checked inside each process: five classes and seven functions for the direct proof, and six classes for the integration proof. Source fingerprints before and after every run match.

| Suite, repeated on PHP 8.4.23 and 8.5.8 | Before production repair | After production repair |
| --- | --- | --- |
| Direct, final published test bytes | 28 failed, 36 passed; 273 assertions | 64 passed; 417 assertions |
| Integration, final published test bytes | 6 failed, 5 passed; 32 assertions | 11 passed; 98 assertions |
| Combined per runtime | **34 failed, 41 passed** | **75 passed, 515 assertions** |

All 34 expected baseline failures are missing-rejection failures. They are separate cases for one defect, not 34 independent bugs. Existing-behavior controls pass before and after the repair. Full outputs, source-origin checks, hashes, exact test patches and commands are retained in the [evidence directory](evidence/2026-11-16-propertyless-lazy/README.md).

The integration controls verify that supported Lazy/Proxy services still defer their lifecycle, execute the expected constructor/setup sequence once, and destroy the backing service once. Rejected resolution can be followed by a successful explicit `ClassDefinition` for the same identifier; the definition obeys its own eager contract and does not execute DI attributes. An ordinary product factory also remains callable after an explicit `Make`/`Proxy` rejection. The custom-factory case proves delegation and callback/return identity using a recording double; it is not presented as a complete alternative production lazy adapter.

One intermediate fixture declared a trait used only by reflection; full configured PHPStan correctly reported `trait.unused`. The final fixture reuses its exercised lifecycle trait, without suppressions. The final test bytes were rerun through both baseline RED and fixed GREEN. Earlier candidate evidence is labeled as historical provenance; it is not substituted for final acceptance.

## 4. Complete validation and provenance

The final local source/test fingerprint is `f517fbbacdcd1e380d9492d8b2c1496801cbeb8716d3e01531f356a927af2e3e` over 418 files: 169 source files and 249 test files. Every byte was compared with the published fix commit. The local logs accurately retain precommit HEAD `fe435db` plus the uncommitted repair; their HEAD fields were not rewritten to make the runs appear postcommit.

| Final local gate | Result |
| --- | --- |
| PHP 8.4.23, randomized order, seed `2026111602`, CLI OPcache off | **1,602 passed / 18 skipped / 24,774 assertions** |
| PHP 8.5.8, reverse order, CLI OPcache on, JIT disabled | **1,619 passed / 1 skipped / 24,881 assertions** |
| Configured PHPStan maximum level | **No errors**. The optional turbo extension cannot load in this local binary; the supported analysis fallback completed with exit 0. |
| PHP CS Fixer dry run, no result cache | **0 of 413 files require changes** |

The exact fix commit is also validated by [GitHub Actions run `43774753909`](https://github.com/componenta/di/actions/runs/43774753909), push attempt 1:

| CI platform | Runtime reported by CI | Passed | Skipped | Assertions | Style / PHPStan |
| --- | --- | ---: | ---: | ---: | --- |
| Linux | PHP 8.4.27 | 1,602 | 18 | 24,774 | Pass / pass |
| Linux | PHP 8.5.12 | 1,619 | 1 | 24,881 | Pass / pass |
| Windows | PHP 8.4.27 | 1,601 | 19 | 24,772 | Pass / pass |
| Windows | PHP 8.5.12 | 1,618 | 2 | 24,879 | Pass / pass |

The run completed with **success on all five required jobs**. Its coverage job executed the full PHP 8.4 suite successfully, enforced the existing coverage minimum of 80%, and completed the mutation minimum of 85% without changing either threshold.

| Current exact-commit measurement | Result |
| --- | --- |
| Coverage console total | **92.5%**; the formatter truncates to one decimal place |
| Clover executable statements | **4,486 / 4,846 = 92.57%**, across 169 source files |
| Clover methods | **441 / 558 = 79.03%**; this is a separate metric, not the 80% statement-coverage gate |
| `ProxyFactory.php` | **35 / 35 statements and 3 / 3 methods covered** |
| Generated mutations | **4,274**, in 115 files with generated mutations |
| Mutation results | **3,743 tested / 54 timeout / 477 untested** |
| Reported MSI | **88.84%** = `(3,743 + 54) / 4,274 × 100` |
| Completed tested results divided by all mutations | **87.58%** = `3,743 / 4,274 × 100` |

The mutation engine's `tested` result records a completed mutant subprocess returning nonzero. A timeout is a separate outcome; the plugin includes it in the reported score's numerator and it remains in the total denominator. The 54 timeouts are therefore visible rather than silently treated as completed behavioral evidence. Clover reports statement/method coverage here; its zero conditional counters do not establish branch coverage.

The complete log contains 477 unique `UNTESTED` IDs. Exactly two concern the changed `ProxyFactory.php`, both on added lines:

| Mutant | Static interpretation of the preserved raw diff |
| --- | --- |
| Line 70, `ContinueToBreak`, `e999ec54f589c95c` | Runs only after a backed property has been found. Ending the ancestor scan early still proceeds to native allocation; PHP continues to reject unsupported internal ancestry through the same error-normalization boundary. The capability outcome is unchanged. |
| Line 77, `BreakToContinue`, `64ba76f19854225e` | Continues inspecting property metadata after setting the backed-property flag to true. No later branch resets the flag or invokes application code, so the public capability result is unchanged. |

These two mutants appear behaviorally equivalent by static inspection of the current code. Their metadata traversal cost can differ; no timing invariant is asserted. They are not evidence of an uncovered public lifecycle outcome, and private loop-counter assertions were not added merely to change the score. This is a static inference, not a separately executed equivalence proof. The [survivor record](evidence/2026-11-16-propertyless-lazy/proxyfactory-survivors.json) preserves both original diffs and this qualification.

The [CI record](evidence/2026-11-16-propertyless-lazy/ci.json), five full compressed logs, original coverage ZIP and complete Clover XML are archived. The ZIP SHA-256 matches GitHub's artifact digest, and extraction reproduces the archived XML byte-for-byte. A [second API read](evidence/2026-11-16-propertyless-lazy/ci-independent-review.json) independently confirms the successful run, its exact head and all five job conclusions.

The conditional manually requested benchmark job is outside this push's required gates. No new benchmark claim is inferred from its skip. Platform runtime versions are recorded observations and are distinct from the local binaries.

The production patch, direct and integration tests were also reviewed independently against R1/R2 and P1–P5 in the accepted plan. Those reviews found no further concrete correction. The code commit was pushed before this report; the report/evidence commit changes documentation and evidence only. Source, test and workflow subtrees remain those validated at `299ee93`.

## 5. Migration for affected applications

The compatibility change is deliberate: a previously successful but uninitialized native-lazy result now fails immediately with a specific DI error. Choose the initialization path that the application actually needs:

1. **Ordinary eager reflection creation:** remove the relevant class or injection-point Lazy/Proxy marker. The normal reflection pipeline still performs its property injection and setup. `NoConstructor`, when present, retains its constructor-only suppression. Switching from `get()` to `make()` alone does not disable attributes.
2. **An explicit ordinary factory:** initialize the returned product inside that factory. The container does not reinterpret the opaque product's class attributes afterward; a class-level `Proxy` marker alone does not wrap it.
3. **An explicit `ClassDefinition`:** use the constructor and ordered `call()` instructions. The definition intentionally ignores DI attributes, including `SetUp`, `Inject`, `Lazy`, `Proxy` and `NoConstructor`. Move required setup into explicit calls; type-based fallback remains opt-in.
4. **A custom proxy factory:** use an independently tested implementation that supports the target and preserves the existing deferred-callback contracts. The default adapter's check is not imposed globally.

For example, a required public `initialize()` method can be configured explicitly:

```php
use Componenta\DI\Definition\ClassDefinition;

$container->set(
    Service::class,
    ClassDefinition::create(Service::class)->call('initialize'),
);
```

Supply any constructor/method arguments using the existing definition API. Adding a meaningless property solely to trigger native laziness is not the recommended migration.

## 6. Closure and remaining scope

D1/A's chosen behavior is implemented, its public contracts are documented, and its new regression cases prove the failure before the repair and the intended behavior afterward. All five required CI jobs completed successfully on the exact fix commit. No D1 implementation decision or validation gate remains open.

The separate conditional B2 performance design, possible future DTO work and feature-specific E1 native-callable limitation retain their existing scopes. This change does not turn them into prerequisites for this repair. DI 6 has not been released or merged into the release line by this task.
