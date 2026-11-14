# Definition ownership and mutation retirement

**Canonical specification:** this English document. [Full Russian translation](definition-owner-lifecycle.ru.md).

**Implementation status (2026-11-14):** the central binding table, registration-free strategies, snapshots, attachment rules and complete mutation retirement are implemented on `refactor/di6-definitions-callbacks` in commit `87d7a775`. This is unreleased next-major work; the compatible 5.x line retains its earlier architecture. See the [migration guide](../migrations/di6-definitions.md) and [implementation evidence](../audit/2026-11-14-di6-refactoring.md) for verification and remaining gates. The baseline analysis below describes the audited 5.x implementation. The normative contract and acceptance criteria remain the implementation target.

## Objective and evidence

Replacing an explicit definition must leave one authoritative registration and stop retaining its obsolete configuration. All four public registration mutations must finish publication and affected cache invalidation before releasing retired configuration, service values or resolved delegator callables. Throwing destructors must not leave shared `get()` and fresh `make()` using different registrations or leave an alias family partly invalidated.

Three failures remain observable on PHP **8.4.23** and **8.5.8** in the v5.0.5 implementation (`a56f3d70dd56bdd62947b6c322aa878e8a76ef4c`). They were reproduced again during the fresh review; subsequent localized alias/external-lookup and class-availability corrections do not change these ownership paths. Run the public-entry-point diagnostics with development dependencies installed:

```sh
php -n docs/audit/reproductions/DefinitionLifecycle.php
php -n docs/audit/reproductions/CacheRetirement.php
php -n docs/audit/reproductions/MutationParity.php
```

The scripts are [DefinitionLifecycle.php](../audit/reproductions/DefinitionLifecycle.php), [CacheRetirement.php](../audit/reproductions/CacheRetirement.php) and [MutationParity.php](../audit/reproductions/MutationParity.php). They report observations outside the regular test suite while the defects remain unfixed.

| Scenario | Current observation on both runtimes | Required result |
| --- | --- | --- |
| Replace a callable-object factory with an invokable definition | The former factory and payload remain reachable until the container is discarded; `make()` already uses the new definition. | Once caller and in-flight references are gone, the old configuration is collectible while the container remains alive. |
| Replace a factory with another factory whose retirement does not throw | Old factory and payload weak references clear. | Preserve this successful control. |
| Replace a warmed factory whose destructor throws | `set()` reports `InvalidConfigurationException`, `get()` keeps the old product, and `make()` produces the new one. | A committed retirement failure is distinguishable from rejection; both lookup paths use the committed registration. |
| Replace a source shared by two decorated aliases; the first old wrapper's destructor throws | Source and first alias return `new`; the second alias still returns `old`. | Complete all affected invalidation before retirement, including after a reported destructor failure. |

The retained configuration is bounded by former resolver/ID slots; these observations do not prove growth on every replacement. The two destructor cases demonstrate inconsistent published state, not merely delayed GC. The mutation-parity diagnostic still passes **600 operations / 14,400 comparisons** on each runtime. Thus the evidence justifies correcting ownership and release ordering, not replacing all caching or claiming that ordinary mutations generally fail.

## Baseline responsibility and architectural choice

The current owners are independently mutable:

| Current component | Owned state and responsibility | Problem at the boundary |
| --- | --- | --- |
| [CompositeResolver](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Resolver/Entry/CompositeResolver.php) | Resolver order and runtime `definitionOwners` routing | Changing the selected owner does not detach the old leaf's registration. |
| [FactoryResolver](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Resolver/Entry/FactoryResolver.php) | Factory/ClassDefinition map and per-ID resolved-callable validation flags | An overwrite can destroy old user state before container invalidation. |
| [InvokableResolver](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Resolver/Entry/InvokableResolver.php) | Class registrations | Initial registrations, like initial factory entries, bypass `definitionOwners`. |
| [Container](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Container.php) | Alias canonicalization, protected IDs, public mutations and invalidation orchestration | It treats a throwing leaf overwrite as rejection even when the leaf changed. |
| [EntryCache](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Internal/EntryCache.php), [DelegatorRegistry](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Internal/DelegatorRegistry.php) | Shared values, revisions, alias reverse membership and cached callable chains | Eviction releases user objects during an incomplete traversal. |

