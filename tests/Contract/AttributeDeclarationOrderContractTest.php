<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeDeclarationOrder;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class Label
{
    public function __construct(public string $value) {}
}

#[Label('first'), Label('second')]
final class Subject {}

function parameter(#[Label('first'), Label('second')] string $value): void {}

/** @extends ReflectionClass<object> */
final class ReversedClassAttributes extends ReflectionClass
{
    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return array_reverse(parent::getAttributes($name, $flags), true);
    }
}

final class ReversedParameterAttributes extends ReflectionParameter
{
    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return array_reverse(parent::getAttributes($name, $flags), true);
    }
}

test('independent attributes retain declaration order when a reflector returns reversed numeric indexes', function (bool $parameter): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Label::class));
    $target = $parameter
        ? new ReversedParameterAttributes(__NAMESPACE__ . '\\parameter', 0)
        : new ReversedClassAttributes(Subject::class);

    $plan = (new AttributePlanBuilder($registry))->build($target);

    expect(array_map(static fn(AttributeUsage $usage): mixed => $usage->arguments[0], $plan->usages))
        ->toBe(['first', 'second']);
})->with(['class reflector' => false, 'parameter reflector' => true]);
