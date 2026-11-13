# Architecture plans

[Русская версия](README.ru.md)

Reviewed on November 13, 2026, using the implementation-specification workflow: each plan distinguishes its desired contract, derived implementation steps, acceptance evidence, and remaining decisions. These documents describe proposed work; they do not add APIs to the 5.x bug-fix release.

| Plan | English specification | Full Russian translation | Readiness |
| --- | --- | --- | --- |
| Definition ownership and mutation retirement | [English](definition-owner-lifecycle.md) | [Русский](definition-owner-lifecycle.ru.md) | Specified for next-major implementation. It changes the public definition-resolver extension protocol; it is not a 5.x patch. |
| Attribute availability polling | [English](attribute-availability-performance.md) | [Русский](attribute-availability-performance.ru.md) | Correctness fix and bounded experiments are complete. A broader optimization is blocked on the G1 compatibility decision, then G2/G3 checkpoint and cost evidence. Alternative A was not adopted. |
| PHP 8.5 callbacks and union autowiring | [English](php85-callbacks.md) | [Русский](php85-callbacks.ru.md) | Feature contracts are specified for DI 6. Platform selection still requires the verified PHP patch floor and native regression gate. Request-to-DTO callbacks remain deferred. |

The Russian documents translate the complete specifications and implementation plans, including contracts, examples, acceptance criteria, and blockers. Their code symbols and test-case identifiers match the English versions. Update both versions when changing a contract.

See the [deep audit](../audit/2026-11-13-di-audit.md) and its [machine-readable evidence](../audit/2026-11-13-evidence.json) for the current implementation, fixed defects, and the reproductions that justify the lifecycle proposal.
