# Standalone creation, attribute processing and request helpers

[Русская версия](standalone-api-surface.ru.md)

## Recheck after report preservation

The [subsequent audit](audit-architecture-recheck-2026-11-20.md#published-test-coverage) adds direct parameter, object-pipeline and entry-strategy contract tests on both supported PHP series. This strengthens coverage of current behavior without selecting a wider API support promise. The existing prohibition on sharing mutable collaborators between Containers remains in force.

**Status, 2026-11-20:** preserved architecture proposal, partly covered by later contract tests. No removal, visibility change or new runtime policy is selected. The remaining decision is which low-level surfaces are independently supported, and which exact preparation/callback guarantees they carry. Current source reference: [`43c4641`](https://github.com/componenta/di/tree/43c464187f80c95802fc36191350ae68b70a469e).

## Problem and provenance

The third full re-audit identified a support-policy gap: a public or protected method can have consumers outside this repository even when the current container pipeline does not call it. Removing it as unused, or testing every implementation detail merely to increase mutation coverage, would silently choose opposite compatibility policies. The original [English report](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round3.md#architecture-decisions-and-implementation-plans) and [expanded Russian plan](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round3.ru.md#6-решения-по-архитектуре-и-план-действий) were explicitly carried forward by the fourth audit. This document preserves that decision without treating its historical test inventory as current.

The issue concerns three related surfaces. It is separate from the implemented standalone composite/definition ownership contract in [definition-owner-lifecycle](definition-owner-lifecycle.md).

| Surface | Current behavior and existing evidence | Decision still needed |
|---|---|---|
| [`InstanceCreator`](../../src/Resolver/Entry/InstanceCreator.php) `create()` / `initialize()` | Both wrap prepared parameter resolution. [`InstanceCreatorContractTest`](../../tests/Contract/InstanceCreatorContractTest.php) covers explicit arguments, attributed dependencies, defaults, one constructor invocation, constructorless objects and native allocation errors. | Publish whether direct construction is a maintained extension API, its lifecycle limits, and which prepared methods are intended for downstream use. These paths are not currently untested. |
| `createPrepared()` / `initializePrepared()` and `beforeConstructor` | The object pipeline uses the prepared route. [`RequestConstructorSourceContractTest`](../../tests/Contract/RequestConstructorSourceContractTest.php) and [`RequestConstructorMetadataContractTest`](../../tests/Contract/RequestConstructorMetadataContractTest.php) already protect relevant final checks before constructor invocation. | State callback count and its position relative to argument resolution and allocation, including the constructorless case. Current `createPrepared()` invokes the callback for a constructorless class; `initializePrepared()` returns without it when no constructor exists. Whether to harmonize these cases is a compatibility decision, not an automatic bug fix. |
| [`AttributeProcessor::prepare()`](../../src/Resolver/Attribute/AttributeProcessor.php) and direct processing | `prepare()` builds the execution plan; it does not execute handlers, but may autoload and execute composition rules. This is not a purity or zero-user-code guarantee. [`StandaloneAttributeProcessorContractTest`](../../tests/Contract/StandaloneAttributeProcessorContractTest.php) covers independent before/after phases, repeated creation and property write effects without a bootstrap collaborator. [`AttributeProcessorRevisionContractTest`](../../tests/Contract/AttributeProcessorRevisionContractTest.php) protects availability refresh. | Decide whether preparation promises eager validation at that call boundary or is an optional warmup whose precise validation timing may change. Specify what late discovery can still invalidate after successful preparation. |
| Protected [`ExtractsRequestData::extractSharedData()`](../../src/Attribute/ExtractsRequestData.php) | [`RequestAttributeProtocolContractTest`](../../tests/Contract/RequestAttributeProtocolContractTest.php) already exercises this helper through `SharedSourceMapper::extract()`, with selection, transformation, fresh request values and source conflicts. Custom mappers remain supported in [mapping examples](request-mapping-examples.md). | State whether this particular protected helper, rather than only the public extraction/mapping protocol, is a maintained subclass hook. Preserve tested behavior during that decision. |

These observations identify a compatibility surface, not a demonstrated security vulnerability. They do not establish that all public implementation collaborators should be removed or declared internal. Nor does the existing test suite prove that every downstream invocation form has been inventoried.

## Why this needs a decision

The high-level pipeline and a standalone caller do not necessarily own the same work. The pipeline supplies policy callbacks and guards, while a direct caller chooses collaborators and may supply no callback. A support promise must say which layer owns attribute phases, validation, request-source checks, allocation and error wrapping. A method name alone is insufficient to infer those responsibilities.

In particular, silently making `prepare()` a no-op can change when a caller sees an invalid composition. Making it eagerly instantiate attribute arguments can add user-code effects to a previously metadata-oriented step. Similarly, invoking a previously omitted callback during constructorless initialization adds an effect even though the object has no constructor. Any such change needs a selected public contract and a consumer-visible regression or migration case.

## Options and recommendation

| Option | Benefit | Cost and compatibility |
|---|---|---|
| Explicitly support the selected standalone methods | Consumers can use lower-level integration points with documented lifecycle and error guarantees. Tests cover actual extension behavior. | Every selected guarantee must survive internal optimization; examples and regression coverage need maintenance. |
| Keep selected methods as implementation APIs with a transition | Internal planning and caching can evolve without promising every callable surface as a permanent extension point. | Requires consumer inventory, explicit documentation and a compatible deprecation/migration path. Adding `@internal` does not itself make an existing consumer disappear. |
| Remove or reduce visibility immediately because the pipeline does not use a wrapper | Smaller apparent surface. | Rejected as an ungrounded change: repository callers are not the complete downstream population. |

**Recommendation:** preserve current behavior while completing a focused support inventory. Prefer documenting the already useful and tested standalone routes if known integrations use them. Do not add another facade or duplicate pipeline merely to resolve documentation ambiguity. Restrict or deprecate a surface only with a concrete migration target and compatibility rationale.

No speedup, memory reduction or security hardening is claimed from this policy work. Its benefit is a reviewable compatibility boundary and useful tests which protect real application behavior.

## Implementation plan after the decision

1. **Inventory consumers.** Record README/migration examples, published API documentation, repository tests and known downstream Composer consumers. For unavailable downstream source, mark usage unknown. Do not equate a zero-result repository search with no consumers.
2. **Publish a support matrix in both languages.** For each method identify the supported inputs, required collaborators, validation timing, handler execution, callback ownership, exception guarantees and lifetime. Explicitly distinguish direct creation from the complete container lifecycle; direct methods must not imply container features they do not run.
3. **Choose preparation semantics.** Decide what `prepare()` proves immediately, what can change with a later availability revision, and whether a subsequent `process()` must still validate. Retain current late-discovery and completed-prefix obligations; successful preparation must not freeze an unavailable attribute forever.
4. **Choose callback semantics.** Define omitted/supplied callbacks for constructorless and argument-bearing creation and initialization. State the exact invocation count and position before allocation or constructor invocation. Preserve request-source checks and exception identity/wrapping where already established.
5. **Make only the necessary implementation change.** Use the existing prepared route as the shared implementation. If deprecation is selected, first document a replacement and prove it handles actual consumer scenarios. Do not remove protected hooks or change visibility in this documentation step.
6. **Add missing public-contract witnesses.** Use direct public calls or a real subclass exposing extraction through its public method. Avoid reflection into private caches, private invocation counters or assertions on incidental message wording.
7. **Update examples and migration notes.** Mark implemented versus proposed promises clearly. Run the affected suite and the normal PHP 8.4/8.5 gates for any runtime change. Documentation-only clarification needs link/example verification rather than a fabricated performance claim.

## Acceptance matrix

| Scenario | Required observation under the chosen contract |
|---|---|
| Direct `create()` and `initialize()` with dependencies and explicit values | Correct values, defaults and one constructor invocation; initialization retains the supplied object identity. Existing evidence is retained. |
| Constructorless create/initialize, optional callback present/absent | Chosen count and timing are explicit; no callback is silently replayed to reach convergence. |
| Prepared parameter failure | Constructor/allocation and callback effects follow the selected boundary; DI exceptions and foreign causes retain their owner. |
| `prepare()` with an invalid composition | Error timing matches the documented eager-validation or warmup policy. |
| Late availability after prepare, cold/warm and direct/pipeline routes | Subsequent supported work observes current metadata; already completed work is not retroactively counted or replayed. |
| Custom mapper subclass | Selected request attributes/files, null values, duplicate-source conflicts, transformation and successive requests remain correct. |
| Reentrant or Fiber-separated calls | Invocation values and attribute instances remain isolated; a shared plan does not retain a request/receiver. |
| Downstream migration if a surface is deprecated | Every inventoried supported use has an executable replacement example; release notes state the compatibility effect. |

Completion means a published support matrix, reviewed consumer inventory and tests of the selected guarantees. A higher mutation score by itself does not close this proposal.

## Additional policy scope: conditional return types for class-name IDs

### Status, scope and current contract

**Evidence added on 2026-11-20, pass 23:** the conditional PHPDoc return types on [`Container::get()` / `make()`](../../src/Container.php) and [`FactoryInterface::make()`](../../src/FactoryInterface.php) promise `T` for a `class-string<T>` argument. The underlying registration API treats ordinary IDs as names: a class-name ID can identify an unrelated object, and `get()` can return a scalar. This is a confirmed mismatch between an unconditional static inference and an allowed configuration. It does not establish a runtime resolution defect or select a change to the API. The witness below was checked against [`748357f`](https://github.com/componenta/di/tree/748357fa00c108ab5be0d29f46eb1185c547eb42), with PHP 8.4.28, PHP 8.5.13 and PHPStan 2.3.5 at maximum level.

The exact surface is the concrete `Container::get()` PHPDoc and the concrete/interface `make()` PHPDoc. The native signatures already say `mixed` and `object`, respectively. PSR-11's `ContainerInterface::get()` does not gain the concrete container's generic promise. The existing standalone-creator support decision above and the [configuration normalization typing decision](configuration-normalization-contract.md) remain separate.

The [runtime behavior](../../README.md#runtime-behavior) allows local definitions to choose their product. `make()` only requires an object: it resolves a local definition or fallback and bypasses shared values, external containers and delegators. `get()` additionally admits seeded or replaced values, aliases, external results and a requested ID's delegator result. None of these ordinary IDs receives a general product-subtype check merely because its spelling denotes a PHP class. A restriction to compatible products would therefore change which applications are accepted, not just correct an annotation. Protected core identities have their own existing rules and are outside this proposal.

### Reproducible consumer witness

After loading the package's Composer autoloader, this uses only public configuration and factory APIs:

```php
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;

class Expected {}
final class Actual {}

function expected(Container $container): Expected
{
    return $container->make(Expected::class);
}

$container = (new ContainerFactory())->create(
    new Config([], new Environment([])),
    new DependencyDefinitions([
        ConfigKey::FACTORIES => [
            Expected::class => new ClassDefinition(Actual::class),
        ],
    ]),
)->container;
if (!$container instanceof Container) {
    throw new RuntimeException('Unsupported container implementation.');
}

$actual = $container->make(Expected::class); // Runtime product: Actual.
expected($container); // Native return-type TypeError in expected().
```

The consumer function passes PHPStan at `level: max`, including the repository's `treatPhpDocTypesAsCertain: false` setting. Changing only its call to `get(Expected::class)` also passes. A wrapper taking `FactoryInterface` and calling `make(Expected::class)` passes as well. At runtime all three wrappers fail for the unrelated binding; direct `get()` and `make()` succeed and return `Actual`. The failure belongs to the consumer's native return declaration, outside DI's completed resolution. Both cold and warm shared `get()` exhibit the same result.

The independent controls distinguish that mismatch from a general analyzer or DI failure:

| Control | Observation on both PHP versions |
|---|---|
| Ordinary autowiring of `Expected` | All three typed wrappers return `Expected`; shared `get()` identity and fresh default `make()` identity remain correct. |
| An explicit `ClassDefinition` for a subclass of `Expected` | All typed wrappers return the compatible subclass. |
| Factory registered under `Expected::class` returning `7` | Direct `get()` returns `7`; its typed wrapper throws a native `TypeError` on cold and warm calls. No object result is claimed for scalar `make()`. |
| Opaque ID with an unrelated object definition | Wrappers declaring `mixed` for `get()` and `object` for `make()` pass static analysis and return that object. An opaque scalar service also works with the `mixed` wrapper. |
| Native function returning an `object` or `mixed` parameter as `Expected` | PHPStan reports `return.type`; a compatible object succeeds at runtime, while an unrelated object or scalar causes the same native `TypeError`. No ignore, cast or asserted type is used. |

This witness is evidence about the advertised generic inference. It is not a new mutation-score target and does not require a permanent test that forces an unsafe wrapper to remain accepted by the analyzer.

### Options and compatibility costs

| Option | Benefit | Cost and obligations |
|---|---|---|
| A. Describe unrestricted lookup accurately: `mixed` for `get()`, `object` for `make()` | Static results match every currently supported registration. Runtime inputs, resolution and identities can stay unchanged. | Removes convenient inferred types from ordinary class autowiring and compatible interface bindings. Consumers may need explicit verified narrowing; release notes, repository examples and a downstream inventory are required. An annotation-only patch can still be a significant static API break. |
| B. Offer an explicitly verified typed operation, alongside accurate unrestricted lookup | A `class-string<T>` result promise can be established by checking the actual result before returning it. A separately supplied expected class could also support opaque IDs. | Adds a public operation or facade to maintain. Define naming, class/interface inputs, mismatch exception, autoload effects, and the relation between requested ID and expected type. It must perform exactly one underlying lookup/creation, preserve successful product identity and avoid replaying factories, delegators or callbacks. Add it only for demonstrated consumer demand. |
| C. Retain conditional inference as an explicit application assumption | Preserves existing static ergonomics and avoids immediate migration for applications that maintain compatible class-name bindings. | Document that the application must preserve the subtype invariant through registration, aliases, mutation, delegates and providers as applicable. The analyzer cannot infer this invariant from the current APIs, and documentation does not make the unrestricted promise sound. Accept this only as a deliberate policy or transition, with clearly scoped examples and a stated limitation. |

Silently adding `instanceof`/`is_a` enforcement to current `get()` or `make()` is excluded from this documentation change: it would reject a valid opaque-ID registration and, for `get()`, constrain arbitrary delegated or external values. Silently weakening the three PHPDocs would choose option A without assessing its consumers. Neither is an automatic consequence of this proof.

**Recommendation and trigger:** inventory consumer usage before selecting the static API policy. Prioritize a decision when an actual integration uses class-name IDs with unrelated products, accepts mutable or plugin-provided registrations, or relies on these generic results to enter a typed boundary. For a guarantee intended to cover every accepted configuration, use accurate unrestricted return types as the baseline and consider a verified typed operation only when its ergonomic benefit is demonstrated. If the project instead retains the application assumption, publish that limitation explicitly; do not describe it as a runtime-enforced class guarantee. This proposal leaves today's behavior and annotations unchanged while that choice is made.

### Implementation sequence and acceptance

1. **Inventory all three declaration surfaces and their consumers.** Include concrete-container calls, calls through `FactoryInterface`, ordinary autowiring, compatible interface bindings, class-name aliases, factories, raw services and mutable registrations. Treat unavailable downstream code as unknown. Keep `make()`'s bypass of delegators/external containers explicit.
2. **Select and publish the guarantee in both languages.** Decide whether class-name inference is unconditional, explicitly verified or conditional on an application-maintained invariant. Specify migration and release compatibility before changing PHPDoc or adding an operation.
3. **Preserve the runtime contract of existing methods.** A static policy change must not incidentally forbid valid bindings, change shared/fresh identity, add lookup replay or move application callbacks. Any separately proposed restriction needs its own evidence and approval.
4. **Implement the smallest chosen surface and examples.** For a verified operation, define one lookup followed by one result check, including interfaces, subclasses, unrelated objects, scalars and null. Define error ownership and timing without changing current generic lookup exceptions. For an assumption policy, show the configurations that satisfy and violate it.
5. **Verify a real consumer matrix.** Run focused PHPStan maximum-level fixtures without ignores, casts or false assertions, plus runtime controls on supported PHP 8.4/8.5. Under accurate returns, an unchecked `Expected` wrapper must no longer pass. Under a verified operation, its `T` promise must hold or produce the documented mismatch error before returning. Under the assumption policy, the existing counterexample remains an explicit limitation rather than a closed correctness defect.

Acceptance also requires cold/warm shared results, alias and mutation transitions relevant to `get()`, and positive autowire/compatible-binding/opaque-ID cases. Test delegated or external mismatches only for an operation that actually uses those paths. Preserve one execution and object identity for successful verified lookups. PSR-11's native contract, protected core identities, constructor argument validation, application-code trust, low-level prepared callbacks and standalone collaborator-sharing rules are not redesigned by this policy decision. No security or performance improvement is claimed.
