<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Config;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ResolutionException;

final class AuditReadonlyTransformTarget
{
    #[Config('audit.value')]
    #[Cast('int')]
    public readonly int $value;
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AuditRuleAttribute {}

final readonly class AuditForeignCompositionRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        throw new ResolutionException('foreign runtime-classified failure');
    }
}

test('readonly properties reject source plus transformer composition before execution', function (): void {
    expect(fn() => (new ContainerBuilder())->build()->make(AuditReadonlyTransformTarget::class))
        ->toThrow(AttributeCompositionException::class, 'readonly properties can be written only once');
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

    test()->fail('Expected AttributeCompositionException.');
});
