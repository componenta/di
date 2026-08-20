<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorInterface;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorRegistry;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;

interface AuditDefinitionFamilyOne extends DefinitionInterface {}
interface AuditDefinitionFamilyTwo extends DefinitionInterface {}
interface AuditSpecificDefinitionFamily extends AuditDefinitionFamilyOne {}

final readonly class AuditAmbiguousDefinition implements AuditDefinitionFamilyOne, AuditDefinitionFamilyTwo
{
    public function __construct(public mixed $value = null) {}
}

final readonly class AuditSpecificDefinition implements AuditSpecificDefinitionFamily
{
    public function __construct(public mixed $value = null) {}
}

final readonly class AuditDefinitionGenerator implements DefinitionCodeGeneratorInterface
{
    public function __construct(public string $label) {}

    public function generate(string $id, DefinitionInterface $definition): GeneratedDefinitionCode
    {
        return new GeneratedDefinitionCode('null');
    }
}

test('definition code generators reject equally specific inherited matches', function (): void {
    $registry = new DefinitionCodeGeneratorRegistry();
    $registry->register(AuditDefinitionFamilyOne::class, new AuditDefinitionGenerator('one'));
    $registry->register(AuditDefinitionFamilyTwo::class, new AuditDefinitionGenerator('two'));

    expect(fn() => $registry->find(new AuditAmbiguousDefinition()))
        ->toThrow(InvalidConfigurationException::class, 'multiple equally specific');
});

test('definition code generators prefer the most specific inherited match', function (): void {
    $registry = new DefinitionCodeGeneratorRegistry();
    $broad = new AuditDefinitionGenerator('broad');
    $specific = new AuditDefinitionGenerator('specific');
    $registry->register(AuditDefinitionFamilyOne::class, $broad);
    $registry->register(AuditSpecificDefinitionFamily::class, $specific);

    expect($registry->find(new AuditSpecificDefinition()))->toBe($specific);
});

test('an exact definition code generator registration wins over inherited matches', function (): void {
    $registry = new DefinitionCodeGeneratorRegistry();
    $broad = new AuditDefinitionGenerator('broad');
    $exact = new AuditDefinitionGenerator('exact');
    $registry->register(AuditDefinitionFamilyOne::class, $broad);
    $registry->register(AuditSpecificDefinition::class, $exact);

    expect($registry->find(new AuditSpecificDefinition()))->toBe($exact);
});
