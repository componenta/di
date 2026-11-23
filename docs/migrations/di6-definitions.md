# Migrating definition extensions to DI 6

**Status: next-major development, unreleased.** This guide describes the definition and mutation APIs implemented on the DI 6 development branch. These changes are not part of the released 5.0.6 package. The corresponding design is [Definition ownership and mutation retirement](../plans/definition-owner-lifecycle.md). [Русская версия](di6-definitions.ru.md).

## Who needs to change code

Applications using `ContainerFactory`, public definitions and `Container::set()` keep those entry points. Code that constructs factory/invokable resolvers directly, implements `DefinitionAwareResolverInterface`, composes nested resolvers, or catches mutation failures needs the migration below.

| Previous use | DI 6 use |
| --- | --- |
| `DefinitionAwareResolverInterface` with an ID-to-definition map | `DefinitionResolverInterface`, which validates and executes supplied descriptors without owning registrations |
| `new FactoryResolver($factories, $container, $proxyFactory)` | `new FactoryResolver($container, $proxyFactory)`; register public definitions on a composite or use the direct validation/execution protocol |
| `new InvokableResolver($invokables)` | `new InvokableResolver()`; register `InvokableDefinition` objects on a composite or validate/execute one directly |
| Leaf `setDefinition()`, `can()` and `resolve()` on those two resolvers | Composite `setDefinition()`, `can()` and `resolve()`, or strategy `validateDefinition()` and `resolveDefinition()` |
| `EntryResolverInterface::resolve()` with an array of runtime parameters | Receive `EntryParameters` and read the array through `$params->values` |
| Direct `Container` construction with internal cache and alias objects | Pass alias and initial service arrays. The container builds and owns its runtime state. |
| `CompositeResolver::supportsDefinition()` | Removed; no aggregate descriptor-admission predicate is exposed. Register through standalone `setDefinition()` or the attached root's `Container::set()`. |
| Nested composites that observe later child registrations | Independent snapshots of the child's current bindings and ordered providers |
| Direct mutation or reuse of a composite already attached to a container | `Container::set()` for that root; a separate composition for another container |
| Every `InvalidConfigurationException` treated as rejected registration | Handle `MutationRetirementException` first: the mutation already committed |
| `DependencyConfiguration::normalize()` used to validate factory ABI or class eligibility | Admission during `ContainerFactory::create()`, standalone `setDefinition()`, or direct strategy validation |

The mutable leaf interface is removed. There is no compatibility adapter that preserves a hidden leaf registration map. Ordinary fallback resolvers continue implementing [`EntryResolverInterface`](../../src/Resolver/Entry/EntryResolverInterface.php); reflection fallback keeps that role.

`setDefinition()` and `Container::set()` perform real registration, including publication on successful admission; do not use them as dry runs. `can($id)` reports entry availability, not whether an arbitrary descriptor would be accepted. A strategy you own can classify its input kinds through `DefinitionResolverInterface::supportsDefinition()`, but that answer does not predict admission by another composite's ordered provider chain. Handle actual registration failures using the publication and retirement boundaries described below.

## Registration ownership and application behavior

[`CompositeResolver`](../../src/Resolver/Entry/CompositeResolver.php) exposes registration and resolution through a single binding table owned by its internal `EntryBindings`. Each binding captures its ID, chosen strategy and validated descriptor. Bootstrap definitions and later registrations enter this same table. Factory and invokable strategies hold no per-ID configuration map.

This corrects the former retention of a factory after switching definition strategies. It also gives the container a single publication point before retiring old configuration and cached values. Shared and fresh resolution therefore agree on the committed definition even if releasing an old value throws.

Public `Definition::factory()`, `Definition::invokable()`, `ClassDefinition` and `Definition::reference()` retain their existing roles. `ReferenceDefinition` remains an argument/reference descriptor; it is not an independent entry definition supported by the built-in strategies. Explicit invokable construction still calls the constructor with zero arguments. `ClassDefinition` still uses its configured arguments, runtime overrides and ordered method calls; it does not run target DI attributes.

