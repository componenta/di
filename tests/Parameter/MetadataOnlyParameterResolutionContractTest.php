<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\MetadataOnlyParameterResolutionContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class TrimInput {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class TrimmingResolver implements ParameterResolverInterface
{
    public int $calls = 0;

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(TrimInput::class);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        $value = $context->provided[$target->name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        ++$this->calls;
        return [$target->position, trim($value)];
    }
}

function consume(#[TrimInput] string $value): string
{
    return $value;
}

test('metadata-only parameter attributes leave supplied input to the next supporting resolver', function (bool $uncached): void {
    $resolver = new TrimmingResolver();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(TrimInput::class, rules: $uncached ? [new NoOpRule()] : []))
        ->addParameterResolver($resolver, 1150)
        ->build();

    expect($container->call(__NAMESPACE__ . '\\consume', ['value' => '  first  ']))->toBe('first')
        ->and($container->call(__NAMESPACE__ . '\\consume', ['value' => '  second  ']))->toBe('second')
        ->and($resolver->calls)->toBe(2);
})->with(['cached plans' => false, 'non-memoized plans' => true]);
