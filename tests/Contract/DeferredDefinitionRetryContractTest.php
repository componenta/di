<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class RetryDefinitionDependency
{
    public function __construct(public string $value) {}
}

abstract class RetryDefinitionState
{
    public string $value;

    public function __construct(RetryDefinitionDependency $dependency)
    {
        if ($dependency->value === 'bad') {
            throw new \RuntimeException('Dependency must be replaced.');
        }

        $this->value = $dependency->value;
    }
}

#[Lazy]
final class RetryLazyDefinition extends RetryDefinitionState {}

#[Proxy]
final class RetryProxyDefinition extends RetryDefinitionState {}

test('failed deferred initialization retries constructor references against the current container', function (
    string $class,
    bool $definition,
): void {
    if (!is_a($class, RetryDefinitionState::class, true)) {
        throw new \LogicException('Expected a definition retry fixture class.');
    }

    $builder = (new ContainerBuilder())
        ->addService(RetryDefinitionDependency::class, new RetryDefinitionDependency('bad'));
    if ($definition) {
        $builder->addDefinition($class, ClassDefinition::create($class)->constructor([
            'dependency' => Definition::reference(RetryDefinitionDependency::class),
        ]));
    }
    $container = $builder->build();
    $entry = $container->get($class);

    expect(fn() => $entry->value)->toThrow(ResolutionException::class, 'Dependency must be replaced.');

    $replacement = new RetryDefinitionDependency('good');
    $container->set(RetryDefinitionDependency::class, $replacement);

    expect($container->get(RetryDefinitionDependency::class))->toBe($replacement)
        ->and($entry->value)->toBe('good');
})->with([
    'lazy autowiring' => [RetryLazyDefinition::class, false],
    'lazy ClassDefinition' => [RetryLazyDefinition::class, true],
    'proxy autowiring' => [RetryProxyDefinition::class, false],
    'proxy ClassDefinition' => [RetryProxyDefinition::class, true],
]);



final readonly class RetrySnapshotDependency
{
    public function __construct(public int $generation) {}
}

final class RetrySnapshotContainer implements \Psr\Container\ContainerInterface
{
    public int $calls = 0;

    public function has(string $id): bool
    {
        return $id === RetrySnapshotDependency::class;
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new \LogicException('Unexpected dependency lookup: ' . $id);
        }

        return new RetrySnapshotDependency(++$this->calls);
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ObserveRetryParameters {}

abstract class RetrySnapshotState
{
    public function __construct(public RetrySnapshotDependency $dependency) {}
}

#[Lazy, ObserveRetryParameters]
final class RetrySnapshotLazy extends RetrySnapshotState {}

#[Proxy, ObserveRetryParameters]
final class RetrySnapshotProxy extends RetrySnapshotState {}

final class RetrySnapshotObserver implements \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface
{
    /** @var list<int> */
    public array $before = [];

    /** @var list<array{int,int}> */
    public array $after = [];

    public readonly \RuntimeException $failure;

    public function __construct()
    {
        $this->failure = new \RuntimeException('Initialization failed after the constructor.');
    }

    public function handle(
        object $attribute,
        \Reflector $target,
        \Componenta\DI\Resolver\Entry\ObjectCreationContext $context,
    ): void {
        $parameter = $context->parameters['dependency'] ?? null;
        if (!$parameter instanceof RetrySnapshotDependency) {
            throw new \LogicException('Expected a resolved constructor dependency in the public context.');
        }

        if ($context->entry === null) {
            $this->before[] = $parameter->generation;
            return;
        }
        if (!$context->entry instanceof RetrySnapshotState) {
            throw new \LogicException('Expected a retry snapshot fixture.');
        }

        $this->after[] = [$parameter->generation, $context->entry->dependency->generation];
        if (count($this->after) === 1) {
            throw $this->failure;
        }
    }
}

test('each deferred initialization attempt shares one parameter snapshot with its object handlers', function (string $class): void {
    if (!is_a($class, RetrySnapshotState::class, true)) {
        throw new \LogicException('Expected a retry snapshot fixture class.');
    }

    $external = new RetrySnapshotContainer();
    $observer = new RetrySnapshotObserver();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new \Componenta\DI\Attribute\Composition\AttributeDefinition(
            ObserveRetryParameters::class,
            $observer,
            phase: \Componenta\DI\Resolver\Attribute\AttributePhase::Both,
        ))
        ->addDefinition($class, ClassDefinition::create($class)->constructor([
            'dependency' => Definition::reference(RetrySnapshotDependency::class),
        ]))
        ->build();
    $container->addContainer($external);

    $entry = $container->make($class);

    expect($observer->before)->toBe([1])
        ->and($external->calls)->toBe(1);

    try {
        throw new \LogicException('Expected an initialization failure, got: ' . $entry->dependency->generation);
    } catch (ResolutionException $exception) {
        expect($exception->getPrevious())->toBe($observer->failure)
            ->and($observer->after)->toBe([[1, 1]]);
    }

    expect($entry->dependency->generation)->toBe(2)
        ->and($observer->before)->toBe([1])
        ->and($observer->after)->toBe([[1, 1], [2, 2]])
        ->and($external->calls)->toBe(2)
        ->and($entry->dependency->generation)->toBe(2)
        ->and($external->calls)->toBe(2);
})->with([
    'lazy' => [RetrySnapshotLazy::class],
    'proxy' => [RetrySnapshotProxy::class],
]);
