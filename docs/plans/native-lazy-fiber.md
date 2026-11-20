# Shared native lazy objects and Fiber initialization

[Русская версия](native-lazy-fiber.ru.md)

**Status, 2026-11-20:** preserved native-runtime boundary, not a confirmed DI 6 regression. No synchronization architecture is selected. The recommendation is to document the boundary and let an application that requires serialized initialization choose its publication and scheduling policy. A library feature requires a concrete consumer requirement and the separate design below. This is independent of the completed [propertyless native-lazy correction](propertyless-lazy-lifecycle.md).

## 1. Observation and evidence boundary

The November 19 object review compared raw PHP `ReflectionClass::newLazyGhost()` / `newLazyProxy()` with DI `#[Lazy]` / `#[Proxy]`. Its source baseline was `af9eac7f7619a717e1b914bae4ef259ade03ea36`; the consolidated audit was delivered at `22ea0c4f4e8bb1763f77b4448673aed5383bb653`. The review reports the same observation on PHP 8.4.28 and 8.5.13:

| One object, while its initializer is suspended | Raw native PHP | DI route | After the original initializer resumes successfully |
|---|---|---|---|
| Ghost | A second execution context reads the intermediate property value. | Same. | The property has the completed value. |
| Virtual proxy | A second execution context receives a native uninitialized-property `Error`. | Same. | The property has the completed value. |

The historical fixture sets `value` from `default` to `starting`, suspends in the constructor, then sets it to `ready`. One Fiber starts initialization by reading `value`; the main execution context reads that same published object while the Fiber is suspended, then resumes it. This is a specific cooperative scheduling interleaving, not a claim about every property, initializer, runtime patch or scheduling policy.

The retained materials are:

| Original artifact | SHA-256 | Role |
|---|---|---|
| `lazy-shared-fiber-probe.php` | `eaae1f2116bf9693354c9a15fcac69f671e85d60a899cf18829a9182ee51dfba` | The four-route diagnostic source. Its bootstrap path names the historical review checkout. |
| `audit-objects-review.md`, “Additional boundary probes” | `3ab7eeac569a2cec3a5a2b63e66483483450af1a33aed29bc775fe7b2271a99a` | Records native/DI parity on both named runtimes and classifies the observation as a native same-object concurrency boundary. |
| `di6-audit-2026-11-19-22ea0c4.md`, §8 | `2e9b01ae2196a5f9f88bb9b86c995ae75c0d69f830795823128869972c94f63e` | Consolidates the limitation and explicitly declines to add a scheduler or locking automatically. |

The November 20 documentation inventory reread these materials. It did not rerun the probe. A separate retained raw-output artifact for the old probe was not found; the results above are the recorded historical observation, not newly executed evidence for the current HEAD. The original source, sequence and expected outcomes are sufficient to plan a fresh controlled reproduction when this boundary becomes an implementation requirement.

### Portable four-route reproduction

Save the complete block below as `native-lazy-fiber-reproducer.php` outside a checkout with development dependencies installed. Run it in a **fresh PHP 8.4+ process**, passing the repository path:

```sh
php native-lazy-fiber-reproducer.php /absolute/path/to/di
```

[`tests/bootstrap.php`](../../tests/bootstrap.php) and [`ContainerBuilder`](../../tests/Support/ContainerBuilder.php) provide ordinary public container wiring. The native routes allocate `Product`; the DI routes allocate its attributed `Ghost` and `Virtual` subclasses. All four use the same constructor implementation, backed property and suspend/read/resume sequence; the DI subclasses inherit the constructor. The fixture has no setup hooks or extra handlers; the observed parity applies to this sequence, not to all possible DI lifecycle effects.