In `ClassDefinition` constructors and configured methods, positional variadic arguments follow their numeric positions after applying runtime overrides. Named trailing arguments keep their order.

Raw-value `set()` continues to replace the shared value while preserving the definition used by `make()`. Setting a new definition replaces that underlying definition and invalidates affected shared results. This example uses only application construction and mutation APIs:

```php
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

final readonly class Message
{
    public function __construct(public string $text) {}
}

$container = (new ContainerFactory())->create(
    new Config([], new Environment([])),
    new DependencyDefinitions([
        'factories' => [
            'message' => Definition::factory(
                static fn(): Message => new Message('factory'),
            ),
        ],
    ]),
)->container;
assert($container instanceof Container);

$shared = new Message('shared override');
$container->set('message', $shared);
$fresh = $container->make('message');
assert($container->get('message') === $shared);
assert($fresh instanceof Message && $fresh->text === 'factory');

$container->set(
    'message',
    ClassDefinition::create(Message::class)->constructor(['text' => 'configured']),
);
$current = $container->get('message');
$fresh = $container->make('message');
assert($current instanceof Message && $current->text === 'configured');
assert($fresh instanceof Message && $fresh->text === 'configured');
assert($current !== $fresh);
```

Keep existing protected-ID and alias rules. For a definition registered through an alias, `set()` captures the canonical target before validation. If validation performs a nested mutation or suspends, the outer operation retains that captured target and uses the current dependency graph for invalidation. Successful nested mutations are not rolled back when an outer validation rejects.

## Bootstrap normalization and validation timing

[`DependencyConfiguration`](../../src/Configuration/DependencyConfiguration.php) validates configuration shape and factory identifiers, normalizes aliases/invokables, and validates the other configuration sections. It preserves factory-section values, including public definition objects. It no longer unwraps `FactoryDefinition` to its callable or validates factory ABI and `ClassDefinition` eligibility during normalization.

```php
use Componenta\DI\Configuration\DependencyConfiguration;

$definition = Definition::factory(static fn(): Message => new Message('normalized'));
$normalized = DependencyConfiguration::normalize([
    'factories' => ['message' => $definition],
]);
assert($normalized['factories']['message'] === $definition);
```

Consumers of the normalized array must accept the preserved descriptor instead of assuming every factory value is a callable. A successful `normalize()` call alone does not prove that factories or target classes are valid. `ContainerFactory::create()` admits the normalized definitions to the root before attachment; `CompositeResolver::setDefinition()` and `Container::set()` use the same strategy protocol. Unsupported definition kinds reject at admission.

`DependencyConfiguration::assertShape()` publishes the canonicalized array only after every section passes validation. On validation failure the caller retains the original sections.

Existing raw factory specifications in `DependencyDefinitions` remain supported. Bootstrap converts them to an internal admission descriptor. That internal type is not an application registration API. Known direct non-string callables are checked against the `(ContainerValue, array)` factory ABI at admission. Strings and unresolved service-method specifications retain deferred owner lookup and runtime validation. Admission does not instantiate deferred factory owners or execute their factories. Later extension bootstrap may request services through its existing normal behavior.

If an integration previously called `normalize()` as its complete semantic validation step, move that check to container construction or to the selected strategy's `validateDefinition()` method. Do not resolve a deferred owner merely to validate the normalized array.

## Direct built-in strategy users

[`FactoryResolver`](../../src/Resolver/Entry/FactoryResolver.php) now takes the container and proxy factory only. Its container must provide the normal `ContainerValue` bootstrap service. [`InvokableResolver`](../../src/Resolver/Entry/InvokableResolver.php) takes no constructor arguments. Both implement `DefinitionResolverInterface`, not `EntryResolverInterface`.

Direct users own the validated descriptor and pass it back to the same strategy with the same ID. Treat the result of validation as opaque configuration; it can be a different definition kind than the input. Do not pass an internal prepared descriptor back into fresh registration. The following continues the application example above:

