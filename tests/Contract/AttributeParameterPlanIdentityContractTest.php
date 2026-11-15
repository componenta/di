<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeParameterPlanIdentity;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Marker
{
    public function __construct(public string $owner) {}
}

final class A
{
    public function Bc(#[Marker('owner A')] string $value): void {}
}

final class AB
{
    public function c(#[Marker('owner AB')] string $value): void {}
}

test('parameter attribute plans keep class and method boundaries distinct in either warmup order', function (bool $reverse): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Marker::class));
    $builder = new AttributePlanBuilder($registry);
    $cases = [[A::class, 'Bc', 'owner A'], [AB::class, 'c', 'owner AB']];
    if ($reverse) {
        $cases = array_reverse($cases);
    }

    foreach ([1, 2] as $_round) {
        foreach ($cases as [$class, $method, $owner]) {
            $parameter = (new ReflectionMethod($class, $method))->getParameters()[0];
            $usages = $builder->build($parameter)->attributes(Marker::class);
            expect($usages)->toHaveCount(1)
                ->and($usages[0]->arguments)->toBe([$owner]);
        }
    }
})->with(['forward' => false, 'reverse' => true]);
