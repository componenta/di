# Reproducing the DI optimization measurements

These commands reproduce the fixtures used in the November 15, 2026 optimization review. The reports and machine-readable evidence are in the parent directory. The published workers passed PHP 8.4/8.5 syntax and source-selection smokes; the Python drivers passed compile/help and small-count command smokes. Smoke timings are excluded from the reported performance aggregates.

## File set and invocation boundaries

| Repository file | Recorded worker source | Invocation / companion |
|---|---|---|
| `AttributePlanSelection.php` | `attributes/portable/AttributePlanSelection.php` | `--source` takes the **src directory**; `--autoload` takes `vendor/autoload.php`; explicit `--loader` permits a standalone copy. |
| `OptimizationWorkloads.php` | root `OptimizationWorkloads.php` | Positional **repository root**, `vendor/autoload.php`, iterations, builds. Loads `<selected-root>/benchmarks/autoload.php`. |
| `OptimizationComparison.py` | root `OptimizationComparison.py` | Calls adjacent `OptimizationWorkloads.php` unless `--worker` is supplied. Its result schema expects that 12-case worker. |
| `cycle-guard-benchmark.php` | `core/cycle-guard-benchmark.php` | Positional **repository root**, **vendor directory**, scale; requires adjacent `source-loader.php`. |
| `source-loader.php` | `core/source-loader.php` | Companion for the core worker; keep this filename and directory relationship. |
| `run-cycle-guard-benchmark.py` | `core/run-cycle-guard-benchmark.py` | Calls adjacent `cycle-guard-benchmark.php` and hashes adjacent `source-loader.php`; repeat `--php` with one binary per major/minor series. |

Two existing published files are also needed: `docs/audit/reproductions/ParameterTargetHelpers.php` and `benchmarks/autoload.php`. The helper takes a **repository root** and **vendor/autoload.php**, unlike the `AttributePlan` and core-worker CLI shapes. Do not substitute either helper worker into `OptimizationComparison.py`; their result schemas differ.

The common vendor must come from an existing **DI repository checkout**, with its generated Composer files intact. In particular, the preserved core loader remaps the eager DI functions path from `<vendor-parent>/src/Internal/functions.php`; an arbitrary consumer application's vendor tree does not satisfy that assumption. Use absolute paths and a POSIX shell. Run one comparison at a time with the same binary, vendor and settings; keep every result, including slower rounds.

## Prepare immutable source choices

Replace only the initial absolute paths. `DI_REPRO` points to a checkout containing these published reproductions. Both measured source commits must be present in that checkout.

```bash
set -euo pipefail
DI_REPO=/absolute/path/to/di
DI_REPRO="$DI_REPO/docs/audit/reproductions"
DI_RUN=/absolute/path/to/new-di-optimization-run
DI_PHP84=/absolute/path/to/php84
DI_PHP85=/absolute/path/to/php85
DI_VENDOR="$DI_REPO/vendor"
DI_BASELINE=39eca842285ebbe650456a8fe0009102d353aa99
DI_FINAL=874d76fbebd4402adc6f2bdd9bc4ee61d21bd883

mkdir "$DI_RUN"
for variant in baseline plan helper core; do
    git -C "$DI_REPO" worktree add --detach "$DI_RUN/$variant" "$DI_BASELINE"
done
git -C "$DI_REPO" worktree add --detach "$DI_RUN/final" "$DI_FINAL"

git -C "$DI_REPO" show "$DI_FINAL:src/Attribute/Composition/AttributePlan.php" \
    > "$DI_RUN/plan/src/Attribute/Composition/AttributePlan.php"
git -C "$DI_REPO" show "$DI_FINAL:src/Resolver/Target/ParameterTarget.php" \
    > "$DI_RUN/helper/src/Resolver/Target/ParameterTarget.php"
git -C "$DI_REPO" show "$DI_FINAL:src/Internal/CycleGuard.php" \
    > "$DI_RUN/core/src/Internal/CycleGuard.php"

for variant in plan helper core; do
    git -C "$DI_RUN/$variant" diff --name-only -- src
done
```

