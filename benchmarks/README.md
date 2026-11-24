# DI performance benchmarks

The primary suite uses PHPBench and the current checkout's Composer autoloader. PHPBench is a development dependency, never a runtime dependency. Its 1.x releases require the abandoned `doctrine/annotations` package. Benchmark discovery explicitly disables annotation parsing; a package-specific Composer abandoned-status exception allows this development dependency only, without disabling vulnerability or malware checks. Remove the exception when PHPBench no longer requires it.

## Run

Use the PHP version required by composer.json and install development dependencies:

```sh
composer install
composer bench
vendor/bin/phpbench run --config=phpbench.json.dist --filter=ContainerBench --report=aggregate --report=env
vendor/bin/phpbench run --config=phpbench.json.dist --filter=benchFirstGet --report=aggregate
```

Revolution and iteration defaults are declared on benchmark classes, with method-level overrides. Do not put `runner.revs` or `runner.iterations` in the main PHPBench configuration: PHPBench 1.x treats these as overrides of method metadata. Do not pass `--revs` when running the mixed suite or first-operation subjects. A first operation is measured once in each isolated PHPBench iteration; repeating it on the same container would measure a different operation. A quick discovery/execution check can reduce iterations (not revolutions):

```sh
composer bench-smoke
```

The smoke command uses the committed configuration, checks the actual PHPBench XML report against the declared subjects and parameter counts, and verifies revolutions, warmup and the single successful iteration for every variant. It rejects accidental global overrides and silent discovery omissions. The temporary report is deleted after verification.

To compare revisions, use separate checkouts with the same PHP build, extensions, Composer dependency versions, CPU/power settings and PHP configuration. Record OPcache/JIT and Xdebug settings. For example, export a baseline to an absolute path outside both checkouts with `vendor/bin/phpbench run --config=phpbench.json.dist --dump-file=/tmp/di-baseline.xml --report=aggregate`. In the candidate checkout compare using `vendor/bin/phpbench run --config=phpbench.json.dist --file=/tmp/di-baseline.xml --report=aggregate`. Use the same suite, fixture data, operation counts, and PHP configuration for both runs. Keep local results out of version control.

## Coverage

| Benchmark | Workload |
| --- | --- |
| BuildBench | Dependency normalization; cold-process, warm-process single assembly and repeated batch assembly; normal vs targeted discovery; empty, representative, service and alias-chain sizes |
| ContainerBench | First and shared get; first and repeated has, including after a shared get; scalar, null and false entries; reflection, shared dependency graph, invokable, factory, class definitions with nested references and repeated configured methods, alias, delegators; first and repeated make with default and override arguments |
| LifecycleBench | Assembly plus first graph resolution, attribute-injected object, or request-to-DTO callback; cold and warm PHP processes; normal vs targeted discovery |
| CallableBench | First and repeated call/resolve for closures, function names, object/class methods, static strings, invokable objects, callable service IDs and prepared callables; newly allocated closures |
| ParameterBench | Default/nullable, named/positional, variadic, union, custom parameter resolver and missing entry error |
| AttributeBench | Constructor and property attributes, configuration, environment fallback, explicit lookup, make, initialization, setup method and PHP 8.5 setup/transform callbacks, named caster, constructor suppression, targeted discovery and plan-cache parity |
| RequestBench | All individual PSR-7 extractors; seven single-source mappers and multi-source mapping; arrays of 1–4096 keys; DTO mapping, native callback, casting and raw-data validation; first/repeated and plan-cache modes; alternating request payloads |
| LazyBench | Explicit native lazy ghosts and virtual proxies, class and injection-point attributes, allocation, first state access and initialized access |
| MutationBench | Replacement of cached entries, alias retargeting, delegator registration, external provider registration, notification, first and repeated get and has |

## Measurement boundaries

- Build subjects construct configuration inputs outside the timer. The single-revolution warm-process subjects first assemble and release a container, then collect cycles before timing an independent assembly. Repeated assembly measures a batch of new containers in a warm process, including natural object retirement and possible garbage collection. Its process peak memory and timing are not interchangeable with isolated assembly results.
- Lifecycle subjects measure assembly plus one real resolution or request-handler invocation through the newly assembled container. Warm-process lifecycle subjects release the previous container and result and collect cycles before timing. Neither variant includes PHP startup, dependency installation, HTTP parsing or response emission.
- First get/has/make/call/resolve start with a newly assembled container and no prior target operation. The process and its autoloader are already bootstrapped; this is not total PHP startup or HTTP latency.
- Shared get reuses the same resolved entry. Repeated make creates new results with warmed metadata and may reuse shared dependencies. Repeated callable/request work uses warmed metadata but resolves inputs each time. Repeated request subjects pre-warm with a different payload; alternating-request subjects switch between two prebuilt PSR-7 requests and include the small index-switch overhead. The validation fixture records raw input and invocation count; assertions run outside the timed subject. A prepared callable is a different invocation contract, not a faster equivalent for an unresolved argument list.
- The attribute plan comparison follows the library's cache-parity contract fixtures: the same marker attribute is registered in both modes, with a no-op composition rule in the uncached-plan variant. The latter includes rule execution and is not a general cache-off mode. Other caches remain active.
- Lazy allocation does not initialize object state. First state access uses a fresh uninitialized object and one revolution. Initialized access uses a pre-initialized object.
- Mutations use independent, warmed containers and one revolution unless explicitly measuring repeated external lookup. Verification runs outside timed subjects. Request construction and input preparation are excluded.
- Use aggregate latency and relative standard deviation to compare cases; repeat noisy measurements. Single-revolution first-operation results are more sensitive to timer and process noise. Do not infer a regression from a small difference within that noise. PHPBench's peak memory is process-level memory, not bytes allocated by a single DI call; do not subtract unrelated peaks or divide them by revolutions.
- No absolute machine-dependent performance assertions are enabled. Check functional correctness with the contract suite separately (`composer test`).

## Targeted diagnostic scripts

The existing independent scripts in this directory are retained for their focused internal, scaling and historical cross-version experiments. They are not discovered by the main PHPBench suite. Most require `COMPONENTA_DI_BENCH_AUTOLOAD` and `COMPONENTA_DI_BENCH_SOURCE` to select the dependency installation and DI source. In particular, the legacy RuntimeBench and GeneratedVsReflectionBench are useful only when comparing compatible historical workloads. Use the PHPBench suite for current DI 6 performance reporting.
