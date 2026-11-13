# Architecture plans

[Русская версия](README.ru.md)

Rechecked on November 13, 2026, using the implementation-specification workflow: each plan distinguishes its desired contract, derived implementation steps, acceptance evidence, and remaining gates. Implementation readiness describes a coding contract, not authorization to implement or release it. The published v5.0.5 release retains its existing API and PHP `^8.4` requirement.

| Plan | English specification | Full Russian translation | Readiness and selected direction |
| --- | --- | --- | --- |
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) | [Русский](definition-owner-lifecycle.ru.md) | Implementation-ready for the next major: one table of immutable bindings in `CompositeResolver`, execution strategies without registration maps, and an internal `MutationRetirement` barrier. The public definition-resolver extension protocol changes; this is not a 5.x patch. |
| Attribute availability polling | [English](attribute-availability-performance.md) | [Русский](attribute-availability-performance.ru.md) | G1 is settled for current 5.x: retain corrected polling; no broad optimization is selected. B2 is the preferred conditional direction if application cost justifies a revised synchronization contract and G2/G3 proof. C is not selected; A was not adopted. |
| PHP 8.5 callbacks and union autowiring | [English](php85-callbacks.md) | [Русский](php85-callbacks.ru.md) | Existing `Init` examples and regression proof are compatible work. The callback binder, `Transform`, callback `SetUp` and simple-union contracts are ready for coding on a future major branch. E1 blocks the native trait-callable support promise and selection of the final minimum PHP patch for the DI 6 release, while those library slices can be implemented and tested. Request-to-DTO callbacks remain separately blocked. |

The Russian documents translate the complete specifications and implementation plans, including contracts, examples, acceptance criteria, and blockers. Their code symbols and test-case identifiers match the English versions. Update both versions when changing a contract.

See the [full-library and specification recheck](../audit/2026-11-13-full-library-recheck.md) and its [machine-readable evidence](../audit/2026-11-13-full-library-evidence.json) for the subsequent main-branch fixes, workload measurements, source boundaries, and remaining lifecycle limitations. That report retains the previous audit as historical evidence; it does not announce another release.