```php
<?php
declare(strict_types=1);

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Tests\Support\ContainerBuilder;

$repository = realpath($argv[1] ?? getcwd());
if ($repository === false) {
    throw new LogicException('Pass the checked-out DI repository as the first argument.');
}
require $repository . '/tests/bootstrap.php';

class Product
{
    public string $value = 'default';

    public function __construct()
    {
        $this->value = 'starting';
        Fiber::suspend('constructing');
        $this->value = 'ready';
    }
}

#[Lazy]
class Ghost extends Product {}

#[Proxy]
class Virtual extends Product {}

$rows = [];
foreach (['native-ghost', 'native-proxy', 'di-ghost', 'di-proxy'] as $mode) {
    $reflection = new ReflectionClass(Product::class);
    $container = null;
    $entry = match ($mode) {
        'native-ghost' => $reflection->newLazyGhost(static fn($object) => $object->__construct()),
        'native-proxy' => $reflection->newLazyProxy(static fn() => new Product()),
        default => ($container = (new ContainerBuilder())->build())->get(
            $mode === 'di-ghost' ? Ghost::class : Virtual::class,
        ),
    };
    $fiber = new Fiber(static fn() => $entry->value);
    $first = $fiber->start();
    try {
        $during = $entry->value;
    } catch (Throwable $error) {
        $during = $error::class . ': ' . $error->getMessage();
    }
    $fiber->resume();
    $rows[$mode] = [
        'first' => $first,
        'during' => $during,
        'after' => $entry->value,
        'fiberResult' => $fiber->getReturn(),
    ];
}
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
```

This is a **static adaptation of the historical source, not a newly executed verification**. It replaces the old absolute bootstrap path with a checkout argument and expands formatting; the four routes and their observation order are preserved. The source defines the expected successful schedule as `first = constructing`, `after = ready` and `fiberResult = ready`; these are source-derived expectations, not independently retained raw results. The historical review reports the ghost's intermediate value and the proxy's native uninitialized-property `Error` during suspension. Treat the exact error message as runtime-dependent. A future qualification must capture the actual output, exact PHP version and selected source revision rather than presenting this adaptation as a current passing test.

## 2. Why creation isolation does not serialize later access

DI's per-attempt state, Fiber ownership and cache revision checks protect resolution and publication performed through the container. A successfully returned native lazy object can then be stored and accessed directly by application code. That later property access enters PHP's native object machinery without another call to `Container::get()` or the parameter resolver.

Consequently, protecting a factory call or checking a container cache cannot intercept every access to an already-issued object. A lock inside an initializer also does not by itself prove that a second direct property read waits for that initializer: the second access must actually enter a controllable waiting boundary. The historical raw-PHP control is important precisely because the same behavior exists without DI caches or plans.

Distinct lazy initialization attempts still require isolated lifecycle state. Preserving that isolation does not add synchronization of all consumers of one shared native instance. The propertyless D1/A correction addresses a different failure: native allocation silently omitted the initializer for unsupported classes. Its preallocation rejection is implemented and remains valid; it does not promise Fiber serialization for supported classes.

## 3. Need, benefit and alternatives

An application may require that no consumer observe a service until construction and setup have completed. A suspending initializer combined with early publication of one instance can violate that application requirement. The historical probe establishes the boundary; it does not establish that an actual consumer requires a new DI facility or that an external attacker can reach it.

| Alternative | Benefit | Cost and conditions | Status |
|---|---|---|---|
| Preserve native behavior and document it | Makes the existing concurrency promise explicit with no new runtime owner or hidden suspension policy. | Applications must control sharing when initialization can suspend. | Recommended baseline. |
| Complete initialization before application publication | Consumers only receive the completed instance under an application-owned publication rule. | Initialization becomes earlier relative to sharing; errors and scheduling belong to that application boundary. Verify that all access paths obey it. | Existing application-level approach, chosen per consumer. |
| Application-owned accessor or asynchronous facade | Can define waiting, failure, cancellation and retry around a controllable operation. | Changes how consumers obtain/use the service and depends on the application's scheduler. It does not transparently change arbitrary direct property reads. | Conditional consumer design. |
| Opt-in alternative lazy adapter with a specified synchronization protocol | Could provide a reusable stronger contract where a real integration requires one. | Must demonstrate interceptable access, object/type/identity compatibility, scheduler integration, reentry, exceptions, lifetime and custom-adapter behavior. | No implementation selected. |
| Add a global container lock or scheduler automatically | No established benefit for direct accesses to an already-issued native object. | New global behavior, potential deadlock/cancellation and latency costs, and an unproved interception boundary. | Not recommended from this evidence. |

