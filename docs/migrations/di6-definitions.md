# DI 6 migration: definitions and extensions

[Русский](../ru/migrations/di6-definitions.md)

This document describes the **unreleased** DI 6 API. Application use of `ContainerFactory`, `get()`, `make()`, `set()`, factories and aliases is retained. The changes mainly affect declarative definitions and low-level extensions.

## Declarative definitions

`ClassDefinition::call()` replaces the former `method()` API. `constructor()`, `call()` and `autowire()` return new immutable definitions.

```php
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

$container->set(
    Client::class,
    ClassDefinition::create(Client::class)
        ->constructor(['endpoint' => 'https://api.example.test'])
        ->call('setLogger', [Definition::reference(LoggerInterface::class)]),
);
```

`ClassDefinition` executes configured constructor and method calls, but **does not execute the target's DI attributes**. Type-based fallback requires `autowire()`. `Definition::reference()` represents a configured argument, not a standalone entry. Invokables still call constructors with no supplied arguments. A factory owns creation of its result; the container does not subsequently run that result's DI attributes.

## Custom strategies

DI 6 holds registrations in a central binding owner. Definition strategies validate and execute supplied descriptors without maintaining their own ID-to-definition maps.

| Before | DI 6 |
| --- | --- |
| `DefinitionAwareResolverInterface` | `DefinitionResolverInterface` |
| `new FactoryResolver($factories, $container, $proxyFactory)` | `new FactoryResolver($container, $proxyFactory)` |
| `new InvokableResolver($invokables)` | `new InvokableResolver()` |
| Registration on individual built-in strategies | `CompositeResolver::setDefinition()` |
| Fallback `resolve(string $id, array $params)` | `resolve(string $id, EntryParameters $params)` |

`DefinitionResolverInterface` declares `supportsDefinition()`, `validateDefinition()` and `resolveDefinition()`. Validation returns the descriptor to execute later; a `CompositeResolver` owns registrations. Fallback resolvers continue to implement `EntryResolverInterface`.

`DependencyConfiguration::normalize()` preserves definitions in its factory section; it does not prove that a descriptor can be executed. Registration validates a definition before publication.

## Direct container construction

Prefer `ContainerFactory::create($config, $dependencies)` for assembling a container. Direct constructors of `Container` or its internal collaborators require a review of the changed DI 6 signature and ownership rules.

## Custom fallback resolvers

`EntryResolverInterface::resolve()` receives `EntryParameters`. The application arguments are available through `$params->values`. Use `EntryParameters::forwardTo()` to forward the same construction while preserving source provenance. Calling `make()` with a regular array begins another construction.

## Mutation errors

`set()`, `alias()`, `delegator()` and `addContainer()` invalidate affected caches before retired values are released. `MutationRetirementException` indicates that the mutation **already committed**, even though cleanup failed. Catch it before `InvalidConfigurationException`, and do not blindly repeat the registration. An invalid registration rejected before publication preserves the prior binding.

A missing requested ID is a PSR-11 not-found error; an unavailable dependency inside an existing entry is a resolution error.

Review custom resolver interfaces, former constructors and `ClassDefinition::method()` calls before upgrading. See [Definitions](../guide/definitions.md) and [Runtime behavior](../guide/runtime.md).
