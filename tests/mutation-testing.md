# Mutation testing

Install the package's Composer development dependencies, then run:

```sh
composer test-mutation -- --parallel --processes=8
```

This command runs the initial coverage suite in one process and tests mutations in parallel. Composer's overall process timeout is disabled for this command; Pest still limits each mutation separately. Xdebug is disabled in child processes after the initial coverage report is collected. It requires a coverage driver (PCOV or Xdebug) and enforces the existing 80% score threshold. Additional Pest mutation options, such as `--path=src/Internal/functions.php`, can select a focused run; its score only describes the selected files.

When using Xdebug, set `XDEBUG_MODE=coverage` and `COMPOSER_ALLOW_XDEBUG=1` in the command environment so Composer does not restart without the coverage driver. PCOV does not need these variables.

Use the Composer command instead of calling `pest --mutate` directly. The test launcher defers DI's Composer `autoload.files` entry until PHPUnit bootstrap, when Pest can substitute mutated functions. The command also disables CLI OPcache for the parent and child processes so previously compiled code cannot hide a mutation.

Mutation selection uses whole test classes to keep child commands within Windows process limits. This includes every covering test and may run additional methods from those same classes; source line coverage and the score threshold are unchanged. Only Pest's temporary mutation coverage report is rewritten.

The launcher supplies the PHP executable explicitly for child processes, normalizes Pest's quoted filters, and keeps mutation worker options out of PHPUnit's arguments. Production autoloading is unchanged. `MutationBootstrapContractTest` checks both an unmodified file and a replacement that must fail before tests execute.

A surviving mutation is a candidate for investigation, not proof of a defect. Check the observable contract before adding tests or ignoring a mutation; do not lower the threshold to make a run green.
