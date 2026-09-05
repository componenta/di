<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

final readonly class OverrideDependency {}

final class ReferenceOverrideTarget
{
    public ?OverrideDependency $methodDependency = null;

    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly OverrideDependency $dependency,
        public readonly array $options = [],
    ) {}

    public function configure(OverrideDependency $dependency): void
    {
        $this->methodDependency = $dependency;
    }
}

/** @param array<string, mixed> $factories */
function referenceOverrideContainer(array $factories): Container
{
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([ConfigKey::FACTORIES => $factories]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    return $container;
}

test('runtime constructor overrides do not instantiate replaced references', function (
    string|int $configuredKey,
    string|int $runtimeKey,
): void {
    $calls = 0;
    $container = referenceOverrideContainer([
        'configured.dependency' => static function () use (&$calls): OverrideDependency {
            ++$calls;

            return new OverrideDependency();
        },
        ReferenceOverrideTarget::class => ClassDefinition::create(ReferenceOverrideTarget::class)
            ->constructor([$configuredKey => Definition::reference('configured.dependency')]),
    ]);
    $replacement = new OverrideDependency();

    $target = $container->make(ReferenceOverrideTarget::class, [$runtimeKey => $replacement]);

    expect($target->dependency)->toBe($replacement)
        ->and($calls)->toBe(0);
})->with([
    'name replaces name' => ['dependency', 'dependency'],
    'position replaces name' => ['dependency', 0],
    'type replaces name' => ['dependency', OverrideDependency::class],
    'name replaces position' => [0, 'dependency'],
    'position replaces position' => [0, 0],
    'type replaces position' => [0, OverrideDependency::class],
]);

test('runtime name wins over position and type without resolving an unavailable default', function (): void {
    $container = referenceOverrideContainer([
        ReferenceOverrideTarget::class => ClassDefinition::create(ReferenceOverrideTarget::class)
            ->constructor([
                'dependency' => Definition::reference('missing.named'),
                0 => Definition::reference('missing.positional'),
            ]),
    ]);
    $replacement = new OverrideDependency();

    $target = $container->make(ReferenceOverrideTarget::class, [
        'dependency' => $replacement,
        0 => new OverrideDependency(),
        OverrideDependency::class => new OverrideDependency(),
    ]);

    expect($target->dependency)->toBe($replacement);
});

test('replacing a configured array skips nested references and keeps runtime values literal', function (): void {
    $container = referenceOverrideContainer([
        ReferenceOverrideTarget::class => ClassDefinition::create(ReferenceOverrideTarget::class)
            ->constructor([
                'options' => ['nested' => [Definition::reference('missing.nested')]],
            ]),
    ]);
    $literal = Definition::reference('runtime.literal');
    $options = ['nested' => [$literal]];

    $target = $container->make(ReferenceOverrideTarget::class, ['options' => $options]);

    expect($target->options)->toBe($options);
});

test('constructor overrides preserve references used by other parameters and method calls', function (): void {
    $configured = new OverrideDependency();
    $replacement = new OverrideDependency();
    $container = referenceOverrideContainer([
        'configured.dependency' => static fn(): OverrideDependency => $configured,
        ReferenceOverrideTarget::class => ClassDefinition::create(ReferenceOverrideTarget::class)
            ->constructor([
                'dependency' => Definition::reference('configured.dependency'),
                'options' => ['nested' => [Definition::reference('configured.dependency')]],
            ])
            ->method('configure', [Definition::reference('configured.dependency')]),
    ]);

    $target = $container->make(ReferenceOverrideTarget::class, ['dependency' => $replacement]);

    expect($target->dependency)->toBe($replacement)
        ->and($target->options)->toBe(['nested' => [$configured]])
        ->and($target->methodDependency)->toBe($configured);
});
