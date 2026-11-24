# Architecture plans and audit reports

[Русская версия](README.ru.md) · [User documentation](../index.md)

Engineering decisions, alternatives and audits for the **unreleased** DI 6 branch. Proposals are not implemented features or confirmed runtime defects.

## Implemented DI 6 contracts

- [Definition ownership and mutation retirement](definition-owner-lifecycle.md) ([RU](definition-owner-lifecycle.ru.md)) — [migration guide](../migrations/di6-definitions.md).
- [Callbacks and union autowiring](php85-callbacks.md) ([RU](php85-callbacks.ru.md)) — [migration guide](../migrations/di6-callbacks.md).
- [Request-mapping examples](request-mapping-examples.md) ([RU](request-mapping-examples.ru.md)) — optional `afterMap` and existing alternatives.
- [Native lazy-object eligibility](propertyless-lazy-lifecycle.md) ([RU](propertyless-lazy-lifecycle.ru.md)) — default-adapter restrictions.

## Proposals requiring a separate decision

- [Bootstrap finalization](bootstrap-finalization-boundaries.md) · [External-provider ownership](external-provider-ownership.md).
- [Relative callable compatibility](relative-callable-context.md) · [Standalone APIs and return types](standalone-api-surface.md).
- [Configuration normalization](configuration-normalization-contract.md) · [Large alias graphs](large-alias-graphs.md).
- [Shared lazy objects across Fibers](native-lazy-fiber.md) · [Attribute-availability performance](attribute-availability-performance.md).

Russian translations are adjacent `.ru.md` files. Some older reports describe issues already fixed; check the subsequent audit before treating them as open.

## Verification history

- [Architecture inventory](audit-architecture-inventory.md) · [November 20 recheck](audit-architecture-recheck-2026-11-20.md)
- [November 21 DI 6 audit](di6-audit-2026-11-21.md) · [Russian release recheck](di6-release-recheck-2026-11-21.ru.md)

Use the [user guides](../index.md) for supported container APIs. Project checks: `composer check`, `composer test-coverage`, `composer test-mutation`.
