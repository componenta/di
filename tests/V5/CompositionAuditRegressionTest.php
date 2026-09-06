<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AuditRuleAttribute {}

final readonly class AuditForeignCompositionRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        throw new ResolutionException('foreign runtime-classified failure');
    }
}

test('readonly properties receive the final transformed source value', function (): void {
    $target = __NAMESPACE__ . '\\AuditReadonlyTransformTarget';
    if (!class_exists($target, false)) {
        // The fixture deliberately initializes readonly state outside its constructor.
        eval('namespace ' . __NAMESPACE__ . '; final class AuditReadonlyTransformTarget { #[\\Componenta\\DI\\Attribute\\Config("audit.value"), \\Componenta\\DI\\Attribute\\Cast("int")] public readonly int $value; }');
    }
    if (!class_exists($target)) {
        throw new \LogicException('Expected the readonly transformation target.');
    }
    $container = ContainerBuilder::configure(new \Componenta\Config\Config(
        ['audit.value' => '42'],
        new \Componenta\Config\Environment([]),
    ))
        ->addService(\Componenta\Caster\CasterProviderInterface::class, new \Componenta\DI\Tests\Support\TestCasterProvider())
        ->build();

    $entry = $container->make($target);
    if (!property_exists($entry, 'value')) {
        throw new \LogicException('Expected the public readonly value.');
    }

    expect($entry->value)->toBe(42);
});

test('composition rules always expose AttributeCompositionException at the composition boundary', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AuditRuleAttribute::class,
            rules: [new AuditForeignCompositionRule()],
        ))
        ->build();

    try {
        $container->call(
            static fn(#[AuditRuleAttribute] string $value): string => $value,
            ['value' => 'ok'],
        );
    } catch (AttributeCompositionException $error) {
        expect($error->getPrevious())->toBeInstanceOf(ResolutionException::class);
        return;
    }

    throw new \LogicException('Expected AttributeCompositionException.');
});
