# Lazy construction without backed instance properties

**Readiness:** blocked by **D1**, the public policy for classes that PHP cannot keep lazy. **Recommendation:** reject the request in the default native `ProxyFactory` before allocating an object. This document is a design proposal; production behavior has not changed.

Repository baseline: `897f8b10ca6537e7b2f96d20b2e00ed52571ff6f`, branch `refactor/di6-definitions-callbacks`. [Русская версия](propertyless-lazy-lifecycle.ru.md).

## 1. Confirmed defect and significance

`ProxyFactory::makeLazy()` and `makeProxy()` return the result of PHP's native allocation methods without checking whether the returned object is actually lazy. For native-eligible target classes, PHP deliberately returns an ordinary object when the class has no backed instance property: this includes classes with no properties, only static properties, or only virtual property hooks. The initializer or backing factory is then never called. Calling `ReflectionClass::initializeLazyObject()` afterward does not repair that result.

Consequently, the reflection DI pipeline can successfully return and share a `#[Lazy]` or `#[Proxy]` object whose constructor and `#[SetUp]` hooks never ran. `#[NoConstructor]` does not make the result correct: the constructor should be omitted, but its configured setup still disappears. An injection point using `#[Make('id'), Proxy(Target::class)]` can return an object without ever invoking the registered product factory. This is a lifecycle correctness defect, not merely an ineffective optimization.

The public interfaces already promise deferred callbacks and describe callbacks' later failures as owned by the callback. [`Lazy`](../../src/Attribute/Lazy.php) promises constructor execution on reflection-created services; [`NoConstructor`](../../src/Attribute/NoConstructor.php) preserves property injection and setup. Silent successful omission does not satisfy these contracts. No application-specific exploit is established; constructor validation or registration can be skipped if an application places that responsibility there.

The source references below are fixed to the audited baseline:

