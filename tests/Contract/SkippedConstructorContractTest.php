<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;

final class SkippedConstructorDependency {}

final class SkippedConstructorEvents
{
    public int $initializations = 0;
}

abstract class SkippedConstructorState
{
    public string $state = 'raw';

    #[Inject]
    public SkippedConstructorDependency $dependency;

    public function initialize(SkippedConstructorEvents $events): void
    {
        ++$events->initializations;
        $this->state = 'ready';
    }
}

#[NoConstructor, SetUp('initialize')]
class SkippedVariadicConstructor extends SkippedConstructorState
{
    private function __construct(string ...$values)
    {
        throw new \LogicException(sprintf('Disabled constructor received %d arguments.', count($values)));
    }
}

#[NoConstructor, SetUp('initialize')]
class SkippedReferenceConstructor extends SkippedConstructorState
{
    private function __construct(string &$value)
    {
        throw new \LogicException('Disabled constructor received: ' . $value);
    }
}

#[NoConstructor, Lazy, SetUp('initialize')]
final class LazySkippedVariadicConstructor extends SkippedVariadicConstructor {}

#[NoConstructor, Lazy, SetUp('initialize')]
final class LazySkippedReferenceConstructor extends SkippedReferenceConstructor {}

#[NoConstructor, Proxy, SetUp('initialize')]
final class ProxySkippedVariadicConstructor extends SkippedVariadicConstructor {}

#[NoConstructor, Proxy, SetUp('initialize')]
final class ProxySkippedReferenceConstructor extends SkippedReferenceConstructor {}

test('disabled constructor signatures do not prevent property injection and setup', function (
    string $class,
    bool $deferred,
    bool $useDefinition,
): void {
    if (!is_a($class, SkippedConstructorState::class, true)) {
        throw new \LogicException('Expected a skipped constructor fixture class.');
    }
    $dependency = new SkippedConstructorDependency();
    $events = new SkippedConstructorEvents();
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            'services' => [
                SkippedConstructorDependency::class => $dependency,
                SkippedConstructorEvents::class => $events,
            ],
            'factories' => $useDefinition
                ? [$class => ClassDefinition::create($class)->constructor(['context' => 'preserved'])]
                : [],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make($class);

    expect($events->initializations)->toBe($deferred ? 0 : 1)
        ->and($target->state)->toBe('ready')
        ->and($target->dependency)->toBe($dependency)
        ->and($target->state)->toBe('ready')
        ->and($events->initializations)->toBe(1);
})->with([
    'eager variadic' => [SkippedVariadicConstructor::class, false],
    'eager reference' => [SkippedReferenceConstructor::class, false],
    'lazy variadic' => [LazySkippedVariadicConstructor::class, true],
    'lazy reference' => [LazySkippedReferenceConstructor::class, true],
    'proxy variadic' => [ProxySkippedVariadicConstructor::class, true],
    'proxy reference' => [ProxySkippedReferenceConstructor::class, true],
])->with([
    'reflection' => [false],
    'ClassDefinition' => [true],
]);
