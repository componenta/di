<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_ALL)]
final class PlanAuditMarker {}

#[Attribute(Attribute::TARGET_ALL)]
final class PlanAuditCompanion {}

#[PlanAuditMarker]
final class PlanAuditTarget
{
    #[PlanAuditMarker]
    public string $value;

    #[PlanAuditMarker]
    public function run(#[PlanAuditMarker] string $value): void {}
}

function planAuditFunction(#[PlanAuditMarker] string $value): void {}

test('stable targets reuse the same semantic attribute plan', function (
    ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $target,
): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PlanAuditMarker::class));
    $builder = new AttributePlanBuilder($registry);
    $first = $builder->build($target);
    expect($first->attributes(PlanAuditMarker::class))->toHaveCount(1)
        ->and($builder->build($target))->toBe($first);
})->with([
    'class' => [new ReflectionClass(PlanAuditTarget::class)],
    'method' => [new ReflectionMethod(PlanAuditTarget::class, 'run')],
    'property' => [new ReflectionProperty(PlanAuditTarget::class, 'value')],
    'method parameter' => [new ReflectionParameter([PlanAuditTarget::class, 'run'], 0)],
    'function parameter' => [new ReflectionParameter(__NAMESPACE__ . '\\planAuditFunction', 0)],
]);

test('missing companion diagnostics identify the complete attribute target', function (
    ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $target,
    string $name,
): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PlanAuditMarker::class, requires: [PlanAuditCompanion::class]));
    $builder = new AttributePlanBuilder($registry);

    try {
        $builder->build($target);
        \PHPUnit\Framework\Assert::fail('A missing companion must be rejected.');
    } catch (AttributeCompositionException $exception) {
        expect($exception->getMessage())->toBe(
            $name . ' requires ' . PlanAuditCompanion::class . ' because of #[' . PlanAuditMarker::class . '].',
        );
    }
})->with([
    'class' => [new ReflectionClass(PlanAuditTarget::class), PlanAuditTarget::class],
    'property' => [new ReflectionProperty(PlanAuditTarget::class, 'value'), PlanAuditTarget::class . '::$value'],
    'method' => [new ReflectionMethod(PlanAuditTarget::class, 'run'), PlanAuditTarget::class . '::run()'],
    'method parameter' => [new ReflectionParameter([PlanAuditTarget::class, 'run'], 0), '$value of ' . PlanAuditTarget::class . '::run()'],
    'function parameter' => [new ReflectionParameter(__NAMESPACE__ . '\\planAuditFunction', 0), '$value of ' . __NAMESPACE__ . '\\planAuditFunction()'],
]);
