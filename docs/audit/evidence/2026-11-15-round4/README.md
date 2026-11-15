# Round-four evidence

The [report](../../2026-11-15-di6-full-reaudit-round4.md) and its [Russian version](../../2026-11-15-di6-full-reaudit-round4.ru.md) distinguish confirmed production defects, test-oracle witnesses, native observations and benchmark smoke. The validated code/test commit is `348330544d3d5b0bec7b158e0c2221927dc23643`; the baseline is `897f8b10ca6537e7b2f96d20b2e00ed52571ff6f`.

| Record | Contents |
|---|---|
| [Overall verification](../../2026-11-15-di6-full-reaudit-round4-evidence.json) | Published commits; actual production diff; local commands, full outputs and before/after fingerprints; JUnit census; dependency graph; CI evidence |
| [Scope](../../2026-11-15-di6-full-reaudit-round4-scope.json) | All 169 reviewed production files, all 247 test-tree files, 22 additional tooling/configuration files and individual hashes/notes |
| [Callable cache defect](callable-case-fix.json) | Actual original-source RED and corrected-source GREEN, with source and test hashes |
| [Cast](cast-fallback.json), [request protocols](request-protocol.json), [magic closure](magic-visibility.json) | Recorded old/improved test phases, source/test identity, full outputs and output SHA-256 |
| [Exact mutation diffs](mutations.json) | Nine exact diffs reconstructed from the executed proof scripts and checked against all 36 recorded mutant-source runs; old/improved test revisions; links and hashes for the durable phase files |
| [Core](core-proof.json) | Three exact mutation witnesses and their 24 canonical run records; independent reviews; bounded alias-screening samples and original probe source |
| [Autoload identity](autoload-identity-proof.json) | One separate bespoke mutation, exact identity RED/GREEN, source origins, bootstrap and unchanged production |
| [Windows PHP 8.5 fixtures](windows-php85-fixture-proof.json) | Actual Windows RED/GREEN, unchanged expectations, minimal source-guard fix and 12 negative controls |
| [Benchmark smoke](benchmark-smoke.json) | Ten final-source processes, exact environment/commands, source guards, unchanged source/harness manifests and stdout/stderr |
| [Completed coverage and mutation CI](coverage-mutation-ci.json) | Exact final run/job/artifact metadata, coverage table, MSI formula, timeout distinction and independent verification |
| [Clover XML](coverage.xml), [original artifact ZIP](coverage-3483305.zip), [full CI log](coverage-mutation.log.gz) | Complete durable artifacts with verified hashes; the log retains all 476 current UNTESTED diffs |
| [Propertyless lifecycle](../../2026-11-15-propertyless-lazy-evidence.json) | Baseline observations plus independent integrated-source observations; selected old-suite controls; syntax/style and source-selection checks |

Original absolute scratch paths in records identify where a command actually ran. They are provenance, not dependencies needed to read the evidence. Outputs, exact mutation diffs, public commit revisions and relevant hashes are embedded. To rerun, use an isolated checkout of the recorded revision with its own Composer autoload setup and substitute the available PHP binary. The old/improved test bytes must come from the recorded test revision; using the new tests for an OLD control changes the experiment.

The current memory regression can be run with:

```sh
php vendor/bin/pest tests/Contract/CallableCaseCacheRetentionContractTest.php --compact --do-not-cache-result
```

The independent open lifecycle defect can be observed with:

```sh
php docs/audit/reproductions/PropertylessLazyObservation.php
```

Its 42 observations are not 42 new passing regression tests. The intentionally rejected eager-fallback prototype is local to that observation script and must not be treated as a production fix. The [design proposal](../../../plans/propertyless-lazy-lifecycle.md) leaves D1 open.

Only the retirement mutant was checked against the complete old suite. The other eleven catalogue witnesses and the bespoke identity witness used their recorded related test-file controls. A semantic RED means a checked observable violation; timeout, setup failure, alternate error wording or a survivor's disappearance alone is insufficient. The final full mutation job completed successfully: 4,249 mutations, 3,716 tested, 57 timeout and 476 untested; reported MSI 88.80%. The tested-only numerator over all 4,249 mutants gives 87.46%. This engine accounting is recorded independently from the controlled local semantic proofs.
