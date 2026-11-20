# Dynamic ownership in external providers

**Status (2026-11-20): proposed; not implemented.** Audit finding **A20-C1** remains open. This report records a confirmed consistency boundary and the decision needed before changing the external-provider contract. It does not authorize a new protocol or restore the withdrawn repeated-`has()` patch. [Русская версия](external-provider-ownership.ru.md).

## 1. Scope and confirmed effect

DI tracks which external PSR-11 containers are registered and invalidates local deferred delegator pipelines when a registration takes over their dependency IDs. It cannot observe arbitrary changes inside an already registered provider. A provider's `has()` can change ownership of a different ID without changing registry membership. The registration operation can consequently publish a new direct owner while retaining an old dependent shared result.

The observed example has two deferred IDs, `first.owner` and `second.owner`, and a warmed local shared service `first`. An earlier provider supplies the callable for `first.owner`. While `addContainer($candidate)` checks `second.owner`, that earlier provider stops supplying `first.owner`. The candidate supplies its replacement. After registration, direct `get('first.owner')` returns the replacement, but shared `get('first')` can still return the same old object with value `first:previous`.

All of the following conditions are needed by this counterexample:

- At least two deferred dependency IDs are inspected.
- A local shared result depending on the first ID has already been created.
- An earlier external provider initially owns that ID; a later candidate can supply it after ownership is lost.
- A callback for another ID changes the first ID after its last relevant observation.
- The change does not use a DI mutation or another signal that changes the observed membership/alias state.

The mutation happens inside a callback invoked by registration itself. No application mutation between API calls, malformed missing-entry behavior, or concurrent thread is required. Nested-registration and nonnested variants were observed. Changes made by a provider between API calls are a related, separate case requiring an explicit signal as well.

This report concerns external ownership and the deferred dependency graph already maintained by DI. It does not propose rebuilding ordinary constructor dependencies or changing objects already issued to application code. The implemented [definition ownership and retirement contract](definition-owner-lifecycle.md) concerns local registrations and release ordering; it does not provide a snapshot of opaque provider state.

## 2. Revision and source evidence

