# Definition ownership and mutation retirement

**Canonical specification:** this English document. [Full Russian translation](definition-owner-lifecycle.ru.md).

**Readiness:** implementation-ready for the next major version's container mutation protocol. The target is specified sufficiently for implementation; readiness is not production rollout approval. This is a proposed architecture, not an implemented change in the current 5.x line. **The v5.0.5 bug-fix release does not implement `MutationRetirement` or this protocol.**

## Objective and evidence

Replacing an explicit definition must leave one effective definition owner and release configuration that no owner can use. All four public registration mutations must publish their binding/routing changes and invalidate affected caches before releasing the configuration, service values or resolved delegator callables that they retired. Throwing destructors must not strand a partially invalidated alias family or make shared `get()` disagree with fresh `make()` about the effective registration. This is a reference-lifetime boundary for mutations, not service shutdown, request scope, a general transaction framework, or a dependency graph for rebuilding ordinary shared consumers.

Three failures were reproduced through `ContainerFactory` and `Container::set()` on PHP **8.4.23** and **8.5.8**, against the November 13 checkout whose baseline is `65da4acc18b19989041a2813dbc92f2f474b7177`. The diagnostics were rechecked at `794699c80996bf6142b08b50d381ac52378bdfd2` after the localized source fixes; all three failures remain observable.

The executable evidence is [DefinitionLifecycle.php](../audit/reproductions/DefinitionLifecycle.php) and [CacheRetirement.php](../audit/reproductions/CacheRetirement.php). From the repository root, with development dependencies installed, run:

```sh
php -n docs/audit/reproductions/DefinitionLifecycle.php
php -n docs/audit/reproductions/CacheRetirement.php
```

Both scripts use public production entry points, report observations as JSON, and stay outside the regular test suite while the defects remain unfixed.

| Scenario | Current observation on both runtimes | Required result |
| --- | --- | --- |
| Replace a callable-object factory with `Definition::invokable(stdClass::class)` | The retired factory and its payload remain reachable through weak references until the entire container is discarded. `make()` already uses the new definition. | Once no caller or in-flight invocation owns them, the retired factory and payload can be collected while the container stays alive. |
| Replace the factory with another factory, with no throwing destructor | Both weak references clear. | Preserve this successful control. |
| Replace a factory whose destructor throws, after warming `get()` | `set()` throws `InvalidConfigurationException`, `get()` keeps the old shared product, and `make()` produces the new product. | A validation rejection preserves the old registration; a retirement failure after successful publication reports the committed outcome and leaves both lookup paths using the new registration. |
| Replace a base service with two warmed decorated aliases; the first retired alias wrapper's destructor throws | `set()` throws `LogicException`; the source and first alias return `new`, but the second alias still returns `old`. | Complete the entire affected invalidation before retirement; all three lookups use the new source despite a reported retirement failure. |

The first defect retains at most the obsolete registration held by each former resolver for that ID; this evidence does not show growth on every repeated switch. The other two are observable cache inconsistencies, not merely delayed garbage collection. These findings do not imply that every application destructor is safe to run or that DI can undo application side effects.

## Current responsibility and root cause

[Container::set()](../../src/Container.php) canonicalizes the ID, asks a definition-aware resolver to register the definition, and only after that call succeeds removes the old base value and invalidates affected resolved entries and deferred delegators.

[CompositeResolver](../../src/Resolver/Entry/CompositeResolver.php) selects the first resolver supporting a definition and stores that resolver in `definitionOwners`. Its member resolvers retain their own authoritative registration maps. [FactoryResolver](../../src/Resolver/Entry/FactoryResolver.php) owns factory callables, `ClassDefinition` objects and validation flags; [InvokableResolver](../../src/Resolver/Entry/InvokableResolver.php) owns invokable classes. Factory and invokable registrations supplied at container construction populate these leaf maps directly, so they initially have no entry in `CompositeResolver::$definitionOwners`.

There are three missing boundaries:

1. Changing the selected resolver does not remove the previous resolver's registration. The interface permits registration but has no operation for relinquishing a definition. Consulting only `definitionOwners` would also miss the initial declarative registrations.
2. Overwriting a factory's slot can destroy its previous callable immediately. If that destructor throws, the resolver has already changed its map, but `Container::set()` treats the exception as a failed registration and skips cache invalidation. The mutable definition and shared cache then disagree.
3. [EntryCache](../../src/Internal/EntryCache.php) and [DelegatorRegistry](../../src/Internal/DelegatorRegistry.php) release cached values/callables while traversing invalidation. A destructor can abort that traversal or reenter a partially updated graph. These helpers are shared by `set()`, `alias()`, `delegator()` and `addContainer()`, so protecting only definition payloads would leave the same consistency gap reachable through the adjacent mutation paths.