The three isolated candidate worktrees intentionally have one modified production file each. They select exact published file bytes while retaining baseline code everywhere else. Their checkout HEAD remains the baseline; record both that HEAD and the selected file hash. The final worktree selects all three published changes. Plan and helper final file bytes match their measured isolated candidates. The final `CycleGuard.php` adds only the private `Fiber<mixed, mixed, mixed, mixed>|null` doc annotation to the measured prototype; exact executable-token identity is recorded in the final annotation proof.

This isolation is required by the preserved core driver: it asserts **169 files** and exactly `src/Internal/CycleGuard.php` changed, and its evidence labels the baseline as `39eca842...`. Passing the full final tree against baseline fails that intentional one-file check. Passing adjacent `b0e7549` and `874d76f` commits would satisfy the changed-path check but mislabel the baseline, so use the baseline-plus-one-file tree above.

## Five-round comparisons

### Combined final behavior paths

The output directory must not already exist. The driver runs 40 measured workers: five ABBA rounds on each runtime, using 10,000 ordinary operations and 100 fresh container builds per worker.

```bash
python3 "$DI_REPRO/OptimizationComparison.py" \
    --baseline "$DI_RUN/baseline" --candidate "$DI_RUN/final" \
    --autoload "$DI_VENDOR/autoload.php" \
    --php84 "$DI_PHP84" --php85 "$DI_PHP85" \
    --output "$DI_RUN/combined-results" \
    --rounds 5 --iterations 10000 --builds 100
```

`evidence.json` contains raw-log identities, commands, source manifests, process samples and both ratio-of-medians and paired-round statistics. Both source trees must include byte-identical `benchmarks/autoload.php`, as they do at the specified commits.

### CycleGuard alone

Use a fresh output directory here too; this historical driver permits existing directories, so reusing a directory could overwrite earlier logs. The recorded qualification used **scale 8192**, while the standalone worker/driver default is 32768; supply 8192 explicitly to reproduce the recorded operation counts.

The driver now checks all selected PHP versions before creating the output directory or inspecting source trees. Repeating a major/minor series, including the same binary twice, is rejected: process log names use that series, so two PHP 8.4 builds would otherwise overwrite each other's logs. Use separate fresh output directories when comparing patch versions from the same series. One PHP 8.4 binary plus one PHP 8.5 binary remains supported.

```bash
python3 "$DI_REPRO/run-cycle-guard-benchmark.py" \
    --baseline-source "$DI_RUN/baseline" --candidate-source "$DI_RUN/core" \
    --vendor "$DI_VENDOR" --php "$DI_PHP84" --php "$DI_PHP85" \
    --output "$DI_RUN/core-results" --rounds 5 --scale 8192
```

This is 40 measured workers plus two untimed PHP-version queries. The core driver preserves its measured protocol and text-mode stdout/stderr handling; logs are decoded/re-encoded text, unlike the combined driver's byte-preserving logs. The historical driver checkpoints after each completed round; if a process fails, keep its saved raw log files and the previous checkpoint. Do not retry into the same directory or present an incomplete series as passed.

### AttributePlan and ParameterTarget helpers

The fixed public-helper loops have different output schemas, so retain one JSON/log pair per process and compare each suite separately. This wrapper gives each suite five ABBA rounds on each runtime. `set -e` stops on an unsuccessful worker and preserves its output.

```bash
mkdir "$DI_RUN/plan-results" "$DI_RUN/helper-results"
run_helper_abba() {
    local suite=$1 runtime_tag=$2 binary=$3 round position variant
    for round in 1 2 3 4 5; do
        position=0
        for variant in baseline "$suite" "$suite" baseline; do
            position=$((position + 1))
            local stem="$DI_RUN/$suite-results/$runtime_tag-r$round-$position-$variant"
            if [ "$suite" = plan ]; then
                "$binary" -n -d opcache.enable_cli=0 -d opcache.jit=0 \
                    "$DI_REPRO/AttributePlanSelection.php" \
                    --source="$DI_RUN/$variant/src" \
                    --autoload="$DI_VENDOR/autoload.php" \
                    --loader="$DI_REPO/benchmarks/autoload.php" \
                    --iterations=100000 --exception-iterations=500 \
                    > "$stem.stdout.json" 2> "$stem.stderr.log"
            else
                "$binary" -n -d opcache.enable_cli=0 -d opcache.jit=0 \
                    "$DI_REPO/docs/audit/reproductions/ParameterTargetHelpers.php" \
                    "$DI_RUN/$variant" "$DI_VENDOR/autoload.php" 500000 \
                    > "$stem.stdout.json" 2> "$stem.stderr.log"
            fi
        done
    done
}
run_helper_abba plan php84 "$DI_PHP84"
run_helper_abba plan php85 "$DI_PHP85"
run_helper_abba helper php84 "$DI_PHP84"
run_helper_abba helper php85 "$DI_PHP85"
```

