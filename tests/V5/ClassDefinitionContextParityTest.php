<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

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

final class ClassDefinitionPrivateConstructorTarget
{
    private function __construct() {}
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
        'dependency' => $runtimeDependency,
        'unrelated' => 'ignored',
    ]);
    if (!$entry instanceof ClassDefinitionParityTarget) {
        throw new \LogicException('The class definition resolved to an unexpected type.');
    }

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
    if (!$entry instanceof ClassDefinitionParityTarget) {
        throw new \LogicException('The class definition resolved to an unexpected type.');
    }

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

test('ClassDefinition rejects private constructors even with NoConstructor', function (): void {
    expect(fn() => (new ContainerBuilder())
        ->addDefinition('class.definition.constructor-policy', ClassDefinition::create(ClassDefinitionConstructorPolicyTarget::class))
        ->build())->toThrow(InvalidConfigurationException::class, 'runtime-ineligible');
});

test('ClassDefinition rejects private constructors during registration', function (): void {
    expect(fn() => (new ContainerBuilder())
        ->addDefinition('class.definition.private-constructor', ClassDefinition::create(ClassDefinitionPrivateConstructorTarget::class))
        ->build())->toThrow(InvalidConfigurationException::class, 'runtime-ineligible');
});

test('ClassDefinition accepts named union overrides instead of type keys', function (): void {
    $configured = new ClassDefinitionUnionLeftValue();
    $runtime = new ClassDefinitionUnionRightValue();
    $container = (new ContainerBuilder())->build();

    $container->set(
        'class.definition.union',
        ClassDefinition::create(ClassDefinitionUnionTarget::class)
            ->constructor(['dependency' => $configured]),
    );

    $left = $container->make('class.definition.union', [
        ClassDefinitionUnionLeft::class => $runtime,
    ]);
    $right = $container->make('class.definition.union', [
            ClassDefinitionUnionRight::class => $runtime,
        ]);
    if (!$left instanceof ClassDefinitionUnionTarget || !$right instanceof ClassDefinitionUnionTarget) {
        throw new \LogicException('The union class definition resolved to an unexpected type.');
    }

    expect($left->dependency)->toBe($configured)
        ->and($right->dependency)->toBe($configured);

    $named = $container->make('class.definition.union', ['dependency' => $runtime]);
    if (!$named instanceof ClassDefinitionUnionTarget) {
        throw new \LogicException('Expected the configured union target.');
    }
    expect($named->dependency)->toBe($runtime);
});