The current [DefinitionAwareResolverInterface](../../src/Resolver/Entry/DefinitionAwareResolverInterface.php) is public and has three in-repository implementations: `FactoryResolver`, `InvokableResolver`, and `CompositeResolver`. `Container` is its primary mutation caller. The constructor of `Container` also permits application-supplied resolvers; custom implementations cannot be assumed absent merely because repository search finds none. These facts require a defined extension contract, not a concrete-type check in `CompositeResolver`.

## Normative desired state

### Ownership and preservation

- **R1 — Exclusive explicit ownership.** After a successful explicit definition replacement, the selected resolver owns that ID's current definition. Every other definition-aware resolver in the composition has relinquished its prior definition for that ID. Fallback reflection remains available for IDs without explicit definitions.
- **R2 — Retirement boundary.** A previous definition's callable/arguments, replaced base values, evicted resolved entries and discarded delegator-callable chains remain strongly held until the public mutation has published its binding/routing changes and invalidated its complete affected cache set. They are then released without a process-global retirement list or later maintenance pass. `set()`, `alias()`, `delegator()` and `addContainer()` share this rule.
- **R3 — Distinct failure outcomes.** Validation/selection rejection and failure while retiring already-replaced state are different outcomes. A retirement failure cannot leave `get()` on the old cached registration while `make()` uses the new one, or leave affected siblings from different source generations.
- **P1 — Raw services.** `set($id, $ordinaryValue)` still replaces the shared base value while preserving the underlying definition used by `make()`. It does not remove definitions. Its retired cache values participate in R2, so ordinary value replacement receives the same safe release ordering.
- **P2 — Lookup semantics.** Aliases, delegator ordering, null/false shared values, protected IDs, external-container precedence, and the distinction between shared `get()` and fresh local `make()` retain their existing contracts.
- **P3 — In-flight work.** An already-running factory or `ClassDefinition` may finish using its earlier configuration. Existing revision checks continue to prevent that attempt from restoring invalidated caches. Retirement does not cancel a Fiber, replay creation, or destroy objects still held by an active invocation or an application reference.
- **P4 — Local lifetime.** Named reflection metadata remains reusable; runtime configuration and captured values are not promoted into metadata caches. Unaffected shared entries retain identity.

### Architectural decision

Extend the existing definition-aware protocol in the next major release. Both replacement and removal return a small **`MutationRetirement` holder** containing detached previous configuration. `Container` incorporates it into the same holder used by affected cache/delegator mutations, retains all removed roots through the complete invalidation batch, and releases them afterwards. This holder is a lifetime barrier for one registration mutation, not a transaction, rollback mechanism, or service lifecycle manager.

| Alternative | Decision and reason |
| --- | --- |
| Optional `removeDefinition(string $id): void` capability | Rejected as the target. It leaves custom implementations outside the ownership guarantee, does not protect same-owner replacement, and can run destructors before cache invalidation. Optional best-effort cleanup would not fix the second reproduced failure. |
| Required removal returning `void` | Insufficient. It makes ownership explicit but still releases captured objects inside the resolver call, where a throwing destructor can strand the old cache. |
| Detect `FactoryResolver` inside `CompositeResolver` and clear its private state | Rejected. It couples the orchestrator to one leaf implementation, misses custom and nested resolvers, and preserves the missing public protocol. |
| Centralize every definition in a new registry and turn all resolvers into stateless strategies | Not selected. It could solve ownership, but it changes bootstrap, selection, direct resolver APIs and all definition execution paths. The reproduced failures require bounded ownership transfer and release ordering, not a new runtime resolution layer. |
| Required mutation/removal retirement protocol | Selected. Existing owners retain their work; the missing release boundary becomes explicit, including custom and nested resolvers. The same holder prevents partial cache eviction. Ordinary lookup writes and the attribute architecture remain unchanged. |

### Public extension contract

The existing interface namespace remains `Componenta\DI\Resolver\Entry`; it imports the new public `Componenta\DI\MutationRetirement`. The next-major interface is:

