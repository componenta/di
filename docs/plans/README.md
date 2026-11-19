# Architecture contracts and plans

[Русская версия](README.ru.md)

The accepted refactoring scope is implemented on `refactor/di6-definitions-callbacks`. DI 6 is not released. The package retains PHP `^8.4`; native callback-expression syntax requires PHP 8.5.

| Area | Specification | Status |
|---|---|---|
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) / [Русский](definition-owner-lifecycle.ru.md) | Central bindings, registration-free strategies, snapshots, attachment rules and retirement after complete invalidation are implemented. [Migration](../migrations/di6-definitions.md). |
| Callbacks and unions | [English](php85-callbacks.md) / [Русский](php85-callbacks.ru.md) | The shared binder, Transform, callback SetUp, Init controls and simple unions are implemented. E1 passes on stable PHP 8.5.13, the minimum verified patch for the affected native forms; `afterMap` is an additive mapping option. [Migration](../migrations/di6-callbacks.md). |
| Request mapping ergonomics | [Existing/callback examples](request-mapping-examples.md) / [Русский](request-mapping-examples.ru.md) | Optional `afterMap` is implemented according to callbacks §11. Existing mapping APIs remain supported; compare typed DTO alternatives, reuse, custom sources and limits. |
| Native laziness | [English](propertyless-lazy-lifecycle.md) / [Русский](propertyless-lazy-lifecycle.ru.md) | D1/A is implemented: the default adapter rejects unsupported targets before allocation. Deferred-callback and custom-adapter contracts are preserved. |
| Attribute availability | [English](attribute-availability-performance.md) / [Русский](attribute-availability-performance.ru.md) | Retain corrected polling. B2 needs an application-cost justification and a revised contract before G2/G3; no implementation is selected. C is not selected. |

Specifications retain decision rationale, contracts, compatibility limits and acceptance criteria. Update both language versions and the corresponding tests when changing a contract.

Run `composer check`, `composer test-coverage` and `composer test-mutation`. [CI](../../.github/workflows/ci.yml) runs full checks on PHP 8.4/8.5 on Linux and Windows, and lowest production consumers on PHP 8.4/8.5/8.6. The complete PHP 8.6 development-tool graph is not qualified by the consumer smoke. Logs and coverage remain workflow results. The native E1 regression runs separately on exactly PHP 8.5.13 with `composer test-platform`'s underlying command.
