# D1/A implementation evidence

This directory preserves verification for fix commit [`299ee93f6be6d91e3a4fc1372389f1fff953d61b`](https://github.com/componenta/di/commit/299ee93f6be6d91e3a4fc1372389f1fff953d61b), relative to baseline `fe435db72d60fc3b042f1a5def815bf1fdb91980`. Read the [English report](../../2026-11-16-propertyless-lazy-fix.md) or [Russian report](../../2026-11-16-propertyless-lazy-fix.ru.md) for the defect, architecture decision, migration and results.

The evidence is a record of executed checks. Absolute scratch paths inside records identify the original process and loaded files; they are provenance, not dependencies required to read the archive. Full logs are embedded or preserved as files, so a temporary path need not remain available.

## Local regression and complete-suite proof

| Artifact | Contents and interpretation |
| --- | --- |
| [production.patch](production.patch) | Exact executable production change used for the isolated RED/GREEN comparisons. Only `src/ProxyFactory.php` changes. The published commit also includes four comment-only source updates, both new tests and documentation. |
| [direct-proof.json](direct-proof.json) | Original direct-test evidence, including baseline source hashes, canonical final-test-byte runs, loaded origins, results, failure messages and review notes. Its `canonical_runs` are the four accepted RED/GREEN runs. Earlier candidate records are explicitly historical. |
| [direct-run-artifacts.json](direct-run-artifacts.json) | Full stdout and JUnit for all four canonical runs, plus the source-origin bootstrap, runner and exact final test-only patch. Each text's original byte count and SHA-256 were checked when archived. |
| [integration-proof.json](integration-proof.json) | Original 11-case lifecycle/delegation proof on both runtimes, including isolated loaded-class checks and source fingerprints. |
| [integration-run-artifacts.json](integration-run-artifacts.json) | All four complete integration outputs, origin guard, runner and exact test-only patch, with verified hashes. Printer-suppressed harness attempts are not used as semantic proof. |
| [verification.json](verification.json) | Final local commands, unmodified run metadata, full outputs, published byte identity, all 418 source/test hashes, comment-only validation, dependency versions and the complete JUnit case comparison. |
| [local-php84-junit.xml.gz](local-php84-junit.xml.gz), [local-php85-junit.xml.gz](local-php85-junit.xml.gz) | Full final local JUnit files compressed with deterministic gzip metadata. Decompressed bytes match the SHA-256 values in `verification.json`. |
| [baseline-php84-junit.xml.gz](baseline-php84-junit.xml.gz), [baseline-php85-junit.xml.gz](baseline-php85-junit.xml.gz) | Full prior JUnit files used for the case census, also with deterministic gzip metadata. Both sides of the 1,545-to-1,620 comparison are preserved, including assertions and skip/failure/error outcomes. |

Per runtime, the direct baseline gives **28 failed / 36 passed**, and the integration baseline gives **6 failed / 5 passed**. Every expected failure concerns the missing rejection. After the single production patch, the suites give **64 + 11 passed, 417 + 98 assertions**. Loaded source is checked within the same process as the tests; separate vendor/autoload copies avoid silently testing the main checkout.

The final direct test has SHA-256 `0764318df8247492aa74412d84030d2018e6d56372c853b423d4399ebd0734d8`; the integration test has SHA-256 `d0eab489292d5fc0f2e668ef6ddbfad3c224e2f614864e33b4a462951a105a10`. The canonical direct-run directory name contains `configured-phpstan-fixture` because it followed the fixture's static-analysis correction; the commands in those records are the actual Pest regression runs, not PHPStan runs.

The local complete-suite commands ran before committing, so their metadata correctly records `fe435db` plus the working changes. All recorded before/after source/test fingerprints equal `f517fbbacdcd1e380d9492d8b2c1496801cbeb8716d3e01531f356a927af2e3e`, and every file byte was subsequently compared against published `299ee93`. This gives provenance without rewriting history. The earlier complete-suite JUnit used for case census came from code commit `3483305`; its test subtree is identical to the baseline `fe435db` test subtree, as recorded in `verification.json`.

The repository does not track a Composer lock file. The local installed package versions/references are therefore recorded separately in `verification.json`; a new dependency resolution at a later date need not reproduce the same tool versions. CI logs preserve their own dependency installation and runtime information.

## Current CI and coverage/mutation proof

[Run 43774753909](https://github.com/componenta/di/actions/runs/43774753909), push attempt 1 at exact fix commit `299ee93`, completed successfully on all five required jobs. The workflow-dispatch benchmark condition does not apply to this push; that job is skipped.

| Artifact | Contents |
| --- | --- |
| [ci.json](ci.json) | Final run/job metadata, platform results, coverage/mutation commands and thresholds, exact numerators/denominators, source-qualified score and console-format rules, artifact digests and limitations. |
| [ci-independent-review.json](ci-independent-review.json) | A second API read by the main reviewer confirming the exact head, successful run and all five job conclusions. |
| [platform-summary.json](platform-summary.json), [platform-log-artifacts.json](platform-log-artifacts.json) | Platform-only snapshot and raw/gzip checksums. The snapshot was collected before the mutation job finished; its historical pending field is superseded by `ci.json`. |
| [Linux PHP 8.4 log](job-132998418193.log.gz), [Linux PHP 8.5 log](job-132998418255.log.gz), [Windows PHP 8.4 log](job-132998418224.log.gz), [Windows PHP 8.5 log](job-132998418174.log.gz) | Complete decoded platform logs, compressed losslessly with gzip mtime zero. |
| [coverage-mutation-job-132998417946.log.gz](coverage-mutation-job-132998417946.log.gz) | Complete coverage/mutation job log, including every reported survivor diff, totals and upload evidence. |
| [coverage-299ee93.zip](coverage-299ee93.zip), [coverage.xml](coverage.xml), [coverage-artifact-metadata.json](coverage-artifact-metadata.json) | Original GitHub ZIP, its complete Clover member and artifact metadata. ZIP digest matches GitHub; extraction matches the XML byte-for-byte. |
| [proxyfactory-survivors.json](proxyfactory-survivors.json), [proxyfactory-survivors.log](proxyfactory-survivors.log) | Both `ProxyFactory` survivor IDs, full original excerpts, added-line mapping and qualified static equivalence analysis. No extra mutant runs are claimed. |

Coverage console Total is **92.5%**; the exact Clover statement fraction is **4,486 / 4,846 = 92.571192…%**. Pest truncates the console display to one decimal place. Methods are a separate **441 / 558** metric. `ProxyFactory.php` has **35 / 35 statements and 3 / 3 methods covered**. Clover's zero conditional counters do not measure branch coverage.

Mutation totals are **4,274 = 3,743 tested + 54 timeout + 477 untested**. Reported MSI is **88.84%**, calculated as `(tested + timeout) / total × 100`; `tested / total × 100` is separately **87.58%**. Timeouts remain in the denominator of both calculations. The engine's outcome classification is not individual semantic triage of every mutant. Exactly two survivors affect the new adapter code; their apparent behavioral equivalence is a static inference, with the reasoning and original diffs preserved.

The source excerpts used to explain formatting and score calculation come from locally inspected installed packages with versions matching the CI installation log. The remote vendor tree was not separately hashed. This limit is explicit in `ci.json`.

## Reproducing the focused comparison

Use a disposable checkout at the exact baseline with its own installed dependencies. Copy both new contract files from fix commit `299ee93` into that checkout. Run those two files with Pest under the selected PHP binary, using `--compact --colors=never --do-not-cache-result`. The intended RED result is 34 missing-rejection failures and 41 passing controls. Apply only the archived `production.patch`, without changing the test files, and repeat the same command. The expected GREEN result is 75 passing cases and 515 assertions. Run PHP 8.4 and 8.5 separately.

The archive preserves the exact commands and origin guards actually used, including absolute paths for the isolated environments. If adapting them to another checkout, change the paths together and retain the checks that loaded code belongs to that checkout. Use the final published test bytes, not the earlier fixture artifact. The historical native observation script in the preceding audit is a pre-repair reproduction and is not the post-repair acceptance oracle.

After focused proof, the applicable repository gates are the full test suite, configured PHPStan, style and the platform/coverage/mutation workflow. The published evidence records those gates for this repair; it does not imply absence of every possible defect or certify an alternative custom lazy adapter.

## Integrity

[manifest.json](manifest.json) records byte counts, SHA-256 and Git blob identities for every archived artifact and the seven updated report/plan documents. The manifest excludes itself to avoid a self-referential checksum. [delivery-checks.json](delivery-checks.json) records the final artifact/link/byte checks before publication. Historical evidence from preceding audits remains unchanged.

Original unified-diff context spacing in `production.patch` and the raw CI separator spacing in `proxyfactory-survivors.log` are retained to preserve their recorded bytes. The three expected whole-diff whitespace diagnostics are enumerated in `delivery-checks.json`; authored Markdown and structured artifacts pass the separate whitespace check. Imported evidence is not reformatted to change its hashes.

The native exception-trace caveat, custom-adapter protocol scope, optional local PHPStan turbo fallback, and local/CI runtime distinction remain explicit in the reports and JSON records. No performance improvement is inferred from these correctness checks.