```php
interface DefinitionAwareResolverInterface extends EntryResolverInterface
{
    public function supportsDefinition(DefinitionInterface $definition): bool;

    public function setDefinition(
        string $id,
        DefinitionInterface $definition,
    ): MutationRetirement;

    public function removeDefinition(string $id): MutationRetirement;
}
```

`supportsDefinition()` classifies the definition kind. It must not publish bindings, execute factories, or mutate another resolver. Selection order remains the configured resolver order.

`setDefinition()` validates before changing its stored definition. A conforming implementation that rejects validation makes no speculative definition write of its own. Validation may autoload classes, reenter the container, and have application-side effects. A nested successful mutation remains effective even if the outer validation then fails; DI must not restore an obsolete snapshot over it. When no such nested mutation occurs, rejection leaves the prior registration unchanged. Once validation succeeds, the implementation must retain its currently stored configuration in the receipt **before** replacing any reference that could be its last owner. It then publishes the new registration and returns the receipt. Same-owner replacement follows this rule too.

`removeDefinition()` detaches a registration owned by that resolver and returns the detached state in a receipt. Absence is an idempotent success with an empty receipt. Removal must not discover or instantiate a service, call `can()`, validate a replacement, invoke user callbacks deliberately, or release the detached state prematurely. Built-in implementations perform only local map updates while the returned receipt retains user-owned values. Removal also clears the definition's local derived state, such as `FactoryResolver::$validateResolvedFactories[$id]`.

These obligations are extension conformance requirements. DI cannot provide rollback for an arbitrary implementation that publishes a new binding and then throws instead of returning its receipt, or that disposes objects before retaining them. Such code violates this protocol; the migration guide and shared conformance tests must make that distinction explicit.

### Retirement holder and committed failure

Add `Componenta\DI\MutationRetirement` as a final, non-callable reference holder. It is public only because application-supplied definition resolvers must return it through the extension protocol. Its surface is:

```php
final class MutationRetirement
{
    public function __construct(public readonly ?string $id);

    public function retain(mixed ...$values): void;
    public function absorb(self $other): void;
    public function recordFailure(Throwable $failure): void;
    public function release(): void;
}
```

`id` identifies the canonical target of a `set()`/definition operation, the requested alias/delegator ID for those mutations, or `null` for `addContainer()`, which has no single service target. It is diagnostic context, not a cache key. Child holders can carry another ID; absorption transfers their retained roots and recorded causes into the outer operation without releasing anything. The recipient keeps its own `id`.

The holder starts active. Consumed and released are terminal states, not new public types or an external state-management API:

| Operation | Preconditions | State and outcome |
| --- | --- | --- |
| `retain()` / `recordFailure()` | Recipient is active | Append roots/causes; remain active. |
| `absorb($other)` | Both holders are active and distinct | Append the donor's roots/causes in their existing order, empty the donor and mark it consumed; recipient remains active. An empty active donor is valid. |
| First `release()` | Holder is active | Detach its stored roots/causes into this invocation's local ownership and mark the holder released before dropping any root. Drain them and either return normally or throw the committed-retirement exception. |
| `release()` on a consumed or released holder | None | Return immediately without releasing roots again or repeating an earlier error. |

Self-absorption, absorption from a consumed/released donor, and `retain()`, `recordFailure()` or `absorb()` on a consumed/released recipient throw `\LogicException` before changing either holder. Normal mutation paths must never depend on these programmer errors for flow control. They are not reported as committed-retirement failures. If the first `release()` suspends in a destructor, a reentrant or concurrent call to `release()` still returns immediately; it neither waits for nor joins the first invocation. Only that first invocation reports its eventual retirement failure.

`retain()` keeps the supplied roots privately before their owning maps are overwritten/unset. It does not clone values, invoke callbacks, inspect an object's graph or traverse user arrays recursively. `absorb()` moves another holder's roots/causes into the recipient, so nested composites and the outer container use one eventual release boundary. `recordFailure()` records a post-publication extension-removal failure for later reporting; it is not a rollback operation.