```php
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;

$factoryStrategy = new FactoryResolver($container, new ProxyFactory());
$validated = $factoryStrategy->validateDefinition(
    'direct-message',
    Definition::factory(static fn(): Message => new Message('direct')),
);
$direct = $factoryStrategy->resolveDefinition('direct-message', $validated);
assert($direct instanceof Message && $direct->text === 'direct');

$invokableStrategy = new InvokableResolver();
$validatedInvokable = $invokableStrategy->validateDefinition(
    'empty-object',
    Definition::invokable(stdClass::class),
);
assert($invokableStrategy->resolveDefinition('empty-object', $validatedInvokable) instanceof stdClass);
```

A direct call has no shared entry cache. Use a standalone composite when IDs should be registered and resolved through an ordered provider composition. Use a container when registration must also invalidate shared values and delegator results.

## Custom definition strategies

Implement the three methods of [`DefinitionResolverInterface`](../../src/Resolver/Entry/DefinitionResolverInterface.php):

1. `supportsDefinition()` classifies the input kind without loading services, executing factories or invoking application callbacks.
2. `validateDefinition()` returns stable configuration for later execution. It does not publish a registration or retain an ID-to-definition map. A separate immutable normalized descriptor is often useful.
3. `resolveDefinition()` executes the exact validated descriptor supplied for that invocation. Store reusable metadata separately from invocation values; do not keep old descriptors or resolved service instances in a metadata cache.

The first supporting strategy wins. If it rejects validation, a later supporting strategy is not tried. Explicit bindings take precedence over fallback resolvers. A provider may implement both strategy and fallback contracts, but each role keeps its own responsibility. Custom definition strategies receive the caller's arguments with reserved DI metadata keys removed.

```php
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;

final readonly class TextDefinition implements DefinitionInterface
{
    public function __construct(public string $text) {}
}

final readonly class ValidatedText implements DefinitionInterface
{
    public function __construct(public string $text) {}
}

final class TextStrategy implements DefinitionResolverInterface
{
    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof TextDefinition;
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        if (!$definition instanceof TextDefinition || trim($definition->text) === '') {
            throw new InvalidConfigurationException('Text definitions require non-empty text.');
        }

        return new ValidatedText(trim($definition->text));
    }

    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed
    {
        if (!$validated instanceof ValidatedText) {
            throw new InvalidConfigurationException('Expected a validated text descriptor.');
        }

        return $validated->text;
    }
}
```

Convert custom definitions from mutable configuration containers to stable snapshots. If an object in a descriptor is application-owned and mutable, the strategy is responsible for defining what stable configuration means; DI does not deep-clone user graphs. An in-flight invocation may finish with its captured descriptor after replacement. Existing cache revisions prevent its old result from repopulating a cache invalidated by that mutation.

## Standalone compositions, snapshots and attachment

A standalone composite retains `setDefinition(string $id, DefinitionInterface $definition): void`. It performs validation, publication and retirement for its own table. It does not own container caches. Continuing the custom strategy example:

```php
use Componenta\DI\Resolver\Entry\CompositeResolver;

$strategy = new TextStrategy();
$child = new CompositeResolver($strategy);
$child->setDefinition('caption', new TextDefinition(' original '));
$snapshot = new CompositeResolver($child);

$child->setDefinition('caption', new TextDefinition('child update'));
assert($snapshot->resolve('caption') === 'original');

$snapshot->setDefinition('caption', new TextDefinition('snapshot update'));
assert($child->resolve('caption') === 'child update');
assert($snapshot->resolve('caption') === 'snapshot update');
```

Snapshot rules are identity-based:

- Child composites are flattened at construction. The parent imports current binding records and providers; it does not retain the child composite itself or observe later child registrations.
- A shared strategy object appears once in the definition strategy order, at its first position. Every fallback occurrence remains in provider order, even when the same fallback object occurs in separate children.
- Importing the same binding record through multiple snapshots is idempotent. Different records for the same ID conflict, even when they contain the same definition object or equivalent values.
- Passing the same provider object twice directly to one constructor rejects.
- Imported descriptors and strategies are shared by reference, not cloned. Create providers with appropriate container dependencies for each composition.

