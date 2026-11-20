# Configuration canonicalization: output types and failure state

[Русская версия](configuration-normalization-contract.ru.md)

**Status, 2026-11-20:** conditional architecture proposal; no runtime redesign selected. Retain the compatible API: `assertShape()` canonicalizes by reference, while `normalize()` returns a typed `DependencyShape`. The incorrect narrowing annotation was already fixed. Caller-section ownership in value normalization was also fixed later and is not an outstanding defect in this plan. Source reference: [`43c4641`](https://github.com/componenta/di/tree/43c464187f80c95802fc36191350ae68b70a469e).

## Problem and existing corrections

[`DependencyConfiguration`](../../src/Configuration/DependencyConfiguration.php) validates section shapes and canonicalizes invokables, aliases and delegators. Its direct `assertShape(array &$dependencies): void` API changes the supplied array in place. It can finish an earlier section and then throw on a later section. Treating the whole operation as a pure assertion or promising atomic publication would misdescribe that behavior.

The [third-audit plan](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round3.ru.md#6-решения-по-архитектуре-и-план-действий) identified two distinct matters:

- The false `@phpstan-assert DependencyShape` annotation caused incorrect static narrowing for valid literal inputs. It was removed in [`cc8ee82`](https://github.com/componenta/di/commit/cc8ee82b65d3b066e3d02af148d832f848d2bfdc); the documented typed result is supplied by `normalize()`.
- A more precise by-reference output type would require correctly typed intermediate results and an explicit failure-state policy. Simply replacing the annotation with `@param-out`, or pretending an intermediate array already has the final type, did not establish that proof. This broader redesign was left open.

Later correction [`c4ca540`](https://github.com/componenta/di/commit/c4ca54028a6341efe14cc2405cc31eee081de23e) detached top-level referenced section slots before value normalization. [`DependencyNormalizationOwnershipContractTest`](../../tests/Contract/DependencyNormalizationOwnershipContractTest.php) now covers caller sections on success and failure, reuse of definitions, and routing captured before an extension callback changes referenced input. Do not reclassify that completed ownership fix as an unresolved reason to redesign the API.

## Current behavioral boundary

| API | Current responsibility | What it does not establish |
|---|---|---|
| `assertShape(&$dependencies)` | Validate allowed keys/section types, then canonicalize and validate sections in order in the caller's array. [`DependencyConfigurationContractTest`](../../tests/Contract/DependencyConfigurationContractTest.php) covers direct successful canonicalization and malformed inputs. | Atomic rollback after a later error; a statically declared final `DependencyShape` output; complete factory ABI or class eligibility. |
| `normalize($dependencies, $defaultAliases)` | Own the input section slots, canonicalize a local working array, merge/check default aliases and return the documented typed result. | Deep-cloning application objects, undoing user-code effects during validation, or proving that every factory descriptor is admissible. |
| Definition admission during construction/registration | Validate factory ABI and class eligibility through the selected strategies. | It must not be silently moved into shape normalization. See [DI6 definition migration](../migrations/di6-definitions.md). |

A minimal direct-API scenario is an early keyed invokable followed by an invalid service ID. The invokable and its alias can be canonicalized before service validation throws. This scenario follows the current source order; it is not a new claim of transactional behavior:

```php
<?php

require getcwd() . '/vendor/autoload.php';

use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\InvalidConfigurationException;

$dependencies = [
    'invokables' => ['product' => stdClass::class],
    'services' => ['' => new stdClass()],
];

try {
    DependencyConfiguration::assertShape($dependencies);
} catch (InvalidConfigurationException $error) {
    // The caller can observe this state after the exception.
    var_export($dependencies['invokables']); // [0 => 'stdClass']
    var_export($dependencies['aliases']);   // ['product' => 'stdClass']
}
```

Calling `normalize()` instead does not publish these rewritten section slots into the caller's array. Ordinary objects and nested application values retain their intended identity. Copying an outer PHP array alone is not a proof that all nested reference cells have been detached; any future stronger ownership promise needs explicit tests of the cells that the implementation will write.

## Options and recommendation

| Option | Benefit | Cost and compatibility |
|---|---|---|
| A. Keep in-place validation and use typed `normalize()` | Existing callers retain behavior; typed consumers already have a supported return-value API. | A direct `assertShape()` caller does not gain a precise post-call shape annotation. |
| B. Typed section results, retaining defined partial failure state | Static analysis can prove each section and the assembled result without false annotations. | Requires maintaining the selected observable write order and failure state; section helpers and their ownership must be carefully specified. |
| C. Typed section results with publish-on-success | The API can promise unchanged caller-owned configuration slots if validation fails before publication. | Changes the direct method's failure behavior; references, allocation cost and effects from validation callbacks complicate the guarantee. Needs a compatibility/migration decision. |

**Recommendation:** keep A unless a downstream integration needs precise by-reference output typing or an explicit atomic configuration-editing API. If such a requirement exists, prototype typed section results first and choose B or C deliberately. Do not claim that the existing `normalize()` path is broken merely because the direct in-place API has a different contract.

Atomicity must be scoped to configuration publication. No variant can generally roll back an arbitrary autoloader or application callback that ran during validation. Likewise, preserving an object reference is not a promise to undo mutations to that object.

## Plan if stronger typing or atomic publication is selected

1. **Record real consumers and the required guarantee.** Identify whether the consumer wants a typed return value, post-call by-reference type information, or unchanged input after failure. The first need is already served by `normalize()`.
2. **Specify failure and reference ownership.** State which top-level and nested routing slots may be written, whether external reference aliases observe those writes, and which state remains after each validation failure. Preserve object/callable identity unless a separate ownership requirement says otherwise.
3. **Define typed section functions.** Return precise invokable, alias, delegator and extension-list shapes. Preserve integer resolver priorities, string IDs, numeric-key rejection, list rules and replace flags. Do not label an intermediate partially processed array as a completed `DependencyShape`.
4. **Assemble and publish under the chosen policy.** B must preserve the documented partial-state sequence. C must validate the selected private snapshot completely before assigning the result; detached outer slots alone are insufficient if helpers write through nested references. Default-alias merging and cycle detection remain explicit stages.
5. **Preserve admission and provenance.** Preserve existing canonicalization: invokable descriptors become class strings, while retained factory/ClassDefinition descriptors keep their identity. Validate ABI/class eligibility in admission, keep source/owner identity, and avoid extra instantiation or deferred-owner resolution during shape checks.
6. **Prove behavior and static types.** Add public input/output and failure-state tests plus a PHPStan consumer fixture using valid literal invokables and scalar delegators. For a deliberately changed promise, establish a baseline witness and the new result. Do not silence analysis with an ignore or a knowingly false annotation.
7. **Measure only the selected redesign.** Compare representative large configurations and small ordinary inputs with the same runtime/dependencies. Record normalization time, peak-memory measurement method, allocations/copies where measurable, and success/failure cases. Set acceptance budgets from a consumer workload rather than an arbitrary percentage.
8. **Document migration.** If the original in-place method changes its failure behavior, explain the observable difference. Avoid maintaining two subtly different new normalization implementations without a clear owner.

## Acceptance criteria

| Group | Required cases |
|---|---|
| Successful canonicalization | String/invokable descriptors, scalar and list delegators, empty sections, flags, stable priorities without reindexing, default and configured aliases. |
| Rejection | Numeric/empty IDs where forbidden, invalid section types, invalid late section after earlier canonicalization, alias cycles and conflicting invokable aliases. |
| Failure publication | Inspect the caller's array after each error; the state matches the selected partial or atomic policy. Check external references to rewritten sections and nested routing cells. |
| Ownership | Reuse the same definitions for two containers; earlier builds keep captured routes; services, callable receivers and nested application objects preserve required identity. Existing ownership tests remain green. |
| Static analysis | Valid literal inputs are accepted; the promised final output shape is proved by the implementation; malformed inputs are not falsely narrowed. |
| Side effects and errors | No duplicate factory/constructor execution to normalize metadata; native/DI error ownership remains defined. Do not claim rollback of arbitrary user effects. |
| Performance | Same workload/runtime baseline, recorded time and memory, no claim that a synthetic copy improvement measures an application's request latency. |

This proposal is complete only after the desired API guarantee, compatibility policy and implementation proof agree. Restoring a misleading type annotation does not complete it.
