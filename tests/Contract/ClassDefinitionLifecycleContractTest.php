<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class DefinitionLifecycleEvents
{
    /** @var list<string> */
    public array $steps = [];
    public bool $fail = true;
    public readonly \RuntimeException $failure;

    public function __construct()
    {
        $this->failure = new \RuntimeException('Definition setup failed.');
    }
}

abstract class DefinitionLifecycleState
{
    public string $state;

    public function __construct(DefinitionLifecycleEvents $events)
    {
        $events->steps[] = 'constructor';
        $this->state = 'constructed';
    }

    public function initialize(DefinitionLifecycleEvents $events): void
    {
        $events->steps[] = 'attribute';
        $this->state = 'initialized';
    }

    public function rejectOnce(DefinitionLifecycleEvents $events): void
    {
        $events->steps[] = 'configure';
        if ($events->fail) {
            $events->fail = false;
            throw $events->failure;
        }
    }

    public function configure(DefinitionLifecycleEvents $events, string $step): void
    {
        $events->steps[] = $step;
    }
}

#[SetUp('initialize')]
final class EagerDefinitionLifecycle extends DefinitionLifecycleState {}

#[Lazy, SetUp('initialize')]
final class LazyDefinitionLifecycle extends DefinitionLifecycleState {}

#[Proxy, SetUp('initialize')]
final class ProxyDefinitionLifecycle extends DefinitionLifecycleState {}

test('ClassDefinition calls run eagerly after the constructor without attribute hooks', function (
    string $class,
): void {
    if (!is_a($class, DefinitionLifecycleState::class, true)) {
        throw new \LogicException('Expected a definition lifecycle fixture class.');
    }

    $events = new DefinitionLifecycleEvents();
    $definition = ClassDefinition::create($class)->constructor(['events' => Definition::reference(DefinitionLifecycleEvents::class)])
        ->call('configure', ['events' => Definition::reference('method.events'), 'step' => 'first'])
        ->call('configure', ['events' => Definition::reference('method.events'), 'step' => 'second']);
    $container = (new ContainerBuilder())
        ->addService(DefinitionLifecycleEvents::class, $events)
        ->addFactory('method.events', static function () use ($events): DefinitionLifecycleEvents {
            $events->steps[] = 'method dependency';
            return $events;
        })
        ->addDefinition($class, $definition)
        ->build();

    $target = $container->make($class);

    expect($events->steps)->toBe(['constructor', 'method dependency', 'first', 'second'])
        ->and($target->state)->toBe('constructed')
        ->and($events->steps)->toBe(['constructor', 'method dependency', 'first', 'second'])
        ->and($target->state)->toBe('constructed')
        ->and($events->steps)->toBe(['constructor', 'method dependency', 'first', 'second']);
})->with([
    'eager' => [EagerDefinitionLifecycle::class],
    'lazy' => [LazyDefinitionLifecycle::class],
    'proxy' => [ProxyDefinitionLifecycle::class],
]);

test('failed ClassDefinition calls stop creation and a later get creates a fresh result', function (string $class): void {
    if (!is_a($class, DefinitionLifecycleState::class, true)) {
        throw new \LogicException('Expected a definition lifecycle fixture class.');
    }

    $events = new DefinitionLifecycleEvents();
    $container = (new ContainerBuilder())
        ->addService(DefinitionLifecycleEvents::class, $events)
        ->addDefinition($class, ClassDefinition::create($class)->autowire()
            ->call('rejectOnce')
            ->call('configure', ['step' => 'finished']))
        ->build();
    expect($events->steps)->toBe([]);

    try {
        $container->get($class);
        throw new \LogicException('Expected the configured call to fail.');
    } catch (\Componenta\DI\Exception\ResolutionException $exception) {
        expect($exception->getPrevious())->toBe($events->failure)
            ->and($events->steps)->toBe(['constructor', 'configure']);
    }

    $target = $container->get($class);
    expect($target->state)->toBe('constructed')
        ->and($events->steps)->toBe(['constructor', 'configure', 'constructor', 'configure', 'finished']);
})->with([
    'lazy' => [LazyDefinitionLifecycle::class],
    'proxy' => [ProxyDefinitionLifecycle::class],
]);
