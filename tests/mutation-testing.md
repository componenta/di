# Mutation testing

[Русская версия](mutation-testing.ru.md)

Install the package's Composer development dependencies, then run:

```sh
composer test-mutation -- --parallel --processes=8
```

This command runs the initial coverage suite in one process and tests mutations in parallel. Composer's overall process timeout is disabled for this command; Pest still limits each mutation separately. Xdebug is disabled in child processes after the initial coverage report is collected. It requires a coverage driver (PCOV or Xdebug) and enforces an 85% mutation score threshold. Additional Pest mutation options, such as `--path=src/Internal/functions.php`, can select a focused run; its score only describes the selected files.

When using Xdebug, set `XDEBUG_MODE=coverage` and `COMPOSER_ALLOW_XDEBUG=1` in the command environment so Composer does not restart without the coverage driver. PCOV does not need these variables.

Use the Composer command instead of calling `pest --mutate` directly. The test launcher defers DI's Composer `autoload.files` entry until PHPUnit bootstrap, when Pest can substitute mutated functions. The command also disables CLI OPcache for the parent and child processes so previously compiled code cannot hide a mutation.

Mutation selection uses whole test classes to keep child commands within Windows process limits. This includes every covering test and may run additional methods from those same classes; source line coverage and the score threshold are unchanged. Only Pest's temporary mutation coverage report is rewritten.

The launcher supplies the PHP executable explicitly for child processes, normalizes Pest's quoted filters, and keeps mutation worker options out of PHPUnit's arguments. Production autoloading is unchanged. `MutationBootstrapContractTest` checks both an unmodified file and a replacement that must fail before tests execute.

A surviving mutation is a candidate for investigation, not proof of a defect. Check the observable contract before adding tests or ignoring a mutation; do not lower the threshold to make a run green.

The bootstrap self-check also runs safely inside an active mutation. It temporarily restores the native file wrapper in the parent while launching its isolated child processes, then restores mutation substitution in `finally`. This keeps Windows process-pipe locking independent of Pest's file wrapper; each child still applies its own requested substitution.

Mutation workers preserve the parent PHP configuration (`-c` or `-n`, extension directory and required extensions). Before mutation generation, the launcher executes the unmodified full test suite using the same worker command. A failed control run aborts mutation testing; failures caused by a broken worker environment must not count as detected mutations.

The mbstring check uses the selected worker configuration. A module supplied through the parent's CLI flags is preserved even when an ini is loaded; a built-in module or one already loaded by the worker's ini is not loaded twice.

Mutations on declarations and other lines without a per-test coverage mapping are executed against the full test suite by `MutationFallback`. When parallel execution is enabled, these full-suite checks run in batches using the requested process count. Their outcomes stay in memory for this run and are handed back to Pest when it encounters each mutation. This records the actual test outcome instead of treating missing coverage metadata as evidence that the code was never exercised. It does not add covered lines or exclude source files. The control output and each fallback outcome are saved under `.phpunit.cache/mutation-workers/` for inspection.

Every running fallback worker is polled so both output pipes keep draining while other workers are active. Each worker retains its own timeout from process start. A verbose worker can therefore finish without waiting for an earlier worker, and a genuinely timed-out worker still reports `timeout`. Contract tests use real processes and output exceeding pipe capacity on both stdout and stderr; they also check successful and failing exits, report contents and reuse of primed results.

The reported Pest score includes timeouts. Review timeout cases separately and report the fraction detected by completed test runs as well when assessing the threshold.

At the mutation suite's finish event, the parent writes `.phpunit.cache/mutation-report.json`. The versioned JSON contains the checkout root and commit when available, PHP and tool versions, command arguments, source-file SHA-256 hashes, outcome counts and each mutation's ID, relative file, lines, mutator, plain-text diff and result. `complete` means every mutation selected for this run has an outcome other than `none`; it does not mean every source file was selected or every mutation was detected. Timeout remains a separate outcome. Pest's score is recorded unchanged.

Durations are recorded in seconds only when Pest measured them. Fallback processes do not populate Pest's timing fields, so their duration is `null` with reason `not_recorded_by_pest`, not a misleading zero. A selected mutation that was never run has reason `not_run`. Existing fallback output is linked by ID in `.phpunit.cache/mutation-workers/<id>.log`; those logs do not cover Pest's ordinary workers. The local worker directory can retain files from earlier runs: only IDs present in the current manifest belong to its selected scope. CI starts from a clean checkout.