The observation was recorded on PHP **8.4.28** and **8.5.13** during the audit ending at [`e11d69619fd808da1935b100c752fef7cf8bbf69`](https://github.com/componenta/di/commit/e11d69619fd808da1935b100c752fef7cf8bbf69). The relevant `Container.php` and registry source is unchanged at the next reviewed revision, [`43c464187f80c95802fc36191350ae68b70a469e`](https://github.com/componenta/di/commit/43c464187f80c95802fc36191350ae68b70a469e). Publishing this plan records existing evidence; it is not a claim of a new runtime verification.

| Evidence at `43c4641` | What it establishes |
| --- | --- |
| [`Container::addContainer()`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Container.php#L335-L368) | Inspection precedes registration and invalidation; the affected IDs determine which deferred pipelines are invalidated. |
| [`Container::deferredDependenciesTakenOverBy()`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Container.php#L487-L566) | Existing ownership can skip the candidate's `has()` for an ID. The proof observes registry revision, protected aliases and newly introduced deferred IDs; it has no provider-state revision. |
| [`ExternalContainerRegistry`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Internal/ExternalContainerRegistry.php) | `revision` increments when a new provider instance is registered. `findOwning()` obtains ownership by calling each provider's `has()` in order. |
| [`DelegatorRegistry`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Internal/DelegatorRegistry.php) | Deferred dependency IDs and affected pipelines are already tracked; resolved callable chains can be retained in cache until invalidation. |

The fixture below is included in full so the report can be reproduced from a checkout with development dependencies. It observes public APIs; reflection at the end records source provenance only.

## 3. Reproducer and the withdrawn fix

Save the PHP block below as `owner-reproducer.php` outside the checkout, then run:

```sh
php owner-reproducer.php /absolute/path/to/di
```

Use a checkout at the named revision with its development dependencies installed. [`tests/bootstrap.php`](../../tests/bootstrap.php) and [`ContainerBuilder`](../../tests/Support/ContainerBuilder.php) supply ordinary container wiring, not a copy of the affected algorithm. Run the same scenario on each PHP version being qualified. Record the emitted source filename/hash along with the revision.

```php
<?php
declare(strict_types=1);

namespace ComponentaAudit20Peer;

use Closure;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

$repository = realpath($argv[1] ?? getcwd());
if ($repository === false) {
    throw new \LogicException('Pass the checked-out DI repository as the first argument.');
}
require $repository . '/tests/bootstrap.php';

final readonly class Product
{
    public function __construct(public string $value) {}
}
final class Provider implements ContainerInterface
{
    public array $queries = [];
    public function __construct(private array $entries, private ?Closure $probe = null) {}
    public function has(string $id): bool
    {
        $this->queries[] = $id;
        return $this->probe === null ? array_key_exists($id, $this->entries) : ($this->probe)($id);
    }
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw NotFoundException::forService($id);
        }
        return $this->entries[$id];
    }
}

/** These checks cannot fire the second-ID callback because they inspect different IDs. */
function verifyProviderLookup(Provider $provider, string $id, bool $expectedHas, mixed $expectedValue = null): array
{
    $has = $provider->has($id);
    try {
        $value = $provider->get($id);
        $found = true;
    } catch (NotFoundExceptionInterface) {
        $value = null;
        $found = false;
    }
    $valid = $has === $expectedHas && ($expectedHas ? $found && $value === $expectedValue : !$found);
    if (!$valid) {
        throw new \LogicException('The fixture violated its observed get/has contract for ' . $id);
    }
    return ['has' => $has, 'get' => $found ? 'same value' : 'NotFoundException', 'valid' => $valid];
}

$results = [];
foreach ([0, 1, 2] as $releaseOnSecondIdProbe) {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $previous = static fn(Product $entry): Product => new Product($entry->value . ':previous');
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $di = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'second' => new Product('second'),
        'first.owner' => $local,
        'second.owner' => $local,
    ])->addDelegator('first', 'first.owner')->addDelegator('second', 'second.owner')->build();
    $state = (object) ['armed' => false, 'ownsFirst' => true, 'secondChecks' => 0];
    $earlier = new Provider(['first.owner' => $previous, 'second.owner' => $previous],
        static function (string $id) use ($state, $releaseOnSecondIdProbe): bool {
            if ($id === 'first.owner') {
                return $state->ownsFirst;
            }
            if ($id === 'second.owner') {
                if ($state->armed && ++$state->secondChecks === $releaseOnSecondIdProbe) {
                    $state->armed = false;
                    $state->ownsFirst = false;
                }
                return true;
            }
            return false;
        });
    $di->addContainer($earlier);
    $before = $di->get('first');
    $di->get('second');
    $candidate = new Provider(['first.owner' => $replacement, 'second.owner' => $replacement]);
    $state->armed = true;
    $di->addContainer($candidate);
    $candidateRegistrationQueries = $candidate->queries;
    $after = $di->get('first');
    $actualOwner = $di->get('first.owner');
    $expected = $state->ownsFirst ? 'first:previous' : 'first:replacement';
    $psrChecks = [
        'earlier_first' => verifyProviderLookup($earlier, 'first.owner', $state->ownsFirst, $previous),
        'candidate_first' => verifyProviderLookup($candidate, 'first.owner', true, $replacement),
        'earlier_missing' => verifyProviderLookup($earlier, 'missing.owner', false),
        'candidate_missing' => verifyProviderLookup($candidate, 'missing.owner', false),
    ];
    $results[] = [
        'release_on_second_id_probe' => $releaseOnSecondIdProbe,
        'second_id_checks' => $state->secondChecks,
        'final_owner' => $actualOwner === $replacement ? 'replacement' : 'previous',
        'actual' => $after->value,
        'expected' => $expected,
        'shared_identity_preserved' => $after === $before,
        'candidate_registration_queries' => $candidateRegistrationQueries,
        'ok' => $after->value === $expected,
        'provider_contract_checks' => $psrChecks,
    ];
}
$containerSource = (new \ReflectionClass(\Componenta\DI\Container::class))->getFileName();
echo json_encode([
    'php' => PHP_VERSION,
    'source_directory' => $repository . '/src',
    'container_source' => $containerSource,
    'container_sha256' => hash_file('sha256', $containerSource),
    'probe_sha256' => hash_file('sha256', __FILE__),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
```

The `0` case is a stable-provider control. On the reverted/current algorithm, case `1` reports replacement as the direct owner, `first:previous` as the shared value, preserved shared identity and `ok: false`. Case `2` does not activate the ownership change on that algorithm because the extra final probe is absent.

Commit [`cc4f0e3c8eca5045cda9b6d2b15f32edf39d0c94`](https://github.com/componenta/di/commit/cc4f0e3c8eca5045cda9b6d2b15f32edf39d0c94) tried recording IDs skipped because of an earlier owner and checking them again. It passed the original fourteen scenarios and the suites run for that patch. Independent review then moved the mutation to the last callback of the added proof:

| Mutation point on the attempted patch | Direct owner | Shared `first` | Assessment |
| --- | --- | --- | --- |
| No ownership change | Previous | `first:previous`, same object | Correct control. |
| First `has('second.owner')` | Replacement | `first:replacement`, new object | Original example corrected. |
| Added final `has('second.owner')` | Replacement | `first:previous`, same object | Counterexample remains. |

For the historical comparison, [`a25051ec6ddc3342c1e2191941deffe42ce8255d`](https://github.com/componenta/di/commit/a25051ec6ddc3342c1e2191941deffe42ce8255d) contains the attempted algorithm. Run case `2` there in an isolated checkout; it must not be interpreted as a failing case on the current algorithm when its trigger never fires. The fixture's `get()` checks availability and throws a PSR not-found exception for absent entries; its explicit consistency checks also cover missing IDs.

The attempted fix and its temporary fourteen-case regression file were **fully withdrawn** by [`23aefed0c2578609fe7b05d5f328f6753c08c025`](https://github.com/componenta/di/commit/23aefed0c2578609fe7b05d5f328f6753c08c025). The earlier peer-review suggestion to integrate that local correction was superseded by the counterexample and revert. Those tests are not current regression coverage, and that patch is not the implementation plan below.

## 4. Why this needs a contract decision

In any finite sequence of opaque, effectful `has()` calls, the last call can change an ID already checked while returning the same boolean for its own ID. DI can observe exactly the same answers and membership revision as in the stable case. The stable case requires preserving an unaffected shared object's identity; the changed case requires invalidating its dependent pipeline. Without another signal or a stability restriction, these states are indistinguishable to that algorithm.

Adding a third or later finite pass moves the counterexample rather than supplying the missing information. It also adds observable application callbacks. This reasoning concerns the available observations; it is not a claim that every provider behaves this way.

[PSR-11](https://www.php-fig.org/psr/psr-11/) defines `get()` and `has()`, not a provider-generation or invalidation-notification protocol. Its [meta document](https://www.php-fig.org/psr/psr-11/meta/) leaves mutators outside the standard. A stronger stability rule would be a Componenta extension contract, not an existing PSR purity guarantee. There is no demonstrated remote exploit in this scenario: the provider executes trusted application code. The confirmed consequence is inconsistent local service state under the stated conditions.

## 5. Options and recommendation

| Option | Problem addressed and benefit | Cost and compatibility |
| --- | --- | --- |
| **A. Explicit stable ownership for ordinary providers** | Defines the conditions under which current lookup and addressed invalidation give a coherent result. Adds no polling or cache flush. | Applications must keep ownership stable while dependent shared results remain in use, or use an agreed update mechanism. A stability restriction must be documented as a new explicit extension boundary. |
| **B. Opt-in generation or changed-ID notification** | Makes dynamic ownership observable, including changes between DI calls. Allows addressed invalidation with unaffected shared identity preserved. | Requires provider cooperation, public extension design, publication/failure rules, concurrency handling and subscription lifetime management. |
| **C. Conservative invalidation on registration** | Prevents old deferred results surviving registration solely because the owner proof missed a change. | Unrelated registration can change shared identity and rerun factories/delegators. Existing objects are not updated. Changes after registration still need a contract or signal. |

**Proposed recommendation:** adopt A as the documented mode for ordinary opaque providers. If a real consumer requires dynamic ownership, design B as an explicit opt-in extension. Prefer changed-ID notification where the provider already knows the affected IDs; this may avoid polling every provider on a warm `get()`. A generation-based alternative needs an explicitly costed observation point, including how changes between calls are seen before returning cached data. Do not choose C silently or claim that it preserves existing sharing semantics.

Adopting A alone would define the support boundary; it would not make the counterexample return a fresh result. A provider violating A need not be detectable by DI. The implementation must not imply that a fresh mutable `has()` answer proves continued stability.

## 6. Decisions required before implementation

1. **Supported changes.** Separate provider membership, ownership gain/loss for an ID, replacement of a callable value by the same owner, and mutation of an already returned object. Decide which changes must invalidate cached callable chains and shared pipeline results. Define behavior between API calls and the lifetime of already issued objects.
2. **Signal and publication order.** For generation, define its owner, monotonicity, state-publication order, and a callback-free observation mechanism. A freely effectful version getter merely moves the same problem. For notifications, define affected IDs, synchronous publication/invalidation ordering and allowed reentry. Choose one precise protocol before adding interfaces.
3. **Operation consistency.** Cover earlier providers and the candidate. A change detected during inspection must not publish mixed-generation conclusions. Preserve candidate `has() <= 1` per ID within one registration: this conflicts with arbitrary internal retries. A practical proposed outcome is a conflict before outer publication, followed by an explicit new application attempt. The exception/API and retry policy remain a design decision.
4. **Failure ownership.** A callback may already have completed an independent nested mutation. Do not promise rollback of application effects or nested operations. Specify registry/cache state after signal errors, conflicts and nested registration of the same candidate. Preserve protected aliases and publication before retirement.
5. **Addressed invalidation.** Reuse the existing dependency-to-pipeline graph rather than create a general object graph. Preserve unaffected shared identity. Define invalidation and retirement of callable chains/results, throwing destructors, and prevention of stale in-flight cache writes.
6. **Lifetime and concurrency.** Define nested notifications, registration during inspection, Fiber suspension, subscription/provider release and loop prevention. Avoid long-lived invocation receivers or callbacks captured in shared metadata.

## 7. Proposed implementation sequence

| Slice | Work and owning surface | Completion condition |
| --- | --- | --- |
| 1. Select the contract | Update this plan, public EN/RU provider documentation and migration notes. Identify an actual dynamic-provider consumer before selecting B. | Stable mode, dynamic requirements, same-owner replacement, between-call behavior and retry rules are explicit. No wording attributes them to PSR-11. |
| 2. Prototype B if selected | A conforming test provider and native/public scenarios exercise the chosen signal; design the adapter/registry interaction before production publication. | Both original and last-callback examples are distinguishable from a stable control using the signal. Candidate-call counts and failure outcome are specified. |
| 3. Integrate one protocol | Extend the public adapter contract and `ExternalContainerRegistry`; integrate `Container` inspection/invalidation with `DelegatorRegistry`, `EntryCache` and `MutationRetirement` as necessary. | No mixed-state publication, broad cache flush or repeated opaque proof is substituted for the chosen protocol. Existing local mutation contracts remain intact. |
| 4. Prove and document | Run the acceptance matrix, normal supported-platform checks, focused mutation checks where meaningful, and comparative benchmarks. Publish implementation/migration evidence for exact commits. | Observed results, supported guarantees, compatibility and cost agree; only then mark the selected option implemented. |

Slice 1 can finish independently as a documentation decision. Slices 2–4 depend on choosing dynamic support; they are not needed merely to preserve the current stable-provider mode.

## 8. Acceptance matrix

The rows below are requirements for a future chosen contract, not claims that new tests already exist.

| Boundary | Required observable evidence |
| --- | --- |
| Original counterexample | Under B, the signalled change updates the dependent result or produces the specified conflict before outer publication. Under A alone, clearly classify it outside the supported stability contract. |
| Last callback | Move the change to the final application callback of each phase. The signal/conflict mechanism still accounts for it; do not depend on another finite `has()` pass. |
| Stable control | Same owner, unrelated provider and unaffected services retain their shared identity. Record existing/candidate `has()` counts. |
| Membership versus ownership | Detect a conforming ownership change without a registry membership revision. Preserve membership-driven nested registration behavior. |
| Candidate responses | Positive and negative candidate answers, existing owner, no owner and duplicate registration retain their specified call counts and precedence. |
| Between-call changes | Under B, a change made after registration is observed at the defined boundary before a stale cached result is returned. |
| Same-owner value change | Follow the explicitly selected callable-replacement policy; ordinary object business-state mutation does not silently become graph rebuilding. |
| Aliases and dependencies | Protected IDs are never redirected; changed aliases, newly registered deferred IDs and transitive affected pipelines receive current invalidation. |
| Errors and nested work | Signal/`has()` failures and generation conflicts publish no speculative outer registration; completed nested mutations remain. Retirement failures describe committed outcomes. |
| Concurrency | Suspend during inspection, resolution and retirement; reentrant notifications do not mix invocations or restore stale caches after resume. |
| Lifetime | `WeakReference` controls show no obsolete provider/subscription/callable retention after all legitimate owners are released. Application-held old products remain valid references. |
| Performance | Compare stable warm `get()`, registration, dynamic updates and unaffected lookups at pinned revisions/runtimes; record `has()` calls, allocations and repeated factories/delegators. |

The benefit to demonstrate for B is correct addressed invalidation after an observable ownership transition while preserving unaffected identity. There is no measured throughput improvement to promise. Option A has minimal runtime cost but supplies a contract boundary, not automatic dynamic-provider support. This decision stays separate from local definition ownership, bootstrap metadata convergence and arbitrary application object-graph mutation.
