# Standalone creation, attribute processing and request helpers

[Русская версия](standalone-api-surface.ru.md)

**Status, 2026-11-20:** preserved architecture proposal, partly covered by later contract tests. No removal, visibility change or new runtime policy is selected. The remaining decision is which low-level surfaces are independently supported, and which exact preparation/callback guarantees they carry. Current source reference: [`43c4641`](https://github.com/componenta/di/tree/43c464187f80c95802fc36191350ae68b70a469e).

## Problem and provenance

The third full re-audit identified a support-policy gap: a public or protected method can have consumers outside this repository even when the current container pipeline does not call it. Removing it as unused, or testing every implementation detail merely to increase mutation coverage, would silently choose opposite compatibility policies. The original [English report](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round3.md#architecture-decisions-and-implementation-plans) and [expanded Russian plan](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round3.ru.md#6-решения-по-архитектуре-и-план-действий) were explicitly carried forward by the fourth audit. This document preserves that decision without treating its historical test inventory as current.

The issue concerns three related surfaces. It is separate from the implemented standalone composite/definition ownership contract in [definition-owner-lifecycle](definition-owner-lifecycle.md).

| Surface | Current behavior and existing evidence | Decision still needed |
|---|---|---|
| [`InstanceCreator`](../../src/Resolver/Entry/InstanceCreator.php) `create()` / `initialize()` | Both wrap prepared parameter resolution. [`InstanceCreatorContractTest`](../../tests/Contract/InstanceCreatorContractTest.php) covers explicit arguments, attributed dependencies, defaults, one constructor invocation, constructorless objects and native allocation errors. | Publish whether direct construction is a maintained extension API, its lifecycle limits, and which prepared methods are intended for downstream use. These paths are not currently untested. |
| `createPrepared()` / `initializePrepared()` and `beforeConstructor` | The object pipeline uses the prepared route. [`RequestConstructorSourceContractTest`](../../tests/Contract/RequestConstructorSourceContractTest.php) and [`RequestConstructorMetadataContractTest`](../../tests/Contract/RequestConstructorMetadataContractTest.php) already protect relevant final checks before constructor invocation. | State callback count and its position relative to argument resolution and allocation, including the constructorless case. Current `createPrepared()` invokes the callback for a constructorless class; `initializePrepared()` returns without it when no constructor exists. Whether to harmonize these cases is a compatibility decision, not an automatic bug fix. |
| [`AttributeProcessor::prepare()`](../../src/Resolver/Attribute/AttributeProcessor.php) and direct processing | `prepare()` builds the execution plan; it does not execute handlers, but may autoload and execute composition rules. This is not a purity or zero-user-code guarantee. [`StandaloneAttributeProcessorContractTest`](../../tests/Contract/StandaloneAttributeProcessorContractTest.php) covers independent before/after phases, repeated creation and property write effects without a bootstrap collaborator. [`AttributeProcessorRevisionContractTest`](../../tests/Contract/AttributeProcessorRevisionContractTest.php) protects availability refresh. | Decide whether preparation promises eager validation at that call boundary or is an optional warmup whose precise validation timing may change. Specify what late discovery can still invalidate after successful preparation. |
| Protected [`ExtractsRequestData::extractSharedData()`](../../src/Attribute/ExtractsRequestData.php) | [`RequestAttributeProtocolContractTest`](../../tests/Contract/RequestAttributeProtocolContractTest.php) already exercises this helper through `SharedSourceMapper::extract()`, with selection, transformation, fresh request values and source conflicts. Custom mappers remain supported in [mapping examples](request-mapping-examples.md). | State whether this particular protected helper, rather than only the public extraction/mapping protocol, is a maintained subclass hook. Preserve tested behavior during that decision. |

These observations identify a compatibility surface, not a demonstrated security vulnerability. They do not establish that all public implementation collaborators should be removed or declared internal. Nor does the existing test suite prove that every downstream invocation form has been inventoried.

## Why this needs a decision

The high-level pipeline and a standalone caller do not necessarily own the same work. The pipeline supplies policy callbacks and guards, while a direct caller chooses collaborators and may supply no callback. A support promise must say which layer owns attribute phases, validation, request-source checks, allocation and error wrapping. A method name alone is insufficient to infer those responsibilities.

In particular, silently making `prepare()` a no-op can change when a caller sees an invalid composition. Making it eagerly instantiate attribute arguments can add user-code effects to a previously metadata-oriented step. Similarly, invoking a previously omitted callback during constructorless initialization adds an effect even though the object has no constructor. Any such change needs a selected public contract and a consumer-visible regression or migration case.

## Options and recommendation

| Option | Benefit | Cost and compatibility |
|---|---|---|
| Explicitly support the selected standalone methods | Consumers can use lower-level integration points with documented lifecycle and error guarantees. Tests cover actual extension behavior. | Every selected guarantee must survive internal optimization; examples and regression coverage need maintenance. |
| Keep selected methods as implementation APIs with a transition | Internal planning and caching can evolve without promising every callable surface as a permanent extension point. | Requires consumer inventory, explicit documentation and a compatible deprecation/migration path. Adding `@internal` does not itself make an existing consumer disappear. |
| Remove or reduce visibility immediately because the pipeline does not use a wrapper | Smaller apparent surface. | Rejected as an ungrounded change: repository callers are not the complete downstream population. |

**Recommendation:** preserve current behavior while completing a focused support inventory. Prefer documenting the already useful and tested standalone routes if known integrations use them. Do not add another facade or duplicate pipeline merely to resolve documentation ambiguity. Restrict or deprecate a surface only with a concrete migration target and compatibility rationale.

No speedup, memory reduction or security hardening is claimed from this policy work. Its benefit is a reviewable compatibility boundary and useful tests which protect real application behavior.

## Implementation plan after the decision

1. **Inventory consumers.** Record README/migration examples, published API documentation, repository tests and known downstream Composer consumers. For unavailable downstream source, mark usage unknown. Do not equate a zero-result repository search with no consumers.
2. **Publish a support matrix in both languages.** For each method identify the supported inputs, required collaborators, validation timing, handler execution, callback ownership, exception guarantees and lifetime. Explicitly distinguish direct creation from the complete container lifecycle; direct methods must not imply container features they do not run.
3. **Choose preparation semantics.** Decide what `prepare()` proves immediately, what can change with a later availability revision, and whether a subsequent `process()` must still validate. Retain current late-discovery and completed-prefix obligations; successful preparation must not freeze an unavailable attribute forever.
4. **Choose callback semantics.** Define omitted/supplied callbacks for constructorless and argument-bearing creation and initialization. State the exact invocation count and position before allocation or constructor invocation. Preserve request-source checks and exception identity/wrapping where already established.
5. **Make only the necessary implementation change.** Use the existing prepared route as the shared implementation. If deprecation is selected, first document a replacement and prove it handles actual consumer scenarios. Do not remove protected hooks or change visibility in this documentation step.
6. **Add missing public-contract witnesses.** Use direct public calls or a real subclass exposing extraction through its public method. Avoid reflection into private caches, private invocation counters or assertions on incidental message wording.
7. **Update examples and migration notes.** Mark implemented versus proposed promises clearly. Run the affected suite and the normal PHP 8.4/8.5 gates for any runtime change. Documentation-only clarification needs link/example verification rather than a fabricated performance claim.

## Acceptance matrix

| Scenario | Required observation under the chosen contract |
|---|---|
| Direct `create()` and `initialize()` with dependencies and explicit values | Correct values, defaults and one constructor invocation; initialization retains the supplied object identity. Existing evidence is retained. |
| Constructorless create/initialize, optional callback present/absent | Chosen count and timing are explicit; no callback is silently replayed to reach convergence. |
| Prepared parameter failure | Constructor/allocation and callback effects follow the selected boundary; DI exceptions and foreign causes retain their owner. |
| `prepare()` with an invalid composition | Error timing matches the documented eager-validation or warmup policy. |
| Late availability after prepare, cold/warm and direct/pipeline routes | Subsequent supported work observes current metadata; already completed work is not retroactively counted or replayed. |
| Custom mapper subclass | Selected request attributes/files, null values, duplicate-source conflicts, transformation and successive requests remain correct. |
| Reentrant or Fiber-separated calls | Invocation values and attribute instances remain isolated; a shared plan does not retain a request/receiver. |
| Downstream migration if a surface is deprecated | Every inventoried supported use has an executable replacement example; release notes state the compatibility effect. |

Completion means a published support matrix, reviewed consumer inventory and tests of the selected guarantees. A higher mutation score by itself does not close this proposal.