Mutation IDs include the absolute source path. `--id=<id>` can select a repeat run in the same checkout path, but an ID copied from CI may not match a different local path. Use the recorded commit, relative file, mutator and diff to find the same change in another checkout; verify the source-file hash and compare the recorded PHP and tool versions. `head` prefers the actual Git checkout, falling back to `GITHUB_SHA` if Git cannot identify it. File hashes are collected at the finish event, so keep sources unchanged throughout a run; the report does not detect edits made while mutations execute. It does not contain full mutated source files or capture scheduling and machine load, so a timeout still requires a controlled repeat run.

CI uploads the manifest and fallback logs as the separate `mutation-php-8.4` artifact even when the mutation step fails. The launcher removes the previous manifest before a new parent mutation run; control and mutation children neither clear nor overwrite it. If bootstrap, initial tests or the runner fail before the finish event, there is no new manifest, and an artifact can contain only the available worker logs or be absent. A missing report is not a successful or complete mutation run.

## Native subprocess contracts and mutation selection

Cold-container fixtures run in separate native PHP processes. Their `SubprocessAutoload` bootstrap applies an inherited Pest mutation before loading the target, including a matching Composer `autoload.files` entry. These children do not contribute executed lines to the parent's coverage report.

The mutation selection adapter therefore declares these source-specific dependencies for mutations on already covered lines:

| Source | Additional registered contract | Boundary |
|---|---|---|
| `src/Container.php` | `ContainerColdMutationContractTest` | Cold protected-alias validation |
| `src/ContainerFactory.php` | `ContainerFactoryCompletionContractTest` | Container initialization before the factory returns |

Each additional contract must be registered in the current Pest suite. Registered paths are canonicalized with `realpath()` before comparison. A scoped invocation that did not load a listed test file does not gain its selector or silently load additional tests.

The values `P\Tests\Contract\ContainerColdMutationContractTest::` and `P\Tests\Contract\ContainerFactoryCompletionContractTest::` are the generated class selectors for the pinned Pest 4 version. These are worker filter dependencies, not claims that the classes executed the source lines in the parent process. The adapter already uses the temporary mutation coverage file to transport whole-class selectors to Pest. These additions use that same transport; they do not add source files, executable lines or coverage hits. Empty and `null` line entries remain unchanged. The original coverage input is not modified in place.

`MutationCoverage::covers()` and uncovered-mutant fallback use a compact mask of measured covered lines captured before selectors are added. Keeping only that mask avoids retaining the much larger per-test coverage relation map during mutation execution. The fallback policy and covered/uncovered classification are unchanged.

These declarations are intentionally limited to the two listed sources. Other native subprocess coverage boundaries still require explicit review; this is not general coverage aggregation for child processes. Existing cold bootstrap/retry cases can also be selected through their shared test class when an in-process test covers the relevant line, but that does not establish complete child line coverage for every source file. When Pest is upgraded or a listed contract file is renamed, verify its generated selector and bounded worker regression again.

The regression uses a real `ForeachEmptyIterable` mutation of the protected-alias validation loop. With `RuntimeMutationContractTest` and `ContainerColdMutationContractTest` in the initial suite, measured coverage alone selects only the former and the mutant survives. Adding the declared cold selector makes the existing alias case fail because the protected-target exception is missing and the public alias resolves to `decorated-core`. An identity replacement passes. A run containing only `RuntimeMutationContractTest` retains its original selector and passes its identity control. This checks source behavior and selection without manufacturing coverage for the native child.

The factory completion contract uses a cold autoloader failure to check that construction reports collaborator initialization errors before returning a container, preserves the original cause and permits retry. Removing the final `get(Config::class)` violates that contract. Other tests can cover this line in the parent process while the cold contract remains absent from its measured test IDs; its empty-factory case does not reach completion. The explicit factory mapping keeps the registered cold contract in the worker selection for that mutation. `MutationCoverageContractTest` checks both source mappings, unrelated sources, unregistered contracts, unchanged input and preservation of empty and `null` line entries.