`release()` uses native reference release, never calls `__destruct()` directly, and drops directly held roots in their retained order. It catches each `Throwable` delivered synchronously at those release boundaries and continues with the remaining roots. Recorded extension-removal failures precede these newly observed failures in `causes`; each group keeps its encounter order, including order preserved by `absorb()`. Repeated occurrences of the same throwable are preserved rather than deduplicated. With no causes it returns normally; otherwise it reports the combined list once. This promises neither a count nor an order for destructors deeper inside a user object graph; PHP owns that destruction. A retained exception or an application reference can itself keep an object alive.

Add `Componenta\DI\Exception\MutationRetirementException`, implementing the existing Componenta exception contract and extending `InvalidConfigurationException`. It exposes readonly `?string $id` and readonly `array $causes`, the latter a non-empty ordered list of underlying `Throwable` causes; `getPrevious()` is `causes[0]`. Its diagnostic states that the registration mutation has already committed. Callers distinguish this outcome from rejection by type, without parsing messages. No automatic retry or rollback is performed.

A malformed third-party removal can throw despite the detach-only contract. `CompositeResolver` records the failure, continues detaching other independent non-selected owners, and keeps the selected routing authoritative. The retention guarantee for that violating resolver cannot be met, but the error is reported after the container invalidates affected caches. A selected custom setter that writes then throws without returning a holder violates the separate registration contract; the container cannot reconstruct its opaque state or undo application side effects.

The holder has no custom destructor that throws or performs an implicit commit. The caller must retain and explicitly release it after its complete derived-state update. Direct resolver callers without surrounding caches can use `$resolver->setDefinition(...)->release()`; other callers must honor the same ordering as `Container`. Ignoring or dropping the holder early invokes normal PHP lifetime rules and bypasses the deferred-release/error-projection guarantee; that is a caller contract violation. Cloning or serialization is not a supported ownership-transfer mechanism; callers use `absorb()`. The guarantee is cessation of this subsystem's ownership, not immediate destruction of every reachable object. Production code must not call `gc_collect_cycles()`; tests may use it to observe collection after the intended owners have gone away.