[ContainerFactory](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/ContainerFactory.php) fills the leaf maps directly. `Container` also accepts application entry resolvers, so public extension and standalone resolver consumers must be migrated explicitly. Repository search finds three built-in implementations of [DefinitionAwareResolverInterface](https://github.com/componenta/di/blob/ad46b3098f663d6c0d55bf6d1651a526af16ac68/src/Resolver/Entry/DefinitionAwareResolverInterface.php); it cannot establish that third-party implementations do not exist.

**Decision:** centralize explicit registrations in `CompositeResolver`; make factory/invokable extensions strategies that validate and execute supplied definitions without keeping their own ID maps. Keep the existing fallback entry-resolver mechanism and the existing value/delegator caches. Retain old bindings and evicted cache roots through a separate, internal mutation retirement barrier.

| Alternative | Assessment |
| --- | --- |
| Keep the current design and document its limits | Suitable for the current 5.x line while localized fixes continue. It leaves the three reproduced lifetime/consistency defects, so it is not the target for corrected ownership. |
| Concrete factory cleanup or optional removal | Does not cover custom owners, initial registration, same-owner overwrite and cache-wrapper retirement together. |
| Required `removeDefinition()` plus public retirement receipts | Can be made correct, but preserves multiple registration authorities and adds recursive removal, shared-leaf restrictions, receipt aggregation and extension-removal failure handling. Because it already requires a major API migration, retaining those responsibilities is a poor long-term tradeoff. |
| A central registry separate from `CompositeResolver` | Would duplicate or relocate the existing selection boundary without a demonstrated additional responsibility. Do not introduce a second registry, generated container or generic definition compiler. |
| One binding table in `CompositeResolver` plus registration-free strategies | Selected. Routing and configuration change together in one slot; initial and runtime definitions have the same owner. There is no cross-resolver removal operation. Cache retirement remains necessary and independent. |

This is a correctness and maintainability refactor, not a measured throughput optimization. It removes an entire class of owner/map synchronization and makes each invocation's configuration snapshot explicit. It also gives custom definition kinds one admission/execution contract. It does not add scopes, disposal hooks, service graph rebuilding, persistent configuration, or hot reload. Warm shared `get()` continues to return from `EntryCache`; no retirement holder belongs on that path. Fresh resolution and bootstrap costs must be measured, including the cost of binding objects; no speedup is promised.

## Normative desired state

### Requirements and preserved behavior

- **R1 — One registration authority.** One `CompositeResolver` binding table owns every explicit definition in its composition, including bootstrap definitions. Its entry associates a canonical ID, a selected definition strategy and a validated definition snapshot. Factory and invokable strategies keep no per-ID registration or ownership map.
- **R2 — Complete retirement boundary.** `set()`, `alias()`, `delegator()` and `addContainer()` retain all replaced binding/configuration roots and affected evicted cache/callable roots through publication and complete invalidation, then release them. No long-lived retirement queue is introduced.
- **R3 — Distinct outcomes.** Rejection before publication makes no speculative binding/cache write by that operation. A failure delivered during retirement reports an already committed mutation; it never restores the old registration or cache.
- **P1 — Services and creation.** Raw-value `set()` changes the shared base while preserving the underlying definition used by `make()`. Shared null/false values, factory ABI, explicit ClassDefinition argument/reference behavior and invokable zero-argument construction stay intact.
- **P2 — Routing and identity.** Preserve aliases, protected-core guards, external first-owner precedence, requested-ID delegator pipelines, their ordering and transitive invalidation. Unaffected shared instances retain identity. Ordinary constructor/factory consumers are not automatically rebuilt when their dependencies change.
- **P3 — In-flight resolution.** An invocation may finish using the definition it captured before replacement. Existing revision and Fiber ownership checks prevent it from restoring invalidated caches. Do not cancel, replay or lock an invocation merely because a binding changes.
- **P4 — Metadata and cost.** Reusable metadata may be cached, but invocation values and retired configuration must not enter metadata caches. Ordinary cache population does not allocate a retirement holder. Caller parameters crossing custom definition/fallback boundaries contain no DI-private metadata.

### Central bindings and strategy contract

`CompositeResolver` keeps one table of immutable internal `DefinitionBinding` records. A record contains the canonical `id`, selected `DefinitionResolverInterface` instance and validated `DefinitionInterface` payload. It contains no resolved service result. There is no parallel `definitionOwners` map. A record is captured locally before invoking its strategy and is never reread mid-invocation to select a different registration.

Replace the public mutable-leaf `DefinitionAwareResolverInterface` with `Componenta\DI\Resolver\Entry\DefinitionResolverInterface`:

```php
interface DefinitionResolverInterface
{
    public function supportsDefinition(DefinitionInterface $definition): bool;

    public function validateDefinition(
        string $id,
        DefinitionInterface $definition,
    ): DefinitionInterface;

    /** @param array<string|int, mixed> $params */
    public function resolveDefinition(
        string $id,
        DefinitionInterface $validated,
        array $params = [],
    ): mixed;
}
```

`supportsDefinition()` classifies the supplied kind without changing registrations, loading services, executing factories, or invoking application callbacks. The first supporting strategy in configured order wins. If none supports it, admission rejects; if the selected strategy rejects validation, DI does not try a later strategy as a fallback.

`validateDefinition()` validates and, when necessary, returns a normalized immutable payload for that same strategy's execution method. It may return the original immutable definition. The returned type can be a strategy-specific implementation of the existing marker interface; it need not be publicly constructible or supported as fresh input. Only the selected strategy executes its validated payload. This permits current factory validation flags to travel with a registration instead of remaining in another ID map.

Validation must not resolve a factory service or execute a service constructor/delegator. Class/callable inspection may autoload and reenter application code. The strategy must not retain the supplied or returned definition in a per-ID store, or publish a binding itself. Its output is stable configuration: mutable application factory objects may keep their intended business state, but the strategy cannot mutate the descriptor's routing/execution policy after admission. Custom definitions needing normalization return an owned stable descriptor; DI does not deep-clone arbitrary user graphs.

`resolveDefinition()` receives exactly the selected record's validated payload and current public parameters. It can call services and factories, and it may suspend. It must not consult another per-ID definition store. Resolution errors retain the existing Componenta exception projection at the owning boundary. Direct strategy callers must call validation before resolving a supplied definition and must keep the returned payload alive for that invocation. They own any results; strategies never become shared-value caches.

These are extension conformance obligations. DI cannot undo arbitrary side effects of a violating implementation. Factories and invokable construction remain effectful; “registration-free” does not mean mathematically pure.

### Built-in forms and admission

Public `Definition`, `FactoryDefinition(callable)`, `ClassDefinition`, `InvokableDefinition` and `ReferenceDefinition` construction APIs remain unchanged. A reference stays an argument/value descriptor, not a new top-level binding kind. The ownership refactor does not broaden the public factory constructor to arbitrary `mixed` values.

Declarative configuration still accepts its existing callable, non-empty service/function string, deferred `[service-id, method]`, lazy-factory and ClassDefinition forms. `DependencyConfiguration` continues shape/ID/alias normalization, but semantic factory ABI and class-eligibility validation belongs to admission in `CompositeResolver`. Direct users of the public configuration normalizer must therefore no longer treat shape normalization alone as proof of executable factory validity; `ContainerFactory::create()` still rejects invalid eager specifications before returning a container.

`ContainerFactory` wraps raw configured factory forms once in internal `ConfiguredFactoryDefinition` descriptors and submits them to the same admission path used for explicit definitions. A configured public definition is submitted as that definition, without wrapping ClassDefinition or inventing a second execution route. Factory strategy admission produces an internal readonly `PreparedFactoryDefinition` containing the normalized factory specification and the current `validateResolvedCallable` flag. Both internal descriptor classes implement `DefinitionInterface`; neither is a registry or public construction API.

Preserve validation timing: known non-string direct callables are checked against `(ContainerValue, array)` at admission; strings and unresolved service-method specifications remain deferred and are checked after the owner is actually resolved. Preserve service-ID precedence over callable-string fallback and the lazy-factory path. Do not resolve deferred owners during admission, repeat semantic normalization during `resolveDefinition()`, or cache the resolved callable's service instance in the binding. The current factory validation metadata cache can still avoid repeating checks for the same reflection metadata.

Stable configuration also excludes caller-owned PHP reference slots (`&`). Snapshot factory callable-array members before callable inspection and validation, preserving their keys and any actual factory object's identity. Resolving a deferred owner must not write through a caller reference or replace the service ID in the admitted descriptor. Apply the same boundary to delegator callable arrays before validation and dependency-ID capture: stored routing and its invalidation dependencies must describe the same owned snapshot. Mutating an application's factory/delegator object remains intentional business state; rebinding its caller variable or method-name variable is not a registration mutation.

ClassDefinition admission retains current eligibility and explicit-method checks, including classes loaded by a later availability probe. Execution continues to use literal runtime overrides, fresh reference lookups and ordered explicit calls, without applying DI attributes. Invokable admission validates and normalizes its class name; execution remains a direct zero-argument constructor call. Reflection fallback remains an ordinary `EntryResolverInterface` and receives no definition-admission methods.

For ClassDefinition, own the constructor-argument and method-call array graphs before class inspection can autoload application code. Detach caller reference slots recursively without cloning application objects or `ReferenceDefinition` values. Preserve shared array edges and recursive arrays in that private snapshot; this is not a new admission-time rejection of recursive configured arguments. The existing execution boundary still rejects a recursive configured value only when it is consumed, and a literal runtime override can bypass it. Runtime parameter arrays are not part of the admission snapshot and retain their existing literal semantics. Snapshot bookkeeping belongs only to admission and must not retain an additional copy of the graph afterward.

### Composition, bootstrap and direct consumers

`CompositeResolver` accepts an ordered variadic list of `EntryResolverInterface|DefinitionResolverInterface` providers. A provider may implement both contracts; the contracts keep their separate responsibilities. `can()` first checks the central explicit table; without an explicit binding it consults current fallback `can()` results in order. `resolve()` executes the captured explicit binding, or selects and invokes the current fallback. Explicit bindings take precedence over fallbacks. Unsupported IDs retain existing not-found behavior.

Nested `CompositeResolver` arguments are composition-time snapshots, not live mutable children. Construction flattens their provider order and imports their immutable binding records without revalidation, then releases references to the child composite itself. Identical definition-strategy objects reached through multiple branches are included once at their first position. Fallback occurrence ordering remains intact. Keep the current rejection of duplicate objects passed directly to one constructor.

Importing the exact same `DefinitionBinding` record through multiple branches is idempotent. Different records for the same ID are rejected before publishing the new composition; do not compare arbitrary definitions for structural equality or silently select an owner. A later explicit `setDefinition()` on the new standalone composition is an intentional replacement. Later changes to a source child affect that child only. A caller retaining the child legitimately retains its independent snapshot; this is not obsolete configuration retained by the new container. Shared strategy instances remain valid because strategies hold no registrations. No generic export interface or recursive removal protocol is introduced.

A standalone `CompositeResolver::setDefinition(string $id, DefinitionInterface $definition): void` remains available. It prepares a record, publishes it with an internal retirement holder, and releases the holder after its own map update. There are no surrounding container caches in this standalone flow. Its direct users receive the same committed-retirement exception when an old configuration destructor fails.

Once a `CompositeResolver` is attached to a `Container`, that container is the sole public mutation authority for that binding table. Subsequent direct `setDefinition()` calls, attachment to a second container, and use of an attached composite as a snapshot child reject with `InvalidConfigurationException`. Enforce this with local attachment state on the composite; no process-global registry, cross-container lock or detachment protocol is needed. The attachment lasts for the composite's lifetime. This is an explicit next-major restriction: otherwise a direct/shared-root mutation bypasses one or more containers' revision/invalidation batches. Build an independent composition from the desired providers for another container; provider-specific container dependencies remain the caller's responsibility.

`Container` keeps its `EntryResolverInterface` constructor input. An input already using `CompositeResolver` becomes its root; a bare fallback resolver is wrapped in an empty composite. Custom definition support is supplied through the new definition-strategy contract, not by probing a legacy mutable-leaf capability. `ContainerFactory` creates the root providers, admits all normalized bootstrap definitions before attachment, then completes its existing bootstrap hand-off. Initial and later definitions occupy the same table. Validate constructor/bootstrap arguments before claiming attachment; a rejected attachment must not alter the previously attached container.

`Container` and the final composite use internal preparation/publication methods: preparation returns `DefinitionBinding` without a write; publication retains the currently stored record in the operation's holder before replacing the slot. Prepared bindings belong to the producing composite and canonical ID and are not an application transfer API. Only the internal container path may publish into an attached composite. Public direct strategy/composite consumers never receive a retirement receipt and never call cache-invalidation helpers.

For this publication guard, `Container` creates a private object capability at attachment; the composite stores its identity and requires that same capability before any attached-table publication write. Neither a prepared binding nor an accessor exposes the capability. Standalone publication is allowed only while unattached. This is a local identity check, not a global authority registry or a guarantee against deliberate reflection into private internals.

### Publication, reentry and retirement

All mutators follow **validation → publication → complete invalidation → release**. The operation performs no binding, revision, reverse-index or pipeline write after final release begins. User callbacks belong to preparation/resolution or native object retirement, not to the central publication/invalidation batch.

For `set($requestedId, $definition)`:

1. Check the mutable/protected target and capture the canonical ID. Ask the root to select/validate and prepare a binding without publishing it.
2. After validation succeeds, use the current alias/deferred-dependency graph to determine affected pipelines for that captured canonical ID. Successful nested mutations are retained. Do not retarget the outer write by canonicalizing the requested alias again.
3. Create one internal holder. Retain the record currently occupying that canonical slot, publish the new binding, retain/remove the old base and invalidate every affected requested result and deferred callable chain, updating all revisions and reverse membership.
4. Release the holder outside the validation-rejection catch. New lookups now use the committed binding. A reported retirement failure does not restore old state.

Validation can suspend or perform a nested mutation. The binding retired in step 3 is the current one at publication, not a stale pre-validation snapshot. A successful outer mutation publishes after earlier nested work; a rejected outer validation does not roll that work back. No new optimistic-conflict, retry or cancellation policy is added.

Raw `set()` preserves the definition and retains/replaces only its cache roots. `alias()`, `delegator()` and `addContainer()` preserve their existing affected-set semantics and use the same holder through all invalidation helpers. Preserve post-validation protected-target checks without repeating user validation. External-registration ownership inspection must include newly added deferred dependency IDs, inspect an ID only once per registration, and invalidate dependents from the completed current graph; its rejection occurs before publishing the external container. These are preserved localized fixes, not a reason to introduce a transaction framework.

`EntryCache` mutation helpers retain every overwritten/removed base and resolved value before dropping its map reference. `DelegatorRegistry` retains evicted resolved callable chains before dropping them. Raw pipeline specifications remain owned while registered; this proposal adds no unregister API. Normal resolution `putBase()`/`putResolved()` remains direct and does not create holders. Internal forwarding helpers pass the one operation holder rather than creating independent receipts.

Add internal `Componenta\DI\Internal\MutationRetirement` with only this surface:

```php
final class MutationRetirement
{
    public function __construct(public readonly ?string $id);
    public function retain(mixed ...$values): void;
    public function release(): void;
}
```

`id` is the canonical target of `set()`, requested alias/delegator ID, or `null` for `addContainer()`. `retain()` appends roots while active, without cloning, traversing user graphs or invoking callbacks. First `release()` moves its roots to local ownership and marks the holder released before dropping any root. Repeated or reentrant release returns immediately, including while the first invocation is suspended; it does not join or repeat that invocation's eventual error. `retain()` after release throws `\LogicException` before changing state. There is no `absorb()`, `recordFailure()`, consumed-receipt state, implicit destructor drain or extension-removal failure path: one owner and one internal holder make those mechanisms unnecessary.

Release uses native reference dropping, never calls `__destruct()` directly, and attempts each directly held root in insertion order. Catch each `Throwable` synchronously delivered at those direct release boundaries, continue releasing the remaining roots, and preserve all encountered causes in order without deduplication. This does not promise a destructor count or order inside a nested user graph. Application/in-flight references, cycles, resurrection and exception objects can keep values alive after the holder stops owning them. Production code never forces GC.

Add public `Componenta\DI\Exception\MutationRetirementException` extending `InvalidConfigurationException`. It exposes readonly `?string $id` and a readonly non-empty ordered `array $causes` of `Throwable`; `getPrevious()` is the first cause. Its message states that the mutation committed. This type denotes a committed outcome, not a validation rejection or instruction to retry. No causes means normal return. Callers deciding whether to retry must distinguish this subtype before their broader invalid-configuration handling.

Existing Componenta exception identity is preserved across validation callbacks. A callback can propagate a `MutationRetirementException` from an independently committed nested mutation before the outer operation publishes anything. Its committed outcome and `id` describe the mutation that produced the exception; the outer call must not infer that its own requested write completed.

The [PHP destructor documentation](https://www.php.net/manual/en/language.oop5.decon.php#language.oop5.decon.destructor) describes separate GC destructor Fibers from PHP 8.4 and fatal shutdown-destructor exceptions; the [Fiber documentation](https://www.php.net/manual/en/language.fibers.php) allows destructor Fiber switching from PHP 8.4. Consequently the holder collects only errors actually propagated into its active release invocation. It does not wait for GC, another destructor Fiber or shutdown. Those later events already see completed publication/invalidation, but their timing and error delivery remain PHP/application responsibilities.

| Event | Published binding and caches | Observable outcome |
| --- | --- | --- |
| Unsupported kind or validation rejection | No speculative write by the rejected operation; independent nested changes remain | Existing configuration rejection, with existing Componenta/foreign exception normalization. |
| Successful mutation and retirement | New binding/routing; full affected invalidation | Normal return. |
| Synchronous destructor failure during final retirement | New binding/routing; full affected invalidation | `MutationRetirementException`; later lookups use current state. |
| Destructor reentry or suspension | The complete outer mutation is already visible | A successful nested mutation becomes current; outer retirement performs no later state writes. |
| Old factory suspended during replacement | Current table changes; old local binding stays alive as needed | Existing concurrent-resolution rules remain; old result cannot repopulate invalidated cache. |

The boundary cannot roll back user callbacks, external side effects or deliberately nonconforming extensions. A destructor may make another mutation, retain an object, throw or suspend. Do not describe every exception as rejection, and do not promise rollback after publication.

## Compatibility and change surface

This target belongs in the **next major version**. Remove `DefinitionAwareResolverInterface`; factory/invokable constructors and direct resolver APIs change, nested composites become snapshots, and attached roots no longer accept direct mutation or sharing. None of these changes belongs in a 5.x patch. The architecture does not itself require PHP 8.5 syntax; platform-floor decisions belong to the separate [PHP 8.5 plan](php85-callbacks.md).

Application `Container` mutation signatures and the public definition constructors remain unchanged. Custom mutable definition resolvers split into the new registration-free strategy and, only if needed, an ordinary fallback entry resolver. Their configuration moves into definitions admitted to the root composite. Standalone factory/invokable callers either validate/execute one descriptor directly or register definitions in a standalone composite. Consumers needing shared-cache-aware mutation use `Container::set()`. There is no safe adapter that makes an opaque legacy mutable owner registration-free, so no permanent legacy path remains.

| File or consumer | Required disposition |
| --- | --- |
| `src/Resolver/Entry/DefinitionAwareResolverInterface.php` | Delete the obsolete mutable-owner extension contract. |
| New `src/Resolver/Entry/DefinitionResolverInterface.php` | Add admission/execution contract and custom implementation obligations. |
| `src/Resolver/Entry/CompositeResolver.php` | Replace `definitionOwners` with the sole binding table; add preparation/publication, standalone mutation, snapshot composition and local attachment rules. |
| New `src/Internal/Resolver/Entry/DefinitionBinding.php`, `ConfiguredFactoryDefinition.php`, `PreparedFactoryDefinition.php` | Immutable binding and factory admission data; no independent registries or runtime value caches. |
| `src/Resolver/Entry/FactoryResolver.php`, `InvokableResolver.php` | Remove registration maps and setters; implement validation/execution against supplied snapshots. Preserve their actual creation behavior. |
| `src/Configuration/DependencyConfiguration.php`, `src/ContainerFactory.php` | Normalize shape once; admit all initial definitions to the root through the selected strategy; stop filling leaf maps. Document the normalizer's changed semantic-validation boundary. |
| `src/Internal/Resolver/Entry/FactorySpecificationValidator.php` | Reuse existing ABI/eligibility checks for admission and deferred resolved-callable validation. |
| `src/Container.php`, `src/Internal/Resolver/Entry/EntryResolverContext.php` | Use central prepare/publish and current invalidation; preserve private-metadata filtering for fallback and definition execution. |
| `src/Internal/EntryCache.php`, `src/Internal/DelegatorRegistry.php` | Retain removed roots in the operation holder through all mutation-only helper paths. |
| New `src/Internal/MutationRetirement.php`, `src/Exception/MutationRetirementException.php` | Internal lifetime barrier and public committed-failure type. |
| READMEs, custom implementations and direct consumers | Explain major migration, snapshots, attachment and failure outcomes; replace the 5.x limitation notice only after implementation passes its proof. |

No persistent data, serialized graph or generated container needs migration. Unknown external consumer identities do not remove the documented consumer-class migration obligation. Keep ordinary fallback interfaces, value caches, alias compression, reflection metadata and object/attribute construction pipelines. Delete leaf registration maps, redundant owner routing and every obsolete remove/receipt proposal rather than leaving parallel authorities.

## Verification and implementation plan

The slices implement the normative target; they do not authorize a major refactor during this audit.

### Slice 1 — Authoritative bindings and complete mutation publication

**Depends on:** the next-major contract above. Add the strategy interface, immutable internal records, internal retirement holder and committed-failure type; migrate factory/invokable behavior and the composite together. Change bootstrap and public-normalizer semantics in the same slice so no initial definition remains in an old leaf map. Update direct callers and metadata filtering. Remove the old interface and obsolete maps immediately. Add snapshot imports and attachment restrictions where the new authority is introduced. Integrate the holder into central publication, standalone replacement and all four public container mutators, including every cache/delegator invalidation helper. Final release is outside rejection normalization and after the last state write. Do not ship a prepared/publication API without its holder, or protect definitions while leaving adjacent cache retirement unprotected.

Start with public regressions for all three diagnostic failures. Prove runtime and bootstrap factory/invokable/ClassDefinition transitions, including reverse transitions, null results, deferred service methods and lazy factories. Preserve ClassDefinition arguments, references, native defaults, explicit calls and absence of target attributes. Add a test-only custom strategy with stable normalized data; prove first-match selection, rejection without speculative writes, unsupported kinds, private-parameter filtering and no deferred service execution during admission. Check imported identical bindings, conflicting IDs, child-independent mutation and absence of retained child composites.

**Done when:** all three original failures are gone; explicit registrations use one table and one admission path; direct/extension migration is executable; existing creation/routing contracts pass; no mutable-leaf interface or second ownership map remains. Ordinary lookups allocate no retirement holders.

### Slice 2 — Ownership and retirement conformance

**Depends on:** slice 1. Prove the introduced boundaries across custom strategies, nested snapshots and all adjacent mutation paths. This slice adds conformance coverage and any corrections needed to meet the already specified target; it introduces no new ownership or failure policy.

Include ClassDefinition object-valued arguments, raw-value replacement preserving `make()`, several throwing retired roots, a still-referenced application object, and an unaffected shared identity. A destructor that reenters `get()` must see the complete new state. A nested successful mutation from retirement must remain current after the outer operation returns or throws. Test standalone replacement and attached-root rejection as separate public boundaries. Confirm snapshot identity deduplication, conflicting import rejection, independent child mutations and safe sharing of registration-free strategies.

**Done when:** old unneeded configuration is collectible, both cache inconsistency diagnostics are gone, failures identify committed state, and ordinary lookups still allocate no retirement holders.

### Slice 3 — Concurrency, consumer conformance and convergence

**Depends on:** slices 1 and 2. Exercise a suspended old factory across replacement, alias retarget and deferred-delegator invalidation. Verify the old binding stays alive only as long as the invocation needs it and cannot restore an invalidated entry. Suspend a destructor during release, perform another mutation, resume it, and prove there are no later outer writes. Check repeated release, retain-after-release rejection and ordered multiple causes; use a non-throwing cyclic object to separate ownership release from GC timing.

Exercise successful and rejecting validation-autoload callbacks, captured canonical IDs, current deferred-dependency scans, protected-target rechecks and external ownership callbacks that add deferred dependencies. Run custom strategy conformance through both standalone and container composition. Confirm all application mutation examples use the correct owner and all old interface/map references have a disposition.

Run complete formatting, static analysis, tests and applicable Linux/Windows/coverage/mutation gates. Compare unchanged shared-get, explicit-definition fresh-make, reflection-fallback and construction benchmarks using the same runtime/settings. Measure bootstrap as well as steady-state costs; use weak references for lifetime proof rather than treating RSS reduction as evidence. Update both READMEs and the complete Russian specification only after implementation and desired state converge.

**Done when:** implementation, interfaces, error categories, docs and proof agree; no superseded owner path, generic compiler, public receipt protocol or long-lived retirement store remains.

### Acceptance mapping

| Requirement or boundary | Proof |
| --- | --- |
| R1 central ownership | Bootstrap/runtime transitions; one current binding; custom strategies; no leaf maps; nested snapshot/import/conflict tests. |
| R2 retirement | Public weak-reference cases; all four mutators; wrapper/callable roots; application and cyclic-reference controls; last-write-before-release review. |
| R3 outcomes | Pre-publication rejection and nested-mutation preservation; committed destructor failures; full sibling invalidation; ordered causes and release reentry. |
| P1 creation | Existing factory/ClassDefinition/invokable suites plus deferred-form timing and raw-service/fresh-make controls. |
| P2 routing and identity | Alias, external ownership, protected-core, requested-pipeline and transitive invalidation suites; unrelated identity. |
| P3 in-flight work | Current Fiber/cycle/recovery contracts plus retained binding snapshot and suspended retirement cases. |
| P4 isolation and cost | Custom-definition/fallback metadata boundaries; no hot-lookup holder; controlled bootstrap/get/make benchmarks. |
| Consumer migration | No old interface/map consumers; strategy direct-call examples; snapshot independence; rejection of attached direct mutation and second attachment. |
| Single semantic admission | Declarative and runtime controls reject eager invalid input before use, preserve deferred failures and avoid repeating normalization during execution. |
| Owned configuration arrays | Caller references cannot redirect factory/delegator methods, desynchronize delegator invalidation or change admitted ClassDefinition arrays; object identity/business state, fresh service references, shared acyclic arrays, deferred recursive failures and runtime overrides remain intact. |

There are no deferred material design choices in this target. The specified implementation, local evidence and executable migration are present on the major branch; hosted verification status is recorded in the implementation report. The separately versioned change does not remove the ownership/retirement limitation from the existing 5.x release.
