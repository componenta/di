<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClosureMethodAttributePlan;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use LogicException;
use ReflectionMethod;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Label
{
    public function __construct(public string $value) {}
}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

test('closure invocation method plans preserve each instance declaration and signature', function (bool $uncached): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Label::class, rules: $uncached ? [new NoOpRule()] : []));
    $builder = new AttributePlanBuilder($registry);
    $first = #[Label('first'), Label('second')] static fn(int $number): string => (string) $number;
    $second = #[Label('other')] static fn(string $name, bool $enabled): bool => $enabled && $name !== '';

    foreach ([$first, $second, $first] as $closure) {
        $method = new ReflectionMethod($closure, '__invoke');
        $plan = $builder->build($method);
        $target = $plan->target;
        if (!$target instanceof ReflectionMethod) {
            throw new LogicException('A method plan must expose its reflected method.');
        }

        expect(array_map(static fn(AttributeUsage $usage): array => $usage->arguments, $plan->usages))
            ->toBe($closure === $first ? [['first'], ['second']] : [['other']])
            ->and(array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $target->getParameters()))
            ->toBe($closure === $first ? ['number'] : ['name', 'enabled'])
            ->and((string) $target->getReturnType())->toBe($closure === $first ? 'string' : 'bool');
    }
})->with(['ordinary composition' => false, 'custom composition rule' => true]);
