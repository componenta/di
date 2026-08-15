<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;

final class ClassDefinitionContextDependency {}

final class ClassDefinitionContextTarget
{
    public function __construct(
        public string $first = 'first-default',
        public string $second = 'second-default',
        public ?ClassDefinitionContextDependency $dependency = null,
    ) {}
}

final class ClassDefinitionNoConstructorTarget {}

it('normalizes ClassDefinition configured and runtime constructor arguments by signature', function (): void {
    $configuredDependency = new ClassDefinitionContextDependency();
    $runtimeDependency = new ClassDefinitionContextDependency();
    $container = (new ContainerBuilder())->build();

    $container->set(
        'class.definition.context',
        ClassDefinition::create(ClassDefinitionContextTarget::class)
            ->constructor([
                'first' => 'configured-first',
                'second' => 'configured-second',
                'dependency' => $configuredDependency,
            ]),
    );

    $entry = $container->make('class.definition.context', [
        1 => 'runtime-second',
        ClassDefinitionContextDependency::class => $runtimeDependency,
        'unrelated' => 'ignored',
    ]);

    expect($entry->first)->toBe('configured-first')
        ->and($entry->second)->toBe('runtime-second')
        ->and($entry->dependency)->toBe($runtimeDependency);
});

it('lets positional runtime arguments override named configured ClassDefinition arguments', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(
        'class.definition.position',
        ClassDefinition::create(ClassDefinitionContextTarget::class)
            ->constructor([
                'first' => 'configured-first',
                'second' => 'configured-second',
            ]),
    );

    $entry = $container->make('class.definition.position', [0 => 'runtime-first']);

    expect($entry->first)->toBe('runtime-first')
        ->and($entry->second)->toBe('configured-second');
});

it('ignores unrelated make context for a ClassDefinition target without a constructor', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(
        'class.definition.no-constructor',
        ClassDefinition::create(ClassDefinitionNoConstructorTarget::class),
    );

    expect($container->make(
        'class.definition.no-constructor',
        ['unrelated' => 'ignored'],
    ))->toBeInstanceOf(ClassDefinitionNoConstructorTarget::class);
});