Removing the relevant lazy/proxy marker selects ordinary reflection creation; replacing `get()` with `make()` alone does not disable that marker. Explicit factories or `ClassDefinition` have their existing lifecycle contracts. An application choosing them must arrange its required setup explicitly where that lifecycle requires it.

No measured throughput or memory improvement is claimed. The potential benefit of a stronger design is deterministic completion-before-use for a named consumer contract; it may deliberately trade deferred work and concurrency for that guarantee.

## 4. Conditional design sequence

1. **Describe the actual publication path.** Identify the shared service, the point at which its initializer may suspend, all direct property/method consumers, and who currently owns the scheduler. Distinguish one shared object from independent `make()` results and from a lookup that is still in flight.
2. **Select the public behavior.** Decide whether a concurrent consumer waits, fails immediately with a defined error, or is prohibited by the application contract. Define owner reentry, recursive initialization, failure propagation, cancellation, retry, and whether callers keep the same object identity.
3. **Prove the controllable boundary before designing a lock.** Reproduce raw and DI ghost/proxy behavior on the supported exact runtimes. Show that every operation covered by the proposed guarantee reaches its accessor/adapter. If direct native property access cannot be intercepted, narrow the promise or use an explicit application API.
4. **Keep synchronization ownership local.** Prefer the application publication/access boundary or an explicitly selected adapter. Specify the lifetime of any owner token, waiting Fiber and retained exception/result; do not store request values or runtime receivers in shared metadata plans. An ordinary container cache hit should not acquire a global scheduler dependency.
5. **Implement only the chosen contract.** Preserve eligible-target lazy timing where promised, callback-owned errors, custom adapter delegation and no replay of completed construction/setup. Document any intentional eager timing, type or identity migration. Do not infer rollback of arbitrary callback effects.
6. **Qualify correctness and cost.** Run the acceptance matrix below before wider integration. Measure uninitialized access, initialized access, uncontended creation and a defined contended workload separately. Report retained waiting state and cleanup; no throughput target can be chosen from this historical probe alone.

## 5. Acceptance and stopping rules

| Area | Required evidence for a future stronger contract |
|---|---|
| Runtime baseline | Raw and DI ghost/proxy controls, exact PHP version, source selection and captured output; record intermediate and final outcomes separately. |
| One shared object | The originally reported suspended-initializer schedule obeys the selected wait/fail/application-publication policy. Test property and method routes covered by that policy. |
| Independent attempts | Distinct objects and Fibers do not share invocation data, owner tokens or a failed attempt's completion proof. |
| Effects and identity | Constructor/setup/factory counters establish no unintended replay; wrapper/backing and caller-observed identities follow the selected contract. |
| Error and retry | Original initializer failure, waiting-consumer failure, cancellation and a later retry have explicit, tested ownership and timing. |
| Reentry | Same-owner recursion and cross-Fiber dependency cycles have a defined outcome, with no hanging test counted as a success. |
| Lifetime | After completion, failure or cancellation, release waiting Fibers, callback captures and service graphs when application/native owners are gone. Distinguish retained exception traces from a metadata leak. |
| Existing contracts | Propertyless-target rejection, native error precedence, ordinary eager paths and custom adapter delegation remain intact unless an explicit migration changes them. |
| Cost | Bounded serial measurements for unaffected paths and the chosen integration; keep all samples and distinguish synchronization cost from application work. |

Without a concrete requirement for stronger synchronization, publication of this boundary report completes the architectural documentation task. It does not open an unconditional scheduler project, block DI 6's accepted scope, or relabel native behavior as a local defect needing an automatic patch.
