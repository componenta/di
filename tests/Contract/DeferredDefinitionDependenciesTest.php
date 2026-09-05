<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class DeferredDefinitionDependency {}

abstract class DeferredDefinitionState
{
    public function __construct(public DeferredDefinitionDependency $dependency) {}
}

final class EagerDefinitionDependencyTarget extends DeferredDefinitionState {}

#[Lazy]
final class LazyDefinitionDependencyTarget extends DeferredDefinitionState {}

#[Proxy]
final class ProxyDefinitionDependencyTarget extends DeferredDefinitionState {}

test('ClassDefinition resolves constructor references when object initialization starts', function (
    string $class,
    bool $deferred,
): void {
    if (!is_a($class, DeferredDefinitionState::class, true)) {
        throw new \LogicException('Expected a definition dependency fixture class.');
    }

    $created = 0;
    $dependency = new DeferredDefinitionDependency();
    $container = (new ContainerBuilder())
        ->addFactory('constructor.dependency', static function () use (&$created, $dependency): DeferredDefinitionDependency {
            ++$created;
            return $dependency;
        })
        ->addDefinition($class, ClassDefinition::create($class)->constructor([
            'dependency' => Definition::reference('constructor.dependency'),
        ]))
        ->build();

    $target = $container->get($class);

    expect($created)->toBe($deferred ? 0 : 1)
        ->and($target->dependency)->toBe($dependency)
        ->and($created)->toBe(1)
        ->and($target->dependency)->toBe($dependency)
        ->and($created)->toBe(1);
})->with([
    'eager' => [EagerDefinitionDependencyTarget::class, false],
    'lazy' => [LazyDefinitionDependencyTarget::class, true],
    'proxy' => [ProxyDefinitionDependencyTarget::class, true],
]);

abstract class DeferredDefinitionOwner
{
    public function __construct(public DeferredDefinitionBackReference $dependency) {}
}

#[Lazy]
final class LazyDefinitionOwner extends DeferredDefinitionOwner {}

#[Proxy]
final class ProxyDefinitionOwner extends DeferredDefinitionOwner {}

final class DeferredDefinitionBackReference
{
    public function __construct(public DeferredDefinitionOwner $owner) {}
}

test('deferred ClassDefinition constructor references can point back to the shared owner', function (string $class): void {
    if (!is_a($class, DeferredDefinitionOwner::class, true)) {
        throw new \LogicException('Expected a deferred definition owner.');
    }

    $container = (new ContainerBuilder())
        ->addAlias(DeferredDefinitionOwner::class, $class)
        ->addDefinition($class, ClassDefinition::create($class)->constructor([
            'dependency' => Definition::reference(DeferredDefinitionBackReference::class),
        ]))
        ->build();

    $owner = $container->get($class);

    expect($owner->dependency->owner)->toBe($owner)
        ->and($container->get($class))->toBe($owner);
})->with([
    'lazy' => [LazyDefinitionOwner::class],
    'proxy' => [ProxyDefinitionOwner::class],
]);

test('failed deferred constructor references can resolve on the next initialization attempt', function (string $class): void {
    if (!is_a($class, DeferredDefinitionState::class, true)) {
        throw new \LogicException('Expected a definition dependency fixture class.');
    }

    $attempts = 0;
    $failure = new \RuntimeException('Constructor dependency unavailable.');
    $dependency = new DeferredDefinitionDependency();
    $container = (new ContainerBuilder())
        ->addFactory('constructor.dependency', static function () use (&$attempts, $failure, $dependency): DeferredDefinitionDependency {
            if (++$attempts === 1) {
                throw $failure;
            }
            return $dependency;
        })
        ->addDefinition($class, ClassDefinition::create($class)->constructor([
            'dependency' => Definition::reference('constructor.dependency'),
        ]))
        ->build();

    $target = $container->get($class);
    expect($attempts)->toBe(0);

    try {
        throw new \LogicException('Expected dependency failure, got: ' . $target->dependency::class);
    } catch (\Componenta\DI\Exception\ResolutionException $exception) {
        expect($exception->getPrevious())->toBe($failure)
            ->and($attempts)->toBe(1);
    }

    expect($target->dependency)->toBe($dependency)
        ->and($attempts)->toBe(2);
})->with([
    'lazy' => [LazyDefinitionDependencyTarget::class],
    'proxy' => [ProxyDefinitionDependencyTarget::class],
]);

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DeferredDefinitionParameterInspection {}

#[Lazy, DeferredDefinitionParameterInspection]
final class InspectedDefinitionTarget extends DeferredDefinitionState {}

final class DeferredDefinitionParameterObserver implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
{
    /** @var array<string|int,mixed> */
    public array $parameters = [];

    public function handle(
        object $attribute,
        \Reflector $target,
        \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
    ): void {
        $this->parameters = $context->parameters;
    }
}

test('deferred definition handlers receive resolved parameters and literal runtime overrides', function (): void {
    $observer = new DeferredDefinitionParameterObserver();
    $dependency = new DeferredDefinitionDependency();
    $literal = Definition::reference('runtime.literal');
    $container = (new ContainerBuilder())
        ->addService('constructor.dependency', $dependency)
        ->addAttributeDefinition(new \Componenta\DI\Attribute\Composition\AttributeDefinition(
            DeferredDefinitionParameterInspection::class,
            $observer,
        ))
        ->addDefinition(InspectedDefinitionTarget::class, ClassDefinition::create(InspectedDefinitionTarget::class)->constructor([
            'dependency' => Definition::reference('constructor.dependency'),
            'options' => ['nested' => Definition::reference('missing.overridden')],
        ]))
        ->build();

    $target = $container->make(InspectedDefinitionTarget::class, ['options' => ['nested' => $literal]]);

    expect($observer->parameters)->toBe([])
        ->and($target->dependency)->toBe($dependency)
        ->and($observer->parameters)->toBe([
            'dependency' => $dependency,
            'options' => ['nested' => $literal],
        ]);
});