When a composite is attached to a `Container`, that container becomes the sole public mutation authority for the root. Direct `setDefinition()`, attachment to a second container, and importing that attached root as a child all throw `InvalidConfigurationException`. Attachment lasts for the composite's lifetime. Build a separate composition for another container and use `$container->set($id, $definition)` for runtime changes.

During construction, `EntryBindings` issues one private writer to `Container`. The writer uses the table's preparation, publication and retirement operations. The binding must come from that same table to be published.

### Direct container construction

Direct construction takes an entry resolver, a callable executor, and optional alias and service arrays. Each container creates its own cache, registries and cycle guards. Use `set()`, `alias()`, `delegator()`, and `addContainer()` for later changes.

```php
$container = new Container(
    $resolver,
    $executor,
    aliases: ['preferred' => 'service'],
    services: ['service' => $service],
);
```

When composing an `ObjectPipeline`, pass the same `AttributePlanBuilder` to `AttributeProcessor` and `ParametersResolver`, then pass those two components and a proxy factory to the pipeline. The pipeline owns its parameter store. Only the execution running the phase can materialize deferred arguments or change its state. A completed phase cannot dispatch its handlers again. A detached reflector received by an object handler preserves the creation class of inherited static methods.

Use `ObjectPipeline::create()` to run both object attribute phases and obtain the initialized object. `AttributeProcessor` provides `prepare()` and `hasHandlers()`. The pipeline supplies the `ObjectCreationContext` to handlers for each attempt.

Pass immediate parameters through `$params`, or pass a `resolveParameters` callback and leave `$params` empty. The callback can return an `EntryParameters` instance to preserve the source of forwarded arguments. Supplying both sources raises `InvalidConfigurationException` before creation starts. The pipeline captures the class named by an input reflector at entry. A handler's `$context->class` is a detached view of that class. A `ReflectionObject` or a derived reflector can supply attribute metadata while it still describes the selected class.

Derived reflectors can select native `ReflectionAttribute` declarations. Attribute plans and parameter targets reject synthetic declarations supplied as subclasses of `ReflectionAttribute` with `AttributeCompositionException`.

Attribute composition holds the selected declaration through validation rules and registry updates. Checks for completed phases, including bootstrap, use that declaration and consult a borrowed reflector while it still describes the same target.

`AttributePlan::target`, `AttributeUsage::target` and `ParameterTarget::reflection` expose independent native reflectors. Use `$builder->build($closure)` to plan attributes for `Closure::__invoke`. Each read of `target` recreates a `ReflectionMethod` with that closure's signature and attributes. Passing a `ReflectionMethod` for `Closure::__invoke` or a reflection of a dynamic property as a plan target raises `AttributeCompositionException`. For a `ReflectionObject` of a closure, `AttributeProcessor` inspects the methods of the `Closure` class declaration. A derived parameter reflector for `Closure::__invoke` supplies the attribute selection when its `ParameterTarget` is created.

The constructor rejects aliases whose source is a protected DI ID before attaching the resolver. Application aliases may point to protected services. A rejected alias configuration leaves the resolver available for a corrected construction.

## Custom fallback resolvers

Implement `resolve(string $id, EntryParameters $params): mixed` using `Componenta\DI\Resolver\Entry\EntryParameters`. Read application arguments through `$params->values`.

The argument arrays exposed by `EntryParameters` and the creation and parameter contexts are snapshots. Editing a local copy of an array leaves the invocation inputs intact, including nested arrays that contain PHP references. Application objects retain their identity. Explicit nested references still reach native invocations.

To forward the same construction, pass `$params` to the next entry resolver or `ObjectPipeline::create()`. A deferred parameter supplier can return the same object. Use `$params->forwardTo($factory, $id)` with a native container or an adapter implementing `EntryParametersFactoryInterface` to preserve HTTP field provenance. An adapter continues construction in `makeEntry()` and can use `without($key)` to remove an argument. Other factories receive application arguments through `make()`. Passing an ordinary array to `make()` starts an independent construction.

