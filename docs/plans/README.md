# Architecture plans

[Русская версия](README.ru.md)

The [November 15 repeated review](../audit/2026-11-15-di6-repeat-review.md) records two additional consistency corrections, behavior-test proof and complete source coverage after the [preceding full-library review](../audit/2026-11-14-di6-full-library-recheck.md). These fixes preserve the selected architecture. The correction in the [platform specification](php85-callbacks.md#8-php-version-and-release-compatibility) limits E1 to affected native expression forms and removes the earlier whole-release gate.

Implementation was authorized on November 14, 2026, and is isolated on `refactor/di6-definitions-callbacks`, based on the compatible v5.0.6 release commit. Specifications define the contracts; the [implementation report](../audit/2026-11-14-di6-refactoring.md) records what is implemented, tested and still gated. DI 6 is not released.

The implementation-ready library refactoring has converged with these contracts. The [completion recheck](../audit/2026-11-14-di6-refactoring.md#completion-recheck) covers three full passes on each local stable runtime, the release-candidate runtime, isolated consumer installations and extension migration. The library retains PHP `^8.4`; E1 limits only affected native-form support and does not delay the library release. The conditional B2 and possible 6.1 DTO work are separate future scopes.

| Plan | English specification | Full Russian translation | Current status |
| --- | --- | --- | --- |
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) | [Русский](definition-owner-lifecycle.ru.md) | Implemented: central immutable bindings, registration-free strategies, snapshot composition and complete mutation retirement. Public extension migration belongs to the next major. [Migration](../migrations/di6-definitions.md). |
| Attribute availability polling | [English](attribute-availability-performance.md) | [Русский](attribute-availability-performance.ru.md) | Retain corrected polling. B2 remains conditional on application cost, a revised synchronization contract and G2/G3 proof; it is not implemented. C is not selected; A was not adopted. |
| PHP 8.5 callbacks and union autowiring | [English](php85-callbacks.md) | [Русский](php85-callbacks.ru.md) | Library slices implemented and locally verified: native Init controls, one binder, Transform, callback SetUp and simple unions. PHP `^8.4` remains the package requirement. E1 limits only affected native trait-callable support; request-to-DTO callbacks remain deferred. [Migration](../migrations/di6-callbacks.md). |

The Russian documents translate the complete specifications and plans, including contracts, examples, acceptance criteria and blockers. Their code symbols and test-case identifiers match the English versions. Update both versions when changing a contract.

The [November 14 patch audit](../audit/2026-11-14-release-audit.md) documents compatible fixes for v5.0.6. The [previous full-library recheck](../audit/2026-11-13-full-library-recheck.md) remains historical evidence for the architecture decisions and baseline limitations.