For each case/runtime/variant, take the median of its ten process samples: `samples[case].ns_per_call` for AttributePlan, `samples[case].ns_per_operation` for ParameterTarget. Report candidate median minus baseline median in ns, and `100 * (candidate median / baseline median - 1)` in percent. Separately compare each round's two baseline and two candidate sample means. Retain those five signed changes and the unchanged helper controls; do not remove outliers or mix paired-median statistics with the ratio of overall medians.

## Targeted follow-up for transform and build variance

The separate `TargetedOptimizationComparison.py` and adjacent `TargetedOptimizationWorkloads.php` reproduce the predeclared follow-up. Keep the original combined results: this series answers its directional `transform/one` PHP 8.4 observation and short build intervals. It does not replace the original 40 processes.

```bash
python3 "$DI_REPRO/TargetedOptimizationComparison.py" \
    --baseline "$DI_RUN/baseline" --candidate "$DI_RUN/final" \
    --autoload "$DI_VENDOR/autoload.php" \
    --php84 "$DI_PHP84" --php85 "$DI_PHP85" \
    --output "$DI_RUN/targeted-results" \
    --rounds 7 --iterations 50000 --builds 2000
```

There are 56 strictly serial processes: seven ABBA rounds per runtime. The selected cases are unchanged shared `get`, one `Transform`, and fresh default container builds. The operation timer, fixtures and setup are byte-identical to the main worker; filtering occurs outside timing, so the sequence of preceding workloads differs. Each build sample sums 20 independent batches of 100 builds, preserving the original collection boundary and retained cyclic graphs. Each batch performs its own warmup and verification outside its elapsed time. The evidence retains all 1,120 raw build batches and validates their sums and operation counts.

The worker rejects build counts that are not positive multiples of 100. Preserve a new output directory and every sample; interpret the two campaigns separately. No automatic rerun, outlier removal, performance threshold or application-latency conclusion is introduced by this follow-up.

## Scope of the reproduction proof

The original publication copied the six PHP/Python files byte-for-byte from their reviewed originals identified in the evidence. The current `run-cycle-guard-benchmark.py` additionally validates runtime-series uniqueness before starting work; it no longer has whole-file identity with the historical driver. This untimed input check leaves the worker, timed bodies, ABBA order and historical results unchanged. The two additional targeted-follow-up files exactly match the separately measured worker and driver. `AttributePlanSelection.php` is the portable adaptation: the original loop functions, case list and timed body are unchanged, while its untimed CLI/source-manifest observer differs from the historical measured worker. Do not claim whole-file identity between that portable file and the historical worker.

All source-selection checks must pass. AttributePlan hashes the full selected source at startup and verifies its loaded class; the common benchmark loader checks loaded DI declarations/functions. The core worker checks all loaded production declarations and internal functions against the selected tree. The helper verifies ParameterTarget and TypeHints origins and that its loaded-case loops invoke no autoload callbacks. Those helper checks are not a proof that all application code comes from the selected tree.

The combined driver records full source fingerprints at campaign boundaries and loaded-source checks in every process. Its dependency hashes cover Composer metadata, not every vendor file. The core source-loader is POSIX-path oriented and intended for this DI checkout layout. Cold shared factory get measures a first get on prebuilt containers; default build measures new containers in a running warmed process; Fiber cases run in one uninterrupted Fiber. None of these measurements predicts application latency or concurrent throughput.
