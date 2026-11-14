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
| Nested composites that observe later child registrations | Independent snapshots of the child's current bindings and ordered providers |
| Direct mutation or reuse of a composite already attached to a container | `Container::set()` for that root; a separate composition for another container |
| Every `InvalidConfigurationException` treated as rejected registration | Handle `MutationRetirementException` first: the mutation already committed |
| `DependencyConfiguration::normalize()` used to validate factory ABI or class eligibility | Admission during `ContainerFactory::create()`, standalone `setDefinition()`, or direct strategy validation |

The mutable leaf interface is removed. There is no compatibility adapter that preserves a hidden leaf registration map. Ordinary fallback resolvers continue implementing [`EntryResolverInterface`](../../src/Resolver/Entry/EntryResolverInterface.php); reflection fallback keeps that role.

## Registration ownership and application behavior

[`CompositeResolver`](../../src/Resolver/Entry/CompositeResolver.php) owns the explicit binding table. Each binding captures its ID, chosen strategy and validated descriptor. Bootstrap definitions and later registrations enter this same table. Factory and invokable strategies hold no per-ID configuration map.

This corrects the former retention of a factory after switching definition strategies. It also gives the container a single publication point before retiring old configuration and cached values. Shared and fresh resolution therefore agree on the committed definition even if releasing an old value throws.

Public `Definition::factory()`, `Definition::invokable()`, `ClassDefinition` and `Definition::reference()` retain their existing roles. `ReferenceDefinition` remains an argument/reference descriptor; it is not an independent entry definition supported by the built-in strategies. Explicit invokable construction still calls the constructor with zero arguments. `ClassDefinition` still uses its configured arguments, runtime overrides and ordered method calls; it does not run target DI attributes.

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

The first supporting strategy wins. If it rejects validation, a later supporting strategy is not tried. Explicit bindings take precedence over fallback resolvers. A provider may implement both strategy and fallback contracts, but each role keeps its own responsibility. Composite calls strip DI-private parameter metadata before custom strategy or custom fallback execution.

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

The internal `prepareDefinition()`, `publishDefinition()` and `attach()` methods are not the extension mutation API. Prepared records are bound to their producing composite. An attached root requires its container's private identity capability for publication; no public accessor exposes that capability. Applications do not create capabilities, publish records, release retirement holders or call cache-invalidation helpers.

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

## Migration checks

Search extensions for the removed interface, old resolver constructor arguments, leaf `setDefinition()`/`resolve()` calls, and assumptions that a normalized factory is already a callable. Move registrations to the root and keep configuration in descriptors. Review nested composites for live-child assumptions and shared attached roots. Update mutation error handling before enabling retries based on `InvalidConfigurationException`.

Executable contract coverage accompanies this guide:

- [Custom strategy admission and in-flight descriptors](../../tests/Contract/CentralDefinitionStrategyContractTest.php).
- [Direct built-in strategy use and deferred owners](../../tests/Contract/DefinitionStrategyDirectUseContractTest.php).
- [Snapshot composition and attachment authority](../../tests/Contract/DefinitionSnapshotCompositionContractTest.php).
- [Public mutation retirement](../../tests/Contract/MutationRetirementContractTest.php) and [native release boundaries](../../tests/Contract/MutationRetirementBoundaryContractTest.php).
- [Normalization timing](../../tests/Contract/DependencyNormalizationContractTest.php).
