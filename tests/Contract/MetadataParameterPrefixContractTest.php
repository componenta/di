<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\MetadataParameterPrefix;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Metadata
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RevealSuffix
{
    public function __construct(public string $alias) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Suffix {}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Transformer implements ParameterAttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$value->resolved || !is_string($value->value)) {
            throw new LogicException('Expected the supplied string value.');
        }
        if ($attribute instanceof RevealSuffix) {
            $this->events[] = 'first';
            if (!class_exists($attribute->alias, false)) {
                class_alias(Suffix::class, $attribute->alias);
            }
            return ParameterAttributeValue::resolved($value->value . ':first');
        }
        if (!$attribute instanceof Suffix) {
            throw new LogicException('Expected a parameter transformer.');
        }
        $this->events[] = 'suffix';
        return ParameterAttributeValue::resolved($value->value . ':suffix');
    }
}

test('metadata before an executed parameter handler does not become a new predecessor during discovery', function (bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'LateSuffix_' . $suffix;
    $function = 'target_' . $suffix;
    $alias = __NAMESPACE__ . '\\' . $attribute;
    eval(sprintf(
        'namespace %s; function %s(#[Metadata, RevealSuffix(%s), %s] string $value): string { return $value; }',
        __NAMESPACE__,
        $function,
        var_export($alias, true),
        $attribute,
    ));
    $handler = new Transformer();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(Metadata::class, rules: $uncached ? [new NoOpRule()] : []))
        ->addAttributeDefinition(new AttributeDefinition(RevealSuffix::class, $handler, [ValueTransformer::class]))
        ->addAttributeDefinition(new AttributeDefinition(Suffix::class, $handler, [ValueTransformer::class]))
        ->build();
    Metadata::$constructions = 0;

    expect($container->call(__NAMESPACE__ . '\\' . $function, ['value' => 'input']))->toBe('input:first:suffix')
        ->and($handler->events)->toBe(['first', 'suffix'])
        ->and(Metadata::$constructions)->toBe(0);

    expect($container->call(__NAMESPACE__ . '\\' . $function, ['value' => 'next']))->toBe('next:first:suffix')
        ->and($handler->events)->toBe(['first', 'suffix', 'first', 'suffix'])
        ->and(Metadata::$constructions)->toBe(0);
})->with(['cached plans' => false, 'uncached plans' => true]);
