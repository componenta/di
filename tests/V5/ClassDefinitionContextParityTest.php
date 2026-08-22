<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;

final class ClassDefinitionParityDependency {}

final class ClassDefinitionParityTarget
{
    public function __construct(
        public string $first = 'first-default',
        public string $second = 'second-default',
        public ?ClassDefinitionParityDependency $dependency = null,
    ) {}
}

final class ClassDefinitionParityNoConstructorTarget {}

#[NoConstructor]
final class ClassDefinitionConstructorPolicyTarget
{
    public bool $constructorRan = false;

    private function __construct()
    {
        $this->constructorRan = true;
    }
}

interface ClassDefinitionUnionLeft {}
interface ClassDefinitionUnionRight {}

final readonly class ClassDefinitionUnionLeftValue implements ClassDefinitionUnionLeft {}
final readonly class ClassDefinitionUnionRightValue implements ClassDefinitionUnionRight {}

final readonly class ClassDefinitionUnionTarget
{
    public function __construct(
        public ClassDefinitionUnionLeft|ClassDefinitionUnionRight $dependency,
    ) {}
}

test('ClassDefinition runtime overrides normalize by constructor signature', function (): void {
    $configuredDependency = new ClassDefinitionParityDependency();
    $runtimeDependency = new ClassDefinitionParityDependency();
    $container = (new ContainerBuilder())->build();

    $container->set(
        'class.definition.context',
        ClassDefinition::create(ClassDefinitionParityTarget::class)
            ->constructor([
                'first' => 'configured-first',
                'second' => 'configured-second',
                'dependency' => $configuredDependency,
            ]),
    );

    $entry = $container->make('class.definition.context', [
        1 => 'runtime-second',
        ClassDefinitionParityDependency::class => $runtimeDependency,
        'unrelated' => 'ignored',
    ]);

    expect($entry->first)->toBe('configured-first')
        ->and($entry->second)->toBe('runtime-second')
        ->and($entry->dependency)->toBe($runtimeDependency);
});

test('ClassDefinition positional runtime values override named configured values', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(
        'class.definition.position',
        ClassDefinition::create(ClassDefinitionParityTarget::class)
            ->constructor([
                'first' => 'configured-first',
                'second' => 'configured-second',
            ]),
    );

    $entry = $container->make('class.definition.position', [0 => 'runtime-first']);

    expect($entry->first)->toBe('runtime-first')
        ->and($entry->second)->toBe('configured-second');
});

test('ClassDefinition target without constructor ignores unrelated make context', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(
        'class.definition.no-constructor',
        ClassDefinition::create(ClassDefinitionParityNoConstructorTarget::class),
    );

    expect($container->make(
        'class.definition.no-constructor',
        ['unrelated' => 'ignored'],
    ))->toBeInstanceOf(ClassDefinitionParityNoConstructorTarget::class);
});

test('ClassDefinition honors constructor policies from the shared object pipeline', function (): void {
    $container = (new ContainerBuilder())
        ->addDefinition(
            'class.definition.constructor-policy',
            ClassDefinition::create(ClassDefinitionConstructorPolicyTarget::class),
        )
        ->build();

    $entry = $container->make('class.definition.constructor-policy');

    expect($entry)->toBeInstanceOf(ClassDefinitionConstructorPolicyTarget::class)
        ->and($entry->constructorRan)->toBeFalse();
});

test('ClassDefinition typed union overrides match the concrete type key exactly', function (): void {
    $configured = new ClassDefinitionUnionLeftValue();
    $runtime = new ClassDefinitionUnionRightValue();
    $container = (new ContainerBuilder())->build();

    $container->set(
        'class.definition.union',
        ClassDefinition::create(ClassDefinitionUnionTarget::class)
            ->constructor(['dependency' => $configured]),
    );

    expect($container->make('class.definition.union', [
        ClassDefinitionUnionLeft::class => $runtime,
    ])->dependency)->toBe($configured)
        ->and($container->make('class.definition.union', [
            ClassDefinitionUnionRight::class => $runtime,
        ])->dependency)->toBe($runtime);
});
