<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use Reflector;

use function Componenta\DI\Tests\Support\fixtureInstance;

final readonly class SetUpPropertyDependency
{
    public function __construct(public string $label = 'dependency') {}
}

final class SetUpPropertyEvents
{
    /** @var list<string> */
    public array $steps = [];
}

abstract class SetUpPropertyState
{
    #[Inject]
    public SetUpPropertyDependency $dependency;

    #[ConfigAttribute('setup.label')]
    public string $label;

    public string $observed = 'raw';

    public function initialize(SetUpPropertyEvents $events): void
    {
        $this->observed = $this->dependency->label . ':' . $this->label;
        $events->steps[] = 'initialize';
    }

    public function finish(SetUpPropertyEvents $events): void
    {
        $this->observed .= ':finished';
        $events->steps[] = 'finish';
    }
}

#[SetUp('initialize'), SetUp('finish')]
final class EagerSetUpPropertyTarget extends SetUpPropertyState {}

#[Lazy, SetUp('initialize'), SetUp('finish')]
final class LazySetUpPropertyTarget extends SetUpPropertyState {}

#[Proxy, SetUp('initialize'), SetUp('finish')]
final class ProxySetUpPropertyTarget extends SetUpPropertyState {}

#[NoConstructor, SetUp('initialize'), SetUp('finish')]
final class SkippedSetUpPropertyTarget extends SetUpPropertyState {}

#[SetUp('initialize')]
final class FailedSetUpPropertyTarget
{
    #[ConfigAttribute('missing.value')]
    public string $value;

    public function initialize(SetUpPropertyEvents $events): void
    {
        $events->steps[] = 'initialized';
    }
}

test('property injection and setup run only for attribute-driven creation', function (
    string $class,
    bool $deferred,
    bool $useDefinition,
): void {
    if (!is_a($class, SetUpPropertyState::class, true)) {
        throw new \LogicException('Expected a setup property fixture class.');
    }
    $events = new SetUpPropertyEvents();
    $container = (new ContainerFactory())->create(
        new Config(['setup.label' => 'configured'], new Environment([])),
        new DependencyDefinitions([
            'services' => [SetUpPropertyEvents::class => $events],
            'factories' => $useDefinition ? [$class => ClassDefinition::create($class)] : [],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make($class);
    Assert::assertInstanceOf($class, $target);

    expect($events->steps)->toBe($useDefinition || $deferred ? [] : ['initialize', 'finish'])
        ->and($target->observed)->toBe($useDefinition ? 'raw' : 'dependency:configured:finished')
        ->and($events->steps)->toBe($useDefinition ? [] : ['initialize', 'finish']);
})->with([
    'eager' => [EagerSetUpPropertyTarget::class, false],
    'lazy' => [LazySetUpPropertyTarget::class, true],
    'proxy' => [ProxySetUpPropertyTarget::class, true],
    'disabled constructor' => [SkippedSetUpPropertyTarget::class, false],
])->with([
    'reflection' => [false],
    'ClassDefinition' => [true],
]);

test('failed property injection prevents setup side effects', function (): void {
    $events = new SetUpPropertyEvents();
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions(['services' => [SetUpPropertyEvents::class => $events]]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    expect(fn() => $container->make(FailedSetUpPropertyTarget::class))
        ->toThrow(ResolutionException::class)
        ->and($events->steps)->toBe([]);
});

#[Attribute(Attribute::TARGET_CLASS)]
final class SetUpCapabilityPredecessor {}

#[SetUp('initialize'), SetUpCapabilityPredecessor]
final class SetUpCapabilityTarget
{
    /** @var list<string> */
    public array $events = [];

    public function initialize(): void
    {
        $this->events[] = 'setup';
    }
}

test('a lifecycle capability selector orders an extension before the built-in setup hook', function (): void {
    $handler = new class () implements AttributeHandlerInterface {
        public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
        {
            if (!$context->entry instanceof SetUpCapabilityTarget) {
                throw new \LogicException('Expected the setup capability target.');
            }
            $context->entry->events[] = 'predecessor';
        }
    };
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            SetUpCapabilityPredecessor::class,
            $handler,
            before: [LifecycleHook::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    expect(fixtureInstance($container->make(SetUpCapabilityTarget::class), SetUpCapabilityTarget::class)->events)->toBe(['predecessor', 'setup'])
        ->and(fixtureInstance($container->make(SetUpCapabilityTarget::class), SetUpCapabilityTarget::class)->events)->toBe(['predecessor', 'setup']);
});