## Committed retirement failures

All four public registration mutations follow validation, publication, complete affected invalidation, then native release of retired roots: `set()`, `alias()`, `delegator()` and `addContainer()`. A standalone composite applies the corresponding sequence to its own binding table. No mutation writes follow the start of final retirement.

[`MutationRetirementException`](../../src/Exception/MutationRetirementException.php) extends `InvalidConfigurationException` and means that the registration already committed. Handle this subtype before a broader invalid-configuration catch:

```php
use Componenta\DI\Exception\MutationRetirementException;

try {
    $container->set('message', Definition::factory(
        static fn(): Message => new Message('replacement'),
    ));
} catch (MutationRetirementException $failure) {
    // The mutation that produced this failure already committed.
    // Report cleanup failures; do not blindly retry the outer registration.
    foreach ($failure->causes as $cause) {
        error_log($cause->getMessage());
    }
} catch (InvalidConfigurationException $failure) {
    // This outer registration was rejected before publication.
    // Earlier successful nested mutations, if any, remain in effect.
    throw $failure;
}
```

The exception exposes:

| Member | Meaning |
| --- | --- |
| readonly `?string $id` | Captured canonical ID for `set()`; requested alias/delegator ID for `alias()`/`delegator()`; `null` for `addContainer()`; registered ID for standalone `setDefinition()` |
| readonly `array $causes` | Non-empty ordered list of every `Throwable` synchronously delivered at directly retained root release boundaries; causes are not deduplicated |
| `getPrevious()` | The first element of `causes` |
| Message | Explicitly states that the mutation committed |

The committed outcome belongs to the operation whose retirement produced the exception. Existing Componenta exception propagation is preserved: a validation or ownership-inspection callback can itself perform a nested mutation and propagate that nested mutation's retirement failure before the outer operation publishes. In that case the outer mutation remains unpublished and the nested committed change remains in effect. Inspect the error's context and causes; the exception type alone does not turn arbitrary reentrant callback execution into an atomic outer operation.

Retirement attempts every directly held root in insertion order, continues after delivered errors, and drops ownership through native reference release. It does not call `__destruct()` directly or force garbage collection. It does not promise destruction order within nested user graphs. Application references, active invocations, cycles, resurrection or exception traces may keep an object alive. Later GC, another destructor Fiber and shutdown failures are outside the synchronous exception aggregation boundary.

A destructor that reenters lookup observes completed publication and invalidation. If it makes another successful mutation, that newer mutation remains current even if the outer retirement later throws. A suspended destructor likewise cannot cause later outer writes over a newer mutation. This is a committed-outcome contract; it does not promise rollback of application callbacks or external side effects.

## Entry resolution and metadata updates

`get()` throws the PSR-11 `NotFoundExceptionInterface` when no route exists for the requested ID. A missing dependency inside a selected factory or constructor is a `ResolutionException` with the original failure in `getPrevious()`. The same classification applies when a selected external container throws `NotFoundExceptionInterface` or Componenta DI's `NotFoundException` escapes a delegator. A third-party `NotFoundExceptionInterface` thrown directly by a delegator is wrapped in `DelegatorException`, which keeps the original failure in `getPrevious()`. Update catch blocks that treated a missing dependency as a missing requested entry. `make()` and exceptions raised after a lazy object has been returned retain their own error boundaries.

A reentrant lookup skips active ownership checks for the same ID in the current execution. A provider's `has()` can read that ID through another provider or a local entry. A same-ID `get()` during external retrieval detects a circular dependency. The recursive search checks providers in registration order and skips retries of the same unsuccessful recursive route. Each explicit lookup by the same running provider can retry its earlier misses, even inside another recursive lookup. Published container changes also allow fresh checks. A search with no external owner falls back to local entries.

After a local miss, `has()` and `get()` follow a new alias target and consider providers registered during the lookup. They check eligible external providers that they have yet to visit before running another local fallback. A published local value can establish availability immediately for `has()`. Returning to the same route without a new binding, provider, or available target class fails with `CircularDependencyException`.

