<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class DefinitionContractDependency
{
    public function __construct(public string $name) {}
}

final class DefinitionContractTarget
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        public readonly DefinitionContractDependency $dependency,
        public readonly string $label,
    ) {}

    public function append(DefinitionContractDependency $dependency, string $suffix): void
    {
        $this->events[] = $dependency->name . $suffix;
    }
}

test('ClassDefinition resolves explicit entry references for constructors and ordered methods', function (): void {
    $dependency = new DefinitionContractDependency('special');
    $definition = ClassDefinition::create(DefinitionContractTarget::class)
        ->constructor([
            'dependency' => Definition::reference('definition.dependency'),
            'label' => 'configured',
        ])
        ->method('append', [
            'dependency' => Definition::reference('definition.dependency'),
            'suffix' => ':first',
        ])
        ->method('append', [
            'dependency' => Definition::reference('definition.dependency'),
            'suffix' => ':second',
        ]);
    $container = (new ContainerBuilder())
        ->addService('definition.dependency', $dependency)
        ->addDefinition('definition.target', $definition)
        ->build();

    $target = $container->make('definition.target');
    if (!$target instanceof DefinitionContractTarget) {
        throw new \LogicException('ClassDefinition returned an unexpected target.');
    }

    expect($target->dependency)->toBe($dependency)
        ->and($target->label)->toBe('configured')
        ->and($target->events)->toBe(['special:first', 'special:second']);
});

test('Definition factories reject empty reference ids and method names before registration', function (): void {
    $definition = ClassDefinition::create(DefinitionContractTarget::class);
    $method = new \ReflectionMethod($definition, 'method');

    expect(fn() => Definition::reference(''))
        ->toThrow(InvalidConfigurationException::class, 'entry id must be non-empty')
        ->and(fn() => $method->invoke($definition, ''))
        ->toThrow(InvalidConfigurationException::class, 'method name must be a non-empty string');
});