| Contract / route | Source method and location |
|---|---|
| Unchecked native result | [`ProxyFactory::makeLazy()` L21](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/ProxyFactory.php#L21), [`makeProxy()` L40](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/ProxyFactory.php#L40) |
| Deferred public callback promise | [`LazyObjectFactoryInterface` L20](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/LazyObjectFactoryInterface.php#L20), [`VirtualProxyFactoryInterface` L20](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/VirtualProxyFactoryInterface.php#L20) |
| Reflection lifecycle enclosed in the callback | [`ObjectPipeline::lazy()` L296](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/Object/ObjectPipeline.php#L296), [`proxy()` L343](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/Object/ObjectPipeline.php#L343) |
| Explicit factory injection enclosed in the callback | [`MakeHandler::create()` L213](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/Resolver/Attribute/Handler/MakeHandler.php#L213) |
| Container delegates to the selected adapter | [`Container::makeLazy()` L293](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/Container.php#L293), [`makeProxy()` L298](https://github.com/componenta/di/blob/897f8b10ca6537e7b2f96d20b2e00ed52571ff6f/src/Container.php#L298) |

### Reproduction and independent controls

Run [PropertylessLazyObservation.php](../audit/reproductions/PropertylessLazyObservation.php) from a Composer-installed checkout:

```sh
php docs/audit/reproductions/PropertylessLazyObservation.php
```

The script uses the public `ContainerFactory`, `Container`, and `ProxyFactory`, verifies their loaded source paths and SHA-256 hashes, and records outcomes. It does not add a failing test or encode the current omission as desired behavior. Another installation can be selected using the documented paired environment variables; mismatched loaded source is rejected. The [recorded evidence](../audit/2026-11-15-propertyless-lazy-evidence.json) contains both runtime observations, selected baseline-suite results and the fail-closed source-selection check.

The script produced the same observations on local PHP **8.4.23** and **8.5.8**:

| Case | Observed result |
|---|---|
| Native ghost/proxy, no properties / only static / only virtual | Normal object; callback count remains zero after explicit initialization |
| Public `ProxyFactory`, same three shapes | Same missing callback |
| `#[Lazy]` / `#[Proxy]` with constructor and setup | `get()` succeeds and shares the object; both lifecycle events are missing |
| `#[NoConstructor]` plus lazy strategy and setup | Setup is also missing |
| `#[Make]` plus property proxy around a registered factory | Factory invocation count is zero |
| An inherited private backed property | Real native laziness; one callback after explicit initialization |
| A backed property hook | Real native laziness; one callback after explicit initialization |
| `stdClass` and empty subclass | Normal object; no callback |
| `stdClass` subclass with a backed property | Real native laziness |
| Other internal class / its descendant | Native allocation error |

There are 42 recorded observations per runtime: 10 native layout cases, 10 native eligibility controls, 10 public-factory cases, 10 attributed pipeline cases, one factory injection and one rejected fallback prototype. These are observations, not 42 newly added passing regression tests. The binaries' reported versions are recorded; this evidence does not claim a new PHP release certification.

The runtime rule is independently documented in the PHP manuals for [newLazyGhost](https://www.php.net/manual/en/reflectionclass.newlazyghost.php) and [newLazyProxy](https://www.php.net/manual/en/reflectionclass.newlazyproxy.php). Native initialization and destructor semantics are described in the [lazy-objects RFC](https://wiki.php.net/rfc/lazy-objects) and [language manual](https://www.php.net/manual/en/language.oop5.lazy-objects.php). This issue is independent of the PHP 8.5 callable-expression E1 matrix.

## 2. Why an immediate eager fallback is not a safe local correction

The tempting change is to call the supplied callback immediately when native allocation returns an already non-lazy object. It has three material effects:

1. It changes the explicit deferred-callback promise and moves callback errors from a later property access into the creation call. The existing `ProxyFactory` catch boundary would also normalize exceptions that previously belonged to the caller's deferred callback.
2. For a proxy, the factory creates or returns a second object. Returning that object changes the public result's identity from a wrapper to the factory's product. Returning the placeholder instead loses factory-owned state and identity.
3. An ordinary placeholder has an active destructor. The prototype in the reproduction observes `constructor, destructor` **before the returned product is released**, then a second destructor when the product is released. One factory call has caused two destruction events, one on an object whose constructor was not run. Native initialized proxies ordinarily rely on the backing object's destructor instead.

The prototype is a separate function in the observation script; it did not modify `src/`. Keeping the placeholder alive merely postpones the extra destructor and adds ownership/lifetime problems. `resetAsLazy*()` cannot make a propertyless object lazy. Injecting a dummy property or generating a subclass changes class shape/identity and fails for final classes. None is an acceptable silent workaround.

## 3. Decision D1 and alternatives

| Option | Public outcome | Ownership / compatibility consequences | Assessment |
|---|---|---|---|
| **A. Reject before native allocation** | A DI exception identifies the unsupported target and strategy; no target instance or callback is created | Default native adapter owns its limitation; deferred contracts and custom implementations stay intact | **Recommended smallest coherent repair** |
| B. Eager fallback in high-level DI object/factory flows | Constructor/setup/factory run immediately, with no unused proxy placeholder | Requires an explicit timing policy, high-level versus direct-factory API distinction, identity and failure semantics; several callers must change together | Possible separate design if support is required |
| C. Universal eager fallback inside `ProxyFactory` | Callback runs immediately with an allocated placeholder | Violates deferred contract and adds the demonstrated destructor hazard | Reject |
| D. Keep successful omission and document it | Apparently valid but incompletely initialized service | Does not meet lifecycle requirements | Reject |

The minimum decision is **A or B**. Existing evidence establishes the defect and rules out C; it cannot infer that consumers want an exception rather than eager execution. The user's audit instruction requires a plan when such a policy must be chosen. No production slice is executable until D1 is recorded in the accepted target. No further broad audit or speculative API is required to choose.

## 4. Settled requirements and preserved behavior

- **R1:** successful DI creation must never silently discard the constructor/setup or supplied backing factory because native lazy allocation became a normal allocation.
- **R2:** no repair may allocate a disposable ordinary placeholder whose destructor introduces an extra application effect.
- **P1:** classes with eligible backed instance properties retain current deferred callback timing, retry behavior, per-attempt lifecycle order, and native proxy/ghost identities.
- **P2:** `NoConstructor` suppresses only the constructor; it does not suppress setup or property handlers.
- **P3:** default native adapter restrictions must not be imposed on user-supplied `ProxyFactoryInterface` implementations that can support other mechanisms. The interface explicitly allows alternative implementations.
- **P4:** `ClassDefinition` remains eager and does not execute class/property/parameter attributes. Ordinary application factories continue to own their own product initialization. This issue concerns reflection strategies, explicit proxy injection, and factories that themselves call the lazy factory API; a class-level `#[Proxy]` alone does not reinterpret an opaque factory product.
- **P5:** class autoload and pre-existing before-instantiation extension effects are not rolled back. The repair must avoid **new target allocation and callback effects** at the native adapter boundary; it cannot promise global side-effect-free resolution.

## 5. Fully specified candidate target for option A

This section defines the recommended policy if D1 selects A. It does not approve its implementation.

### Ownership and boundary

The default [`ProxyFactory`](../../src/ProxyFactory.php) owns the preallocation capability check for both `makeLazy()` and `makeProxy()`. Keep the two existing public interfaces. Reuse one private check in the adapter; do not add a container-wide eligibility registry, capability service, feature flag, strategy, or cache.

`Container::makeLazy()/makeProxy()`, `ObjectPipeline::lazy()/proxy()`, `MakeHandler::create()`, and `LazyServiceFactoryInterface` users continue calling the selected factory through the existing interfaces. They must receive the adapter's DI exception unchanged under the existing exception-preservation rules. Custom factory implementations bypass this default-adapter check by construction, not through a global opt-out.

### Native capability rule

Before calling `newLazyGhost()` or `newLazyProxy()`, inspect the reflected class and its complete inheritance chain. A declared property supplies a possible native lazy slot only if it is **non-static** and **not virtual**. Include private properties declared by every ancestor, even when absent from the leaf's `getProperties()` result. A backed property with get/set hooks qualifies; a virtual hook does not. Readonly and promoted backed properties qualify. Do not evaluate property values, call hooks, construct an object, or inspect custom attribute arguments to make this decision.

For a native-eligible user class with no such slot, reject before allocation. Treat `stdClass` and its descendants according to the same property rule: the empty forms are not made lazy merely because dynamic properties could be added later. A backed user subclass of `stdClass` remains eligible.

Preserve existing native error precedence. A missing class still fails in `ReflectionClass` construction. Interfaces, traits, abstract classes, enums, and internal classes other than `stdClass` (including user descendants of unsupported internal classes) bypass the new no-slot rejection and reach the existing native call and exception normalization. Gate this using the class-kind reflection flags and the complete internal-ancestor chain, without allocating a probe object. Do not use `isInstantiable()` as a shortcut: private/protected constructor visibility is a separate matter from native lazy allocation. The acceptance oracle is the corresponding native `newLazyGhost()` / `newLazyProxy()` failure: the adapter preserves that native failure as `previous`, rather than replacing it with the new no-slot diagnostic. A backed slot does not make an otherwise unsupported internal class lazy-capable.

The metadata rule is necessary for the default adapter, not a guarantee that PHP will accept every other class property/flag combination. Native allocation remains authoritative for its other restrictions. No new persistent cache is justified before measurement; the property scan runs when requesting a new wrapper, not on shared `get()` hits or every property access.

### Failure contract

Throw `ResolutionException` with `serviceId` set to the canonical target class name. The message must identify the requested ghost/proxy strategy, the absence of backed instance properties, and the available ordinary eager construction path. A dedicated new exception subtype is unnecessary. This deliberate eligibility rejection needs no fabricated native `previous` exception. It must not retain the callback, a reflection object or a service object in diagnostic payload fields.

Diagnostic payload ownership is distinct from PHP's native exception trace. With `zend.exception_ignore_args=0`, a retained exception can keep callback arguments alive through its trace. This proposal does not sanitize traces or promise collection in that configuration while the exception is retained. A lifetime test retaining the exception must set `zend.exception_ignore_args=1` and restore the prior value; alternatively release both the exception and caller references before testing collection.

No initializer, backing factory, target constructor, setup hook, property setter, or target destructor may run **because of adapter allocation** in this rejection. A failed container resolution must not publish a shared value; a later ordinary eager construction or explicitly selected custom factory can still succeed. Direct callbacks for supported lazy targets continue raising their later exceptions at initialization with their original identity; do not wrap them in a new callback solely for this check.

### Compatibility and benefit

Applications currently requesting native laziness for these classes will receive a deterministic failure instead of an incomplete successful service. Migration consists of removing native laziness for that service, using an explicit ordinary factory/definition, or selecting an implementation with an independently tested supported mechanism. Adding a meaningless property just to manufacture a lazy trigger is not the recommended migration.

The benefit is lifecycle correctness and a diagnosable native capability boundary. It is not a performance optimization. The production change is local; integration verification is broader because the same adapter serves multiple public routes. No numerical speedup or memory saving is claimed.

## 6. Candidate change surface and acceptance mapping

These are the files and proofs required by option A after D1; they are not executable production slices while D1 remains open.

| Surface | Required responsibility |
|---|---|
| `src/ProxyFactory.php` | One shared preallocation check; explicit DI failure for both native strategies |
| `src/LazyObjectFactoryInterface.php`, `src/VirtualProxyFactoryInterface.php` | Clarify unsupported native targets and preserve deferred timing / callback-owned later errors |
| `src/Attribute/Lazy.php`, `src/Attribute/Proxy.php`, `README.md` | Document default adapter restriction and eager migration path |
| Existing `tests/Contract/ContainerLazyObjectContractTest.php` plus a focused eligibility contract test | Direct container/factory behavior, meaningful pre-change RED and post-change GREEN |
| Existing object lifecycle and proxy injection tests | Ensure the actual caller routes preserve the adapter outcome and supported behavior |

| Requirement / boundary | Required public proof |
|---|---|
| R1, R2: rejection before allocation | No properties / static-only / virtual-only; both APIs; exact DI type and `serviceId`; callback, constructor and destructor counters remain zero |
| Inheritance and hooks | Private backed property declared only by a parent stays lazy; virtual-only parent stays rejected; backed hook, readonly and promoted property stay supported |
| Runtime limits and error precedence | Empty `stdClass`, empty subclass, backed subclass; missing class, interface, trait, abstract, enum, other internal class and descendant retain their native-error route and native `previous`; private/protected constructors must not be rejected solely by an `isInstantiable()` shortcut |
| P1: ordinary lazy contract | Creation calls no callback; initialization calls it once; second access reuses it; ghost identity and distinct proxy/backing identities remain native |
| P1: failure and retry | Callback exception instance remains identical on supported targets; a failed initialization can retry according to existing native/DI behavior |
| P2: object lifecycle | `Lazy/Proxy + SetUp`, and `NoConstructor + Lazy/Proxy + SetUp`; unsupported class throws before sharing; supported control executes correct lifecycle order |
| Explicit product factory | `Make + Proxy` for property and parameter; unsupported target cannot return a placeholder; ordinary eager product factory remains called with its own result |
| P3: extension mechanism | Existing supplied-factory/decorator fixture still receives the request; no global rejection prevents a custom implementation's supported behavior |
| P4: explicit definitions | The same propertyless class through `ClassDefinition` is eagerly constructed under its established policy |
| Diagnostics / lifetime | Stored DI payload contains only target/strategy metadata; after caller release, prove capture collection with retained exception only under temporarily enabled `zend.exception_ignore_args=1`, or release the exception too; no trace-sanitization claim |
| Production verification | Focused RED/GREEN on PHP 8.4/8.5; existing complete suite, PHPStan max and style; applicable CI matrix including Windows |

After acceptance, establish the rejection tests' RED first, change the shared adapter boundary with its contract documentation, then run the focused supported/unsupported matrix and existing integration gates. Review actual code against R1–R2 and P1–P5, especially preallocation timing, custom-factory bypass and absence of a leftover eager fallback. Do not measure correctness through private cache counters or add an arbitrary timing threshold.

## 7. Bounded remaining decision work

**D1:** confirm A's rejection policy or explicitly require B's eager support. The deciding evidence is already available: native callback omission, explicit public deferred contracts, the default adapter's callers, and the unsafe fallback's duplicate destructor. No additional production experiment is needed.

If B is selected, first revise the target to settle high-level versus direct API behavior, factory/ghost identity, constructor and setup timing, exception normalization, failed-attempt retry and custom implementation interaction. A high-level implementation must choose eager construction **before any placeholder is allocated**, reuse the existing eager object pipeline or application factory, and cover every relevant caller. These responsibilities cannot be completed by merely adding `if (!isUninitializedLazyObject(...))` to the current adapter. Until those contracts are explicit, B is not implementation-ready.

Production files and normal test expectations are unchanged by this proposal. The observation script is retained as a reproduction; the naive prototype is explicitly marked rejected and must not migrate into `src/`.