When `addContainer()` checks an existing provider, nested `get()` and `has()` for the same ID skip that provider's active check. A nested registration can confirm ownership for the enclosing check. A confirmation based on reads from the registry expires when another provider is added. If a read bypasses a provider whose ownership is unresolved, dependent factory results and delegator chains stay local to the resolution attempt, including when it spans multiple DI containers.

A DI-managed `#[Lazy]` or `#[Proxy]` initializer that encounters an incomplete external lookup throws `ResolutionException`. With the native proxy factory, the same object can retry initialization on the next access after the ownership check finishes.

Service IDs accept arbitrary bindings, including class names bound to factories returning a different class. The static result of `get()` is `mixed`, and `FactoryInterface::make()` returns `object`.

When `alias()` turns a canonical ID into an alias, it releases the former definition and base value after publishing the route and invalidating the caches. Synchronous destructor failures are reported through `MutationRetirementException`. An active resolution can return its previously selected result and checks the binding revision before writing to the cache.

When a registered external provider changes ownership or the value it returns for an ID, publish the change and call `notifyExternalEntriesChanged()` with that provider and the affected IDs. This also applies when registering another container changes which IDs the earlier provider supplies. The notification refreshes the cached delegator chains that depend on those IDs, including transitive dependents. Provider order determines the first owner, and warmed entries outside the affected dependencies keep their identity.

```php
$container->notifyExternalEntriesChanged($provider, 'reports.decorator');
```

`addContainer()` can call the candidate's `has()` during inspection before the candidate is visible to lookups. When the candidate derives ownership from other providers, register those dependencies first so availability stays current during inspection.

`ContainerFactory::create()` supports the existing global attribute discovery. Use `createWithTargetedAttributeDiscovery($config, $dependencies)` to limit active autoloading to attributes declared on the current target. This mode retries missing declarations on the next related lookup and observes declarations loaded between resolution steps. If loading an unrelated name declares a required attribute, load that module explicitly before consuming the dependent declaration.

Bootstrap records both the selected parameter resolver and the classification of the preceding resolvers. It checks newly loaded declarations on the original named parameters and live callbacks after releasing its temporary references. A borrowed reflector can supply metadata while it still represents that declaration. For a callback whose reflector is gone, the semantic attribute check uses the recorded declaration names. A suspended attempt verifies its original resolver selection even if it resumes after the factory returns.

Constructor argument policies and the provenance of mapped request values are checked through final configuration. Lazy objects and proxies run those checks at initialization. An incompatible change fails the attempt before caching the result or completing initialization.

Use `InstanceCreator::create()` and `initialize()` for standalone construction. Pass `ParametersResolver` directly to a standalone `ObjectPipeline`. Container assembly owns the attribute registry, plan builder, attribute processor, parameter resolver chain and object pipeline. Register extensions through `DependencyDefinitions`. Custom HTTP mappers obtain the configured handler with `get(RequestAttributeHandler::class)`.

## Migration checks

Search extensions for the removed interface, old resolver constructor arguments, leaf `setDefinition()`/`resolve()` calls, and assumptions that a normalized factory is already a callable. Move registrations to the root and keep configuration in descriptors. Review nested composites for live-child assumptions and shared attached roots. Update mutation error handling before enabling retries based on `InvalidConfigurationException`.

Executable contract coverage accompanies this guide:

- [Custom strategy admission and in-flight descriptors](../../tests/Contract/CentralDefinitionStrategyContractTest.php).
- [Direct built-in strategy use and deferred owners](../../tests/Contract/DefinitionStrategyDirectUseContractTest.php).
- [Snapshot composition and attachment authority](../../tests/Contract/DefinitionSnapshotCompositionContractTest.php).
- [Public mutation retirement](../../tests/Contract/MutationRetirementContractTest.php) and [native release boundaries](../../tests/Contract/MutationRetirementBoundaryContractTest.php).
- [Normalization timing](../../tests/Contract/DependencyNormalizationContractTest.php).
