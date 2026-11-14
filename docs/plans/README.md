# Architecture plans

[Русская версия](README.ru.md)

Implementation was authorized on November 14, 2026, and is isolated on `refactor/di6-definitions-callbacks`, based on the compatible v5.0.6 release commit. Specifications define the contracts; the [implementation report](../audit/2026-11-14-di6-refactoring.md) records what is implemented, tested and still gated. DI 6 is not released.

| Plan | English specification | Full Russian translation | Current status |
| --- | --- | --- | --- |
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) | [Русский](definition-owner-lifecycle.ru.md) | Implemented: central immutable bindings, registration-free strategies, snapshot composition and complete mutation retirement. Public extension migration belongs to the next major. [Migration](../migrations/di6-definitions.md). |
| Attribute availability polling | [English](attribute-availability-performance.md) | [Русский](attribute-availability-performance.ru.md) | Retain corrected polling. B2 remains conditional on application cost, a revised synchronization contract and G2/G3 proof; it is not implemented. C is not selected; A was not adopted. |
| PHP 8.5 callbacks and union autowiring | [English](php85-callbacks.md) | [Русский](php85-callbacks.ru.md) | Library slices implemented and locally verified: native Init controls, one binder, Transform, callback SetUp and simple unions. E1 remains open for the final PHP patch floor and affected native trait-callable support promise; request-to-DTO callbacks remain deferred. [Migration](../migrations/di6-callbacks.md). |

The Russian documents translate the complete specifications and plans, including contracts, examples, acceptance criteria and blockers. Their code symbols and test-case identifiers match the English versions. Update both versions when changing a contract.

The [November 14 patch audit](../audit/2026-11-14-release-audit.md) documents compatible fixes for v5.0.6. The [previous full-library recheck](../audit/2026-11-13-full-library-recheck.md) remains historical evidence for the architecture decisions and baseline limitations.