PHP controls destruction outside these synchronous boundaries. The [PHP destructor documentation](https://www.php.net/manual/en/language.oop5.decon.php#language.oop5.decon.destructor) documents separate garbage-collector destructor Fibers from PHP 8.4 and fatal exceptions from shutdown destructors; the [Fiber documentation](https://www.php.net/manual/en/language.fibers.php) permits destructor Fiber switching from PHP 8.4. Therefore the holder does not collect errors raised later by cyclic GC, another destructor Fiber or shutdown unless they actually propagate into its active `release()` invocation. It does not wait for deferred destruction. Its cache-publication guarantee already holds before any of those later events; their timing and error delivery remain PHP/application responsibilities.

### Cache and delegator mutation boundaries

The operation's holder is passed to mutation-only helpers that overwrite or remove values:

- `EntryCache` retains an old base before raw replacement or definition removal, and every removed resolved value before invalidation. It still updates the same revisions and reverse membership. Introduce a mutation-specific base replacement path; normal `putBase()`/`putResolved()` used by successful resolution stay direct and do not allocate holders or run retirement logic.
- `DelegatorRegistry` retains a discarded cached callable chain before invalidating it. Registration and dependency invalidation propagate the same holder instead of releasing a chain midway through a transitive traversal. Raw registered pipeline specifications remain owned while still registered; this proposal adds no unregister API.
- `Container::invalidate()`, `invalidateRequested()` and `invalidateDeferredDelegators()` pass the holder through the whole affected family. Their callers release it only after completing every required source, requested-entry and transitive-dependent invalidation.

A source/base replacement continues to invalidate the source and affected aliases. A requested-pipeline mutation continues to preserve unrelated shared source/sibling identity. The holder changes when old references are released, not which valid entries are invalidated. All removals must update their revisions and indexes before any captured user object can be destroyed through the mutation. No holder is stored on `Container`, a global registry, a cache value, or a long-lived cleanup queue.

### Composite selection, initial registrations and nesting

For a successful `CompositeResolver::setDefinition($id, $definition)`:

1. Select the first definition-aware resolver supporting the definition. If none does, reject without changing state.
2. Call the selected resolver's `setDefinition()`. If it rejects, make no routing change for this rejected outer operation, retain independently completed nested mutations, and do not remove any other registration.
3. Publish that resolver in `definitionOwners[$id]` and retain its receipt.
4. Call `removeDefinition($id)` on every other definition-aware resolver in this composition, collecting their receipts. Do not call `can()` to infer ownership. This deliberately includes initial declarative factory/invokable registrations that have never populated `definitionOwners`.
5. Return the combined receipt, including any unexpected removal failures, without releasing its contents.

`CompositeResolver::removeDefinition()` detaches the ID from all its definition-aware children and removes its own explicit routing entry. It aggregates their receipts and leaves ordinary reflection eligibility unchanged. Nested composites therefore implement the same boundary recursively.

Each object implementing `DefinitionAwareResolverInterface` must appear at most once in a visible composite tree, including nested composites and their definition-aware descendants. Extend the existing duplicate-instance constructor guard across nested `CompositeResolver` members for these objects, inspecting stored members without calling user resolution methods. Otherwise removal through one sibling could delete the newly selected registration through a shared leaf. Reject this configuration before resolution begins. Keep the existing direct-duplicate check for all directly supplied resolvers; the new transitive check does not reject a shared non-definition-aware `EntryResolverInterface` solely because it appears in different branches. Do not add a global registry or restrict independent stateless metadata sharing. Custom resolver wrappers own their hidden state and must not secretly share mutable definition ownership across sibling registrations.

### Container publication and failure ordering

`Container` continues to own canonicalization, protected-ID checks and invalidation of shared entries/deferred delegators. Every mutator has a preparation/validation boundary, a publication/invalidation batch that retains removed roots, and a final release boundary. The operation performs no additional binding/cache writes after starting final release; a reentrant mutation from a destructor therefore cannot be overwritten by unfinished outer invalidation.

For `set($requestedId, $value)`, the canonical ID captured when validating the operation remains its target even if validation autoloads code that retargets the requested alias. After successful definition registration, determine current deferred dependencies of that captured canonical ID from the current alias graph; do not use a stale pre-validation dependency list or canonicalize the requested alias again to select another source for invalidation. Independently completed nested alias/binding changes remain in place.

The successful definition flow is:

1. Validate the mutable canonical target and create its operation holder.
2. Ask the resolver to install the explicit definition, then absorb its holder. Existing rejection normalization applies only to selection/validation/registration. Retired configuration remains held.
3. Retain/remove the canonical base and invalidate its currently affected resolved entries and deferred delegators through the same holder. Every member of the invalidation batch reaches its final revision/index state before retirement starts.
4. Call `release()` outside the validation-rejection catch. Destructors now observe the committed binding and completed cache invalidation; a retirement exception does not restore prior state.

Raw-value `set()` uses the same flow except that it retains/replaces the base directly and leaves resolver definitions untouched. `alias()`, `delegator()` and `addContainer()` use their existing binding/affected-set semantics, pass one holder through all their invalidations, and release at the end. Validation that may invoke user/autoload code must complete before binding publication; requested-pipeline mutations recheck protected-target eligibility against the current alias graph after such validation. Helpers must not invoke a second user-code validation pass after that final eligibility check.

| Event | Binding/routing | Affected shared caches | Outcome |
| --- | --- | --- | --- |
| Unsupported definition or validation failure before publication | No speculative write by the rejected operation | Unchanged by the rejected operation | Existing rejection; independently completed nested mutations are preserved. |
| Successful replacement | New | Invalidated | Normal return after retirement. |
| A destructor throwable propagates into the active retirement invocation | New | Entire affected batch invalidated | `MutationRetirementException`; subsequent lookups use the effective new registration and unaffected entries preserve identity. |
| Non-selected custom remover violates the detach contract | New | Invalidated | `MutationRetirementException`; failing extension may still retain obsolete state. |
| Old factory is still executing in a Fiber | New for later resolution | Invalidated; existing revision protection remains | Old invocation retains what it needs until it ends; no cancellation or stale publication. |

The holder neither prevents nor rolls back application effects. A destructor can deliberately perform another mutation, retain/resurrect an object, throw, or suspend supported execution. Any subsequent successful mutation is then the current state. No lock, cancellation, replay, implicit retry, forced GC, or rollback of validation/destructor/external-container side effects is added.

## Compatibility and change surface

This is a **next-major API change**. Adding `removeDefinition()` and changing `setDefinition()` from `void` to `MutationRetirement` breaks existing interface implementations. Do not introduce those changes into the current 5.x bug-fix line. The mechanism itself does not require PHP 8.5 syntax; any platform-floor change belongs to the separate [PHP 8.5 plan](php85-callbacks.md).

Existing `Container` callers keep the same method signatures. They gain an explicit committed-retirement failure category. Document that committed outcome alongside validation rejection in both READMEs, and replace the current 5.x limitation notice only when this protocol is implemented. Consumers catching `InvalidConfigurationException` still catch the new subtype, but those deciding whether to retry must inspect `MutationRetirementException` first.

There is no persistent data migration, generated container, or serialized graph to migrate. Custom resolvers migrate with the package major: implement detach-only removal, return receipts from successful registration/removal, and stop releasing previous state before the caller's invalidation boundary. Direct mutation callers must release returned receipts at their own publication boundary. No fallback adapter can safely add these guarantees to an opaque old implementation, so the target has no permanent legacy path.

| File or consumer | Required change |
| --- | --- |
| `src/Resolver/Entry/DefinitionAwareResolverInterface.php` | Required mutation/removal signatures and conformance documentation. |
| New `src/MutationRetirement.php` | Hold and aggregate detached definition/cache/callable roots until explicit retirement. |
| New `src/Exception/MutationRetirementException.php` | Distinguish committed cleanup failure from rejection and preserve causes. |
| `src/Resolver/Entry/FactoryResolver.php` | Retain old callable/definition before same-owner replacement; detach configuration and validation flags on removal. |
| `src/Resolver/Entry/InvokableResolver.php` | Implement the same protocol for class registrations. |
| `src/Resolver/Entry/CompositeResolver.php` | Exclusive ownership transfer, initial-owner cleanup, nested aggregation and duplicate-tree guard. |
| `src/Internal/EntryCache.php` | Mutation-aware base replacement/removal and batched value invalidation using the supplied holder; keep ordinary resolution writes direct. |
| `src/Internal/DelegatorRegistry.php` | Retain discarded callable chains and propagate the holder through registration/dependency invalidation. |
| `src/Container.php` | Keep one holder through each public mutation's complete invalidation and release outside rejection normalization. |
| `src/ContainerFactory.php` | Verify existing leaf bootstrap still participates without creating a second initial-ownership registry. No new definition execution layer. |
| README and README.ru.md | Document replacement outcomes and custom/direct resolver migration. |
| Public custom implementations and direct callers | Explicit next-major migration; apply shared conformance scenarios. Their identities are not discoverable from this repository. |

Keep `FactoryResolver`, `InvokableResolver`, their maps and current resolution paths. Replace only the implicit abandon/overwrite behavior at mutation time. `ReflectionResolver` remains an `EntryResolverInterface` and gains no definition-removal API.

## Verification and implementation plan

The slices below implement the normative target. They are not authorization to apply the major-version change during the current audit.

### Slice 1 — Consistent registration publication and retirement

**Intent:** close the three reproduced failures through one coherent ownership/retirement protocol. **Depends on:** the next-major API target described above.

Implement the required interface, retirement holder and exception; migrate all three built-in definition-aware implementations, mutation-only `EntryCache`/`DelegatorRegistry` helpers and all four public `Container` mutators in the same slice. Replace mutation overwrites/evictions with retain-before-detach behavior and include the nested duplicate guard where recursive removal is introduced. Do not ship an interface change with unmigrated implementations, an unprotected adjacent invalidation caller, or an old-owner cleanup pass deferred to later work.

Start with public contract tests reproducing all three diagnostic cases. Add the cross-owner `ClassDefinition` case with object-valued constructor/method arguments, initial declarative owners and an explicitly registered owner, and reverse transitions back to factory/invokable definitions. Check weak references only after dropping fixture/application references; never assert private map sizes.

Use a retired factory whose destructor reenters `get()` to verify it sees the new result after publication, and a throwing destructor to verify the dedicated failure class, retained cause, new `get()`/`make()` values and repeatable later lookup. Include same-owner replacement. Use distinct decorated alias wrappers so releasing the first one would have interrupted the remaining invalidations on the baseline. Verify every affected sibling is current after a throwing release and that no destructor is called eagerly for a still-referenced application object.

**Done when:** all three original failures are gone; successful/rejected validation controls pass; all affected owners/callers use the holder; static analysis finds no old `void` implementation; ordinary lookups do not gain lifecycle work.

### Slice 2 — Extension, nesting and suspended-work conformance

**Intent:** prove the new boundary at its public extension seams. **Depends on:** slice 1.

Add shared behavioral scenarios for factory, invokable, composite and test-only custom resolvers. Cover a custom resolver rejecting before mutation, idempotent removal of an unknown ID, a custom removal violation surfaced after committed invalidation, nested ownership switches, bootstrap registrations, and rejection of a shared mutable leaf in sibling composites. Both direct resolver and `Container` flows must release holders without retaining an audit/history list. Verify active/consumed/released holder transitions, invalid absorption without reference loss, error ordering, repeated release after failure and release reentry while a destructor is suspended. Exercise `alias()`, `delegator()` and `addContainer()` with retired cached chains or wrapper objects, including multiple release failures, an object retained by the application, and a reentrant nested mutation from a destructor. Confirm the outer mutation performs no later invalidation that overwrites that nested result.

Exercise a Fiber-suspended old factory while replacing its definition with a different owner: the current binding remains usable according to existing concurrent-resolution rules; the old result must not restore a cache entry when it resumes; its captured object remains alive only while the invocation needs it. Repeat with an alias and deferred-delegator dependent. A destructor-suspension fixture must separately show that publication/invalidation is already complete while the first retirement invocation is paused, and that resuming it does not overwrite a later successful mutation. Use a non-throwing cyclic object as a collection-timing control; do not turn deferred GC or shutdown error capture into an acceptance promise. Preserve raw-value `set()`/fresh-`make()` behavior explicitly. Add validation-autoload controls that retarget an alias or complete a nested mutation before the outer validation succeeds or rejects; anchor the outer operation to its original canonical ID and do not undo the nested mutation.

**Done when:** every implementation claiming the protocol passes the same ownership/failure obligations, and the preserved lifecycle suites stay green on supported PHP versions. No extension capability check or concrete-leaf coupling remains as a silent fallback.

### Slice 3 — Consumer documentation and final convergence

**Intent:** make the changed failure/ownership contract usable by package consumers. **Depends on:** slices 1 and 2.

Document the next-major migration and committed-retirement outcome in both READMEs, update examples of direct resolver mutation, and convert the diagnostic observations into links to the accepted regression cases. Inspect every `DefinitionAwareResolverInterface`, `setDefinition()` and `removeDefinition()` reference, including constructor-based standalone composition, to confirm correct receipt ownership. Remove any temporary compatibility or investigation path introduced during implementation.

Run the repository's complete formatting/static-analysis/test checks and applicable Linux/Windows/coverage/mutation gates. Compare the unchanged runtime benchmark's ordinary shared lookup and fresh construction controls using the same runtime/settings; this architecture should add work only to registration mutations, and no throughput percentage is promised. Use the public weak-reference scenarios to prove resource release rather than treating lower process RSS as proof.

**Done when:** observed behavior, public interfaces, docs, failure diagnostics and executable conformance tests agree with this specification; the known retention limit and both cache inconsistencies can no longer be reproduced; no general shutdown, transaction or definition registry framework has appeared.

### Acceptance mapping

| Requirement or preservation | Proof |
| --- | --- |
| R1 exclusive ownership | Public factory/invokable/ClassDefinition transitions; initial and nested owners; subsequent fresh/shared results. |
| R2 bounded retirement | Weak-reference payload/callable/cache-root tests; application-reference and cyclic-object controls; suspended factory lifetime; holder state/absorption and repeated release idempotence. |
| R3 distinct outcomes | Validation rejection without speculative writes; preserved nested validation mutation; captured canonical target plus a post-validation deferred-dependency scan; synchronous throwing/reentrant retirement using the committed graph; full alias-family eviction; custom removal violation; ordered multiple-cause preservation; suspended first release with a no-op second release. |
| P1 raw services | Raw `set()` changes shared lookup and retains the former `make()` definition. |
| P2 lookup contracts | Existing binding, alias, deferred delegator, external ownership, protected-core and null/false suites. |
| P3 in-flight work | Existing in-flight mutation and Fiber recovery contracts plus cross-owner replacement fixture. |
| P4 isolation and cost | Independent shared identity, current cache-parity/lifetime suites, no receipt store, unchanged lookup path review and controlled benchmark. |
| Public extension compatibility | Required-interface migration, direct-call receipt consumption, nested duplicate guard and no old-signature implementations. |

No material architectural choice remains deferred in this specification. Implementation readiness applies to this major-version protocol; the current line retains the documented ownership/retirement limits until that change is separately implemented and verified.
