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

test('ClassDefinition methods and their dependencies join object initialization after attributes', function (
    string $class,
    bool $deferred,
): void {
    if (!is_a($class, DefinitionLifecycleState::class, true)) {
        throw new \LogicException('Expected a definition lifecycle fixture class.');
    }

    $events = new DefinitionLifecycleEvents();
    $definition = ClassDefinition::create($class)
        ->method('configure', ['events' => Definition::reference('method.events'), 'step' => 'first'])
        ->method('configure', ['events' => Definition::reference('method.events'), 'step' => 'second']);
    $container = (new ContainerBuilder())
        ->addService(DefinitionLifecycleEvents::class, $events)
        ->addFactory('method.events', static function () use ($events): DefinitionLifecycleEvents {
            $events->steps[] = 'method dependency';
            return $events;
        })
        ->addDefinition($class, $definition)
        ->build();

    $target = $container->make($class);

    expect($events->steps)->toBe($deferred ? [] : ['constructor', 'attribute', 'method dependency', 'first', 'second'])
        ->and($target->state)->toBe('initialized')
        ->and($events->steps)->toBe(['constructor', 'attribute', 'method dependency', 'first', 'second'])
        ->and($target->state)->toBe('initialized')
        ->and($events->steps)->toBe(['constructor', 'attribute', 'method dependency', 'first', 'second']);
})->with([
    'eager' => [EagerDefinitionLifecycle::class, false],
    'lazy' => [LazyDefinitionLifecycle::class, true],
    'proxy' => [ProxyDefinitionLifecycle::class, true],
]);

test('failed deferred ClassDefinition methods stop the attempt and can initialize on retry', function (string $class): void {
    if (!is_a($class, DefinitionLifecycleState::class, true)) {
        throw new \LogicException('Expected a definition lifecycle fixture class.');
    }

    $events = new DefinitionLifecycleEvents();
    $container = (new ContainerBuilder())
        ->addService(DefinitionLifecycleEvents::class, $events)
        ->addDefinition($class, ClassDefinition::create($class)
            ->method('rejectOnce')
            ->method('configure', ['step' => 'finished']))
        ->build();
    $target = $container->make($class);

    expect($events->steps)->toBe([]);

    try {
        throw new \LogicException('Expected deferred initialization to fail, got: ' . $target->state);
    } catch (\Componenta\DI\Exception\ResolutionException $exception) {
        expect($exception->getPrevious())->toBe($events->failure)
            ->and($events->steps)->toBe(['constructor', 'attribute', 'configure']);
    }

    expect($target->state)->toBe('initialized')
        ->and($events->steps)->toBe(['constructor', 'attribute', 'configure', 'constructor', 'attribute', 'configure', 'finished']);
})->with([
    'lazy' => [LazyDefinitionLifecycle::class],
    'proxy' => [ProxyDefinitionLifecycle::class],
]);
