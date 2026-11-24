<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Definition\DefinitionContract;

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
        ->call('append', [
            'dependency' => Definition::reference('definition.dependency'),
            'suffix' => ':first',
        ])
        ->call('append', [
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
    $invalidMethod = static function () use ($definition): ClassDefinition {
        // @phpstan-ignore argument.type (Deliberately invalid public method name.)
        return $definition->call('');
    };

    expect(fn() => Definition::reference(''))
        ->toThrow(InvalidConfigurationException::class, 'entry id must be non-empty')
        ->and($invalidMethod)
        ->toThrow(InvalidConfigurationException::class, 'method name must be a non-empty string');
});

test('fluent class definitions can branch without changing previously configured definitions', function (): void {
    $dependency = new DefinitionContractDependency('dependency');
    $base = ClassDefinition::create(DefinitionContractTarget::class)->constructor(['label' => 'base']);
    $autowired = $base->autowire();
    $first = $autowired->call('append', ['suffix' => ':first']);
    $second = $first->call('append', ['suffix' => ':second']);
    $renamed = $second->constructor(['label' => 'renamed']);

    expect($base->autowire)->toBeFalse()
        ->and($base->constructorParams)->toBe(['label' => 'base'])
        ->and($base->methodCalls)->toBe([])
        ->and($autowired->methodCalls)->toBe([])
        ->and($first->methodCalls)->toBe([['method' => 'append', 'params' => ['suffix' => ':first']]])
        ->and($second->constructorParams)->toBe(['label' => 'base'])
        ->and($renamed->autowire)->toBeTrue()
        ->and($renamed->methodCalls)->toBe([
            ['method' => 'append', 'params' => ['suffix' => ':first']],
            ['method' => 'append', 'params' => ['suffix' => ':second']],
        ]);

    $container = (new ContainerBuilder())->addService(DefinitionContractDependency::class, $dependency)
        ->addDefinition('autowired', $autowired)
        ->addDefinition('first', $first)
        ->addDefinition('second', $second)
        ->addDefinition('renamed', $renamed)
        ->build();

    foreach ([
        ['autowired', 'base', []],
        ['first', 'base', ['dependency:first']],
        ['second', 'base', ['dependency:first', 'dependency:second']],
        ['renamed', 'renamed', ['dependency:first', 'dependency:second']],
    ] as [$id, $label, $events]) {
        $target = $container->make($id);
        if (!$target instanceof DefinitionContractTarget) {
            throw new \LogicException('Expected the independently configured definition target.');
        }
        expect($target->dependency)->toBe($dependency)
            ->and($target->label)->toBe($label)
            ->and($target->events)->toBe($events);
    }
});
