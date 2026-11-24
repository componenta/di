# Migrating definitions and extensions from version 5

[Русская версия](../ru/migrations/di6-definitions.md)

Application-level entry points such as `ContainerFactory`, `get()`, `make()`, `set()` and configuration providers remain available. Review code that builds `ClassDefinition` objects, implements custom entry resolvers or constructs built-in resolution strategies directly.

## Class definitions

`ClassDefinition::call()` replaces `method()`. `constructor()`, `call()` and `autowire()` return new definition objects rather than mutating the existing one.

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

$container->set(
    Client::class,
    ClassDefinition::create(Client::class)
        ->constructor(['endpoint' => 'https://api.example.test'])
        ->call('setLogger', [Definition::reference(LoggerInterface::class)])
        ->autowire(),
);
```

`ClassDefinition` does not execute DI attributes on the target class or its members, even if `autowire()` is enabled. Its type-based fallback fills only missing arguments. `Definition::reference()` is an argument descriptor; it cannot be registered as a standalone entry using the built-in strategies. Explicit factory callables still receive a compatible prefix of `(ContainerValue, array)`.

## Custom definition strategies

The composite owns registrations. Definition strategies validate and execute descriptors without maintaining a separate ID-to-definition map.

| Version 5 use | Version 6 equivalent |
| --- | --- |
| `DefinitionAwareResolverInterface` | `DefinitionResolverInterface` |
| `new FactoryResolver($factories, $container, $proxyFactory)` | `new FactoryResolver($container, $proxyFactory)` |
| `new InvokableResolver($invokables)` | `new InvokableResolver()` |
| Register on a built-in strategy | Register through `CompositeResolver::setDefinition()` |
| Fallback `resolve(string $id, array $params)` | `resolve(string $id, EntryParameters $params)` |

A `DefinitionResolverInterface` implementation provides `supportsDefinition()`, `validateDefinition()` and `resolveDefinition()`. Classification selects the first supporting strategy. Validation returns a stable descriptor for subsequent execution and does not register it. Fallback strategies retain `EntryResolverInterface`.

`DependencyConfiguration::normalize()` preserves definition objects in the factory section. Normalization checks configuration shape; it does not by itself prove that a definition can be executed. Admission to a composite or container performs the semantic checks.

## Forwarding fallback arguments

Custom fallback resolvers receive `EntryParameters`; the values are exposed through `$params->values`. Use `$params->forwardTo($factory, $entry)` to preserve parameter provenance across a continued construction when the recipient supports it. Passing a plain array to `make()` starts another construction attempt.

Prefer `ContainerFactory::create($config, $dependencies)`. Code constructing `Container` or its internal collaborators directly must be updated to the new constructor and ownership rules.

## Registration failures and lifetime

The mutation methods `set()`, `alias()`, `delegator()` and `addContainer()` validate and publish their changes, invalidate affected state and then release replaced values. An error *before* publication leaves the previous binding intact. `MutationRetirementException` signals an error *after* the change committed.

```php
use Componenta\DI\Exception\MutationRetirementException;

try {
    $container->set('example', Definition::factory(
        static fn() => new Example(),
    ));
} catch (MutationRetirementException $error) {
    // The registration committed. Inspect $error->causes; do not retry blindly.
}
```

`MutationRetirementException` extends `InvalidConfigurationException`, so catch the specific type first. Its `id` and `causes` properties identify the affected mutation and ordered cleanup errors. Independently completed nested changes are not rolled back.

A missing *requested* entry produces a PSR-11 not-found exception; failure to obtain a dependency within an existing entry is a resolution failure.

## Before upgrading

Check uses of `ClassDefinition::method()`, old strategy constructors, implementation of entry resolver interfaces, assumptions about normalized factory values and exception handling after runtime registration. See [Dependency definitions](../guide/definitions.md), [Container API](../guide/container.md), and the public interfaces in `src/Resolver/Entry/`.
