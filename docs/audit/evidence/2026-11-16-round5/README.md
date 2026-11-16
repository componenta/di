# Round 5 evidence index

This directory supports the [English report](../../2026-11-16-di6-full-reaudit-round5.md) and [Russian report](../../2026-11-16-di6-full-reaudit-round5.ru.md) for `refactor/di6-definitions-callbacks`.

- Baseline: `406c8ea563d2fa304434a695c4b6b43f1f269e6f`.
- Final implementation/tooling: `42b62afd9c6a57caa0db3916585e823ab6ebaafa`.
- Exact final-code CI: [run 43857035628](https://github.com/componenta/di/actions/runs/43857035628).
- Source tree: `886c58afff5c667852122748a1003be3bd52dabc`.
- Test tree: `decc32b4fc92f5119b573ed16efcd58e718ea9c9`.
- Source/test manifest: `b1fb6aff09a904f5b081ebf6693e2c9cf07f920672cc4425dd76f401dced7b79`.

## Files

| File | Purpose |
|---|---|
| [verification.json](verification.json) | Findings, commands, local gate identities/results, CI summary, native platform probe, benchmark smoke and evidence limits |
| [scope.json](scope.json) | All 563 baseline files, review methods/reasons/risks/test mappings and hashes; five new test files; complete 13-file change review |
| [survivors.json](survivors.json) | All 477 prior catalogue diffs and classifications, explicit current-reason corrections, and the final 468-entry correspondence/classification index; eight focused exact executions are distinguished from 469 static-only entries |
| [ci.json](ci.json) | Final run/head/jobs, artifact identity, coverage and raw mutation accounting |
| [coverage.xml.gz](coverage.xml.gz) | Lossless compression of the downloaded final Clover XML |
| [manifest.json](manifest.json) | Delivery file hashes and every raw-archive member's path, byte length and SHA-256 |
| [raw-evidence.tar.gz](raw-evidence.tar.gz) | Original gate records/logs/JUnit, agent proofs and patches, build/dependency records, CI logs and original coverage ZIP/XML |

The manifest fingerprint is SHA-256 of Python `json.dumps(path_to_file_sha256_map, sort_keys=True)` encoded as UTF-8 with default separators. It covers all 423 files under tracked `src/` and `tests/`, including fixtures and test infrastructure. Every accepted local final gate has stable before/after file maps matching the final commit. The report/evidence publication changes documentation; it preserves these source/test bytes.

## Raw archive layout

Paths in the archive are relative and verified against the member manifest. Original absolute workspace paths inside captured JSON/logs document where a process ran. Adapt those paths when reproducing a command.

- `audit-round5/gates/`: baseline and final full local tests, configured PHPStan/style, Composer checks, focused integration tests, standalone native platform probe and ten serial benchmark smoke runs. Each JSON records source/test fingerprints, exact command, duration, exit status and log checksum. Full JUnit distinguishes added/removed cases and environment skips.
- `audit-round5/core/evidence/invokable-exception/`: original/fixed final-byte proof for INV-EX-1, foreign-throwable controls and selected-neighbor verification.
- `audit-round5/core/evidence/setup-descriptors/`: exact Env/Config mutant witnesses, baseline/restored runs and explicitly excluded fixture-development attempts.
- `audit-round5/core/evidence/cycle-runner-input/`: real old/new Python driver run under a labelled synthetic subprocess protocol, duplicate/cross-series controls, actual PHP version-query smokes and hashes. Synthetic worker values are not performance measurements.
- `audit-round5/attributes/nonpublic-proxy-proof-final.json`: canonical final-fixture RED/GREEN for PROXY-CTOR-1 on both stable runtimes. The final fixture SHA-256 is `37c987938752e570ddf06fdc38dda36dc903fa8d86747afc8926e7909beb1aeb`.
- `audit-round5/attributes/attribute-test-gaps-proof.json`: the exact LifecycleHook catalogue mutant and separate synthetic factory-owner clone witness.
- `audit-round5/parameters/`: single-parameter and malformed-request proof, finite lifetime-mutant control, per-file/document review and independent complete-change review. Source/test additions are committed in the repository; their proof records retain exact hashes.
- Per-agent ledgers and summaries retain their individual scopes. A sibling's executed result is explicitly attributed rather than counted as a new independent execution.
- `audit-round5/ci-final/`: final metadata, all five job logs, platform summaries, original coverage artifact, parsed metrics and any independent metric review.
- `audit-round5/ci-intermediate/`: the four-matrix PHPStan failure for the original unused-trait fixture, with two complete example job logs. The workflow's final aggregate status was cancelled after a newer push; the four matrix job failures themselves are retained.
- `runtime/`: official release metadata, archive/binary/build-log hashes, configure flags and logs for PHP 8.4.27/8.5.12. Source tarballs and compiled binaries are not duplicated in the archive.
- `audit-round5/*-publish.json`: exact published unit parents/trees/file hashes. `delivered-code-and-tooling.patch` captures the complete baseline-to-final implementation/tooling change.
- The `report-before-ci` snapshots match the first report review. Final report templates include the completed CI result and have a separate metric/narrative check.

## Fresh survivor correspondence and corrected reasoning

The final run has 468 survivors: 458 retained IDs, 10 added and 19 absent from the old catalogue. All retained printed diffs are unchanged. Each new ID has a unique printed-mutation predecessor in the changed source files: nine complete contexts match, while one preserves the changed lines with a changed neighboring guard. Ten pair-specific static reviews are in `ci-final/fresh-attribute-survivor-review.json` and `ci-final/fresh-core-survivor-review.json`. They do not establish new original-source defects or new runtime kills. Absence of the remaining nine old IDs from `untested` does not by itself distinguish `tested` from `timeout`.

The combined ledger's current `classification` and `reason` incorporate explicit correction overlays. Original per-agent detail and archive records retain their bytes and are marked historical/superseded where appropriate. In particular, `Proxy` cannot be subclassed because it is final; an ordinary invalid declaration name can still be a registered `class_alias`. Unsupported hypothetical derived-Proxy witnesses and blanket class-name equivalence claims are not part of the current conclusions. The final CI category `tested` is a worker's completed non-success outcome, not an independently checked semantic assertion failure for every mutant. MSI includes the separately reported 59 timeouts.

## Intermediates excluded from final gate claims

`gates/proxy-fixture-phpstan-red.json` records a command during which the source/test fingerprint changed. The runner rejected it as a stable gate; its analyzer output is neither canonical RED nor canonical GREEN. The original full-scope failure comes from CI; `proxy-fixture-phpstan-green` and `final-phpstan` are stable successful runs.

`gates/attribute-gaps-style.json` is an invocation error: PHP CS Fixer requires an explicit config when multiple paths are given. The corrected targeted command and full final style check passed. This error is not a code-style failure.

The first nonpublic-proxy proof belongs to the original fixture. Targeted PHPStan missed an unused trait; full CI found it. The trait was then used by an already exercised fixture without a suppression, and original/fixed behavior was re-executed on the exact final test bytes. Earlier records are retained as intermediate history.

The initial SetUp fixture used a dotted literal key where the configuration API requires `ConfigPath` for nested lookup. Those harness attempts are preserved and excluded from original-source semantic RED. The canonical 14 phases use the final ConfigPath fixture.

## Reproduction and scope

Use the source commit above with compatible dependencies. The installed local 101-package set and Composer metadata hashes are recorded in `runtime-dependencies.json`; CI installation details are retained independently in its raw logs. The local vendor copy was separate and its autoloader was regenerated for the selected checkout. The benchmark helper checks loaded production declaration/function origins.

Representative full checks from the repository root:

```bash
php -d memory_limit=1G vendor/bin/pest --order-by=random --random-order-seed=2026111606
php -d memory_limit=1G vendor/bin/phpstan analyse src tests --level=max --no-progress --memory-limit=1G
php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no
composer validate --strict
composer audit --no-interaction --format=json
```

The saved commands additionally identify the exact local binaries, `-n` mode and JUnit destinations. PHP 8.5's full local run used reverse ordering, CLI OPcache and JIT off. Local and Windows PDO SQLite absence explains one additional conditional skip; Linux CI executes that test. PHP 8.4/PHP 8.5 native-syntax conditions explain the remaining skips.

Execute mutation witnesses only in isolated source copies and restore/verify the exact source after each mutation. An original-source GREEN plus mutant RED establishes an oracle gap; it does not establish a production defect. Eight exact entries from the old 477-entry catalogue were executed locally, seven killed and one finite GREEN. The other 469 were statically reasoned. Fresh full-CI totals and timeout accounting are recorded separately in `ci.json`.

The standalone `tests/php85-platform.php` result is intentionally separate from the DI suite. On the recorded PHP 8.5.12 it fails eight affected engine-scope cases and passes two controls without loading DI. Release-archive checksums are established by separate build records; the semantic probe's own provenance flags remain false. E1 therefore still limits the affected native forms while the library retains PHP `^8.4`.

The five benchmark programs were smoke-run serially on both local runtimes. These runs verify executable harnesses and record a bounded final-source workload; no before/after optimization, application throughput or global memory-retention claim follows. Historical reports, archives and previous CI totals remain bound to their original revisions.
