# Mutation testing

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

Mutations on declarations and other lines without a per-test coverage mapping are executed against the full test suite by `MutationFallback`. When parallel execution is enabled, these full-suite checks run in batches using the requested process count. Their outcomes stay in memory for this run and are handed back to Pest when it encounters each mutation. This records the actual test outcome instead of treating missing coverage metadata as evidence that the code was never exercised. It does not add covered lines or exclude source files. The control output and each fallback outcome are saved under `.phpunit.cache/mutation-workers/` for inspection.

The reported Pest score includes timeouts. Review timeout cases separately and report the fraction detected by completed test runs as well when assessing the threshold.

At the mutation suite's finish event, the parent writes `.phpunit.cache/mutation-report.json`. The versioned JSON contains the checkout root and commit when available, PHP and tool versions, command arguments, source-file SHA-256 hashes, outcome counts and each mutation's ID, relative file, lines, mutator, plain-text diff and result. `complete` means every mutation selected for this run has an outcome other than `none`; it does not mean every source file was selected or every mutation was detected. Timeout remains a separate outcome. Pest's score is recorded unchanged.

Durations are recorded in seconds only when Pest measured them. Fallback processes do not populate Pest's timing fields, so their duration is `null` with reason `not_recorded_by_pest`, not a misleading zero. A selected mutation that was never run has reason `not_run`. Existing fallback output is linked by ID in `.phpunit.cache/mutation-workers/<id>.log`; those logs do not cover Pest's ordinary workers. The local worker directory can retain files from earlier runs: only IDs present in the current manifest belong to its selected scope. CI starts from a clean checkout.

Mutation IDs include the absolute source path. `--id=<id>` can select a repeat run in the same checkout path, but an ID copied from CI may not match a different local path. Use the recorded commit, relative file, mutator and diff to find the same change in another checkout; verify the source-file hash and compare the recorded PHP and tool versions. `head` prefers the actual Git checkout, falling back to `GITHUB_SHA` if Git cannot identify it. File hashes are collected at the finish event, so keep sources unchanged throughout a run; the report does not detect edits made while mutations execute. It does not contain full mutated source files or capture scheduling and machine load, so a timeout still requires a controlled repeat run.

CI uploads the manifest and fallback logs as the separate `mutation-php-8.4` artifact even when the mutation step fails. The launcher removes the previous manifest before a new parent mutation run; control and mutation children neither clear nor overwrite it. If bootstrap, initial tests or the runner fail before the finish event, there is no new manifest, and an artifact can contain only the available worker logs or be absent. A missing report is not a successful or complete mutation run.
