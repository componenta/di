# Architecture contracts and plans

[Русская версия](README.ru.md)

The accepted refactoring scope is implemented on `refactor/di6-definitions-callbacks`. DI 6 is not released. The package retains PHP `^8.4`; native callback-expression syntax requires PHP 8.5.

The November 20 architecture-report inventory is now preserved here. [The source-to-plan inventory](audit-architecture-inventory.md) records historical proposals, completed corrections, superseded designs and deliberately unchanged contracts. Open reports below are proposals, not authorization to change runtime behavior or a claim that all limitations are DI regressions.

The subsequent [fresh recheck](audit-architecture-recheck-2026-11-20.md) records executed architecture witnesses, added behavior tests, verification results and evidence limits. Its [JSON observations](audit-architecture-recheck-2026-11-20.json) are stored alongside the reports.

The [November 21 DI 6 audit](di6-audit-2026-11-21.md#architecture-fixes) includes the architecture fixes, migration and performance comparison.

## Architecture proposals before the follow-up

The table records the status of the proposals when published. The implemented bootstrap finalization, external notifications, alias retirement, return typing, delegator indexing, graph construction and targeted attribute discovery are described in the [architecture fixes](di6-audit-2026-11-21.md#architecture-fixes).

| Area | Report | Decision and expected benefit |
|---|---|---|
| Bootstrap finalization | [English](bootstrap-finalization-boundaries.md) / [Русский](bootstrap-finalization-boundaries.ru.md) | Two separate boundaries: private loader readiness and metadata changes during proof-reference release. Choose preload/optional notification and a terminal cleanup failure policy before implementation; preserve identity and avoid replay. |
| Dynamic external-provider ownership | [English](external-provider-ownership.md) / [Русский](external-provider-ownership.ru.md) | Choose an explicit stable-provider contract or an opt-in ownership signal. Addressed invalidation needs observable changes; repeated opaque `has()` calls were withdrawn as an incomplete fix. |
| Binding lifetime under runtime aliases | [English](di6-audit-2026-11-21.md#lifetime-of-a-binding-hidden-by-an-alias) / [Русский](di6-audit-2026-11-21.ru.md#срок-жизни-привязки-скрытой-алиасом) | Define ownership and destructor timing for previously canonical data hidden by `alias()`. Preserve successful retargeting, in-flight results and unaffected shared identities. |
| Legacy relative callable context | [English](relative-callable-context.md) / [Русский](relative-callable-context.ru.md) | Document the compatibility boundary; carry invocation-local lexical/called/receiver context only if a consumer requires the deprecated forms. This is separate from native PHP 8.5 closure expressions. |
| Instance-callable shorthand owner precedence | [English](relative-callable-context.md) / [Русский](relative-callable-context.ru.md) | Pass 24: class aliases containing `::` can conflict with accepted qualified ancestor methods. Define one owner/discovery/dependency-invalidation contract before changing string shorthand; the explicit method array is available. The distinct native-valid string parameter-plan bug is fixed in `45eb1ab`. |
| Late-loaded delegator dependencies | [English](relative-callable-context.md#9-separate-policy-boundary-delegators-registered-before-their-owner-loads) / [Русский](relative-callable-context.ru.md#9-отдельная-граница-политики-регистрация-delegator-до-загрузки-владельца) | Pass 25: an initially conservative owner dependency can remain after native-static resolution and cause unnecessary replay. Keep the current policy unless a consumer needs exact dependencies; stronger identity guarantees need route evidence and atomic cache/index publication. The already-loaded native-alias defect is fixed in `f4869bc`. |
| Standalone low-level APIs | [English](standalone-api-surface.md) / [Русский](standalone-api-surface.ru.md) | Later tests cover several original gaps. Remaining support, preparation and callback-timing promises need a consumer inventory and explicit API policy. |
| Static return types for class-name IDs | [English](standalone-api-surface.md#additional-policy-scope-conditional-return-types-for-class-name-ids) / [Русский](standalone-api-surface.ru.md#дополнительная-граница-политики-условный-тип-результата-для-class-name-id) | Pass 23, baseline `748357f`: generic `get()`/`make()` inference exceeds the products allowed under class-name IDs. Choose accurate unrestricted types, a verified typed operation or an explicit application assumption after consumer review. No runtime defect or new API policy is established. |
| Configuration normalization | [English](configuration-normalization-contract.md) / [Русский](configuration-normalization-contract.ru.md) | Preserve current `assertShape()`/typed `normalize()` behavior. Stronger by-reference typing or atomic failure publication is conditional; earlier annotation and value-normalization ownership bugs are fixed. |
| Large cold alias graphs | [English](large-alias-graphs.md) / [Русский](large-alias-graphs.ru.md) | Qualify a real startup workload before replacing repeated validation walks with a graph algorithm. Preserve diagnostic order, cycles and mutation semantics; no warm-lookup gain is assumed. |
| Shared native lazy objects across Fibers | [English](native-lazy-fiber.md) / [Русский](native-lazy-fiber.ru.md) | Preserve the native concurrency boundary. Application-level initialization/synchronization is a separate contract; a scheduler or locking subsystem is not selected. |
| Attribute availability cost | [English](attribute-availability-performance.md) / [Русский](attribute-availability-performance.ru.md) | Retain corrected polling. Current DI6 evidence records 24/192 missing-name calls per affected creation. B2 needs application-cost justification and a revised synchronization contract; C is not selected. |

Every report states the problem, evidence limits, alternatives, recommendation, benefit, cost/compatibility, implementation sequence and acceptance criteria. Neither an unsupported-input counterexample nor a native PHP limitation is automatically a local DI bug.

## Implemented contracts and existing usage

| Area | Specification | Status |
|---|---|---|
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) / [Русский](definition-owner-lifecycle.ru.md) | Central bindings, registration-free strategies, snapshots, attachment rules and retirement after complete invalidation are implemented. [Migration](../migrations/di6-definitions.md). |
| Callbacks and unions | [English](php85-callbacks.md) / [Русский](php85-callbacks.ru.md) | The shared binder, Transform, callback SetUp, Init controls and simple unions are implemented. E1 passes on stable PHP 8.5.13, the minimum verified patch for the affected native forms; `afterMap` is an additive mapping option. [Migration](../migrations/di6-callbacks.md). |
| Request mapping ergonomics | [Existing/callback examples](request-mapping-examples.md) / [Русский](request-mapping-examples.ru.md) | Optional `afterMap` is implemented according to callbacks §11. Existing mapping APIs remain supported; compare typed DTO alternatives, reuse, custom sources and limits. |
| Native laziness | [English](propertyless-lazy-lifecycle.md) / [Русский](propertyless-lazy-lifecycle.ru.md) | D1/A is implemented: the default adapter rejects unsupported targets before allocation. Deferred-callback and custom-adapter contracts are preserved. |

Specifications retain decision rationale, contracts, compatibility limits and acceptance criteria. Update both language versions and the corresponding tests when changing a contract.

Run `composer check`, `composer test-coverage` and `composer test-mutation`. [CI](../../.github/workflows/ci.yml) runs full checks on PHP 8.4/8.5 on Linux and Windows, and lowest production consumers on PHP 8.4/8.5/8.6. The complete PHP 8.6 development-tool graph is not qualified by the consumer smoke. Logs and coverage remain workflow results. The native E1 regression runs separately on exactly PHP 8.5.13 with `composer test-platform`'s underlying command.
