<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;

final class PromotedPropertyDependency {}

final class PromotedPropertyEvents
{
    public int $constructions = 0;
}

class MutablePromotedPropertyState
{
    public function __construct(
        #[ConfigAttribute('promoted.label')]
        public string $label,
        #[Inject]
        public PromotedPropertyDependency $dependency,
        #[Inject]
        public PromotedPropertyEvents $events,
    ) {
        ++$this->events->constructions;
    }
}

readonly class ReadonlyPromotedPropertyState
{
    public function __construct(
        #[ConfigAttribute('promoted.label')]
        public string $label,
        #[Inject]
        public PromotedPropertyDependency $dependency,
        #[Inject]
        public PromotedPropertyEvents $events,
    ) {
        ++$this->events->constructions;
    }
}

#[NoConstructor]
final class SkippedMutablePromotedTarget extends MutablePromotedPropertyState {}

#[NoConstructor, Lazy]
final class LazyMutablePromotedTarget extends MutablePromotedPropertyState {}

#[NoConstructor, Proxy]
final class ProxyMutablePromotedTarget extends MutablePromotedPropertyState {}

#[NoConstructor]
final readonly class SkippedReadonlyPromotedTarget extends ReadonlyPromotedPropertyState {}

#[NoConstructor, Lazy]
final readonly class LazyReadonlyPromotedTarget extends ReadonlyPromotedPropertyState {}

#[NoConstructor, Proxy]
final readonly class ProxyReadonlyPromotedTarget extends ReadonlyPromotedPropertyState {}

test('disabled constructors allow injection into promoted properties', function (
    string $class,
    bool $useDefinition,
): void {
    if (!is_a($class, MutablePromotedPropertyState::class, true)
        && !is_a($class, ReadonlyPromotedPropertyState::class, true)
    ) {
        throw new \LogicException('Expected a promoted property fixture class.');
    }
    $dependency = new PromotedPropertyDependency();
    $events = new PromotedPropertyEvents();
    $container = (new ContainerFactory())->create(
        new Config(['promoted.label' => 'configured'], new Environment([])),
        new DependencyDefinitions([
            'services' => [
                PromotedPropertyDependency::class => $dependency,
                PromotedPropertyEvents::class => $events,
            ],
            'factories' => $useDefinition ? [$class => ClassDefinition::create($class)] : [],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make($class);

    expect($target->label)->toBe('configured')
        ->and($target->dependency)->toBe($dependency)
        ->and($target->events)->toBe($events)
        ->and($events->constructions)->toBe(0);
})->with([
    'mutable' => [SkippedMutablePromotedTarget::class],
    'mutable lazy' => [LazyMutablePromotedTarget::class],
    'mutable proxy' => [ProxyMutablePromotedTarget::class],
    'readonly' => [SkippedReadonlyPromotedTarget::class],
    'readonly lazy' => [LazyReadonlyPromotedTarget::class],
    'readonly proxy' => [ProxyReadonlyPromotedTarget::class],
])->with([
    'reflection' => [false],
    'ClassDefinition' => [true],
]);

test('enabled constructors retain their explicit promoted values', function (bool $readonly): void {
    $class = $readonly ? ReadonlyPromotedPropertyState::class : MutablePromotedPropertyState::class;
    $explicit = new PromotedPropertyDependency();
    $events = new PromotedPropertyEvents();
    $container = (new ContainerFactory())->create(
        new Config(['promoted.label' => 'configured'], new Environment([])),
        new DependencyDefinitions([
            'services' => [
                PromotedPropertyDependency::class => new PromotedPropertyDependency(),
                PromotedPropertyEvents::class => $events,
            ],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make($class, ['label' => 'caller', 'dependency' => $explicit]);

    expect($target->label)->toBe('caller')
        ->and($target->dependency)->toBe($explicit)
        ->and($events->constructions)->toBe(1);
})->with([
    'mutable' => [false],
    'readonly' => [true],
]);
