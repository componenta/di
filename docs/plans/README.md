# Architecture contracts and plans

[Русская версия](README.ru.md)

The accepted refactoring scope is implemented on `refactor/di6-definitions-callbacks`. DI 6 is not released. The package retains PHP `^8.4`; native callback-expression syntax requires PHP 8.5.

| Area | Specification | Status |
|---|---|---|
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) / [Русский](definition-owner-lifecycle.ru.md) | Central bindings, registration-free strategies, snapshots, attachment rules and retirement after complete invalidation are implemented. [Migration](../migrations/di6-definitions.md). |
| Callbacks and unions | [English](php85-callbacks.md) / [Русский](php85-callbacks.ru.md) | The shared binder, Transform, callback SetUp, Init controls and simple unions are implemented. E1 limits affected native forms; request-to-DTO callbacks remain deferred. [Migration](../migrations/di6-callbacks.md). |
| Request mapping ergonomics | [Current/proposed examples](request-mapping-examples.md) / [Русский](request-mapping-examples.ru.md) | Optional `afterMap` design is specified in callbacks §11; not implemented. Compare equivalent typed DTO paths, reuse, custom-source registration and limits. |
| Native laziness | [English](propertyless-lazy-lifecycle.md) / [Русский](propertyless-lazy-lifecycle.ru.md) | D1/A is implemented: the default adapter rejects unsupported targets before allocation. Deferred-callback and custom-adapter contracts are preserved. |
| Attribute availability | [English](attribute-availability-performance.md) / [Русский](attribute-availability-performance.ru.md) | Retain corrected polling. B2 needs an application-cost justification and a revised contract before G2/G3; no implementation is selected. C is not selected. |

Specifications retain decision rationale, contracts, compatibility limits and acceptance criteria. Update both language versions and the corresponding tests when changing a contract.

Run `composer check`, `composer test-coverage` and `composer test-mutation`. [CI](../../.github/workflows/ci.yml) checks PHP 8.4/8.5 on Linux and Windows; logs and coverage remain workflow results. Native PHP limitations are checked separately with `composer test-platform`.
