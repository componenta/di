<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\ClosureMethodAttributePlanContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use LogicException;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use stdClass;
use WeakReference;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Label
{
    public function __construct(public string $value) {}
}

final readonly class RetargetingRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        $target = $attribute->target;
        if (!$target instanceof ReflectionMethod) {
            throw new LogicException('Expected the invocation method of the closure.');
        }
        $target->__construct(static fn(): float => 2.0, '__invoke');
    }
}

test('closure invocation method plans preserve each instance declaration and signature', function (bool $uncached): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Label::class, rules: $uncached ? [new RetargetingRule()] : []));
    $builder = new AttributePlanBuilder($registry);
    $first = #[Label('first'), Label('second')] static fn(): string => 'first';
    $second = #[Label('other')] static fn(string $name, bool $enabled): bool => $enabled && $name !== '';

    foreach ([$first, $second, $first] as $closure) {
        $plan = $builder->build($closure);
        $target = $plan->target;
        $usageTarget = $plan->usages[0]->target;
        if (!$target instanceof ReflectionMethod || !$usageTarget instanceof ReflectionMethod) {
            throw new LogicException('Expected native invocation methods for the closure.');
        }
        $replacement = $closure === $first ? $second : $first;
        $target->__construct($replacement, '__invoke');
        $usageTarget->__construct($replacement, '__invoke');

        $current = $plan->target;
        if (!$current instanceof ReflectionMethod) {
            throw new LogicException('Expected the invocation method of the closure.');
        }
        $expected = $closure === $first ? [['first'], ['second']] : [['other']];
        expect(array_map(static fn(AttributeUsage $usage): array => $usage->arguments, $plan->usages))->toBe($expected)
            ->and(array_map(static fn(ReflectionAttribute $attribute): array => $attribute->getArguments(), $current->getAttributes()))->toBe($expected)
            ->and(array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $current->getParameters()))
            ->toBe($closure === $first ? [] : ['name', 'enabled'])
            ->and((string) $current->getReturnType())->toBe($closure === $first ? 'string' : 'bool')
            ->and($plan->usages[0]->target->getAttributes(Label::class)[0]->getArguments())->toBe($expected[0]);
    }
})->with(['ordinary composition' => false, 'custom composition rule' => true]);

test('a closure invocation method needs the original closure at publication boundaries', function (bool $derived): void {
    $closure = #[Label('source')] static fn(): int => 1;
    $method = new ReflectionMethod($closure, '__invoke');
    $source = $derived ? new class ($closure, '__invoke') extends ReflectionMethod {
        /** @return array<int,ReflectionAttribute<object>> */
        public function getAttributes(?string $name = null, int $flags = 0): array
        {
            throw new LogicException('An invalid target must fail before discovery.');
        }
    } : $method;
    $attribute = $method->getAttributes(Label::class)[0];
    $definition = new AttributeDefinition(Label::class);
    $registry = new AttributeDefinitionRegistry();
    $registry->register($definition);

    expect(fn() => new AttributePlanBuilder($registry)->build($source))
        ->toThrow(AttributeCompositionException::class, 'Pass the Closure object')
        ->and(fn() => new AttributePlan($source, []))
        ->toThrow(AttributeCompositionException::class, 'Pass the Closure object')
        ->and(fn() => new AttributeUsage($attribute, $definition, $source, 0))
        ->toThrow(AttributeCompositionException::class, 'Pass the Closure object');
})->with(['native reflector' => false, 'derived reflector' => true]);

test('a closure method plan retains captures only while the plan or usage is alive', function (): void {
    $capture = new stdClass();
    $reference = WeakReference::create($capture);
    $closure = #[Label('captured')] static fn(): stdClass => $capture;
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Label::class));
    $builder = new AttributePlanBuilder($registry);
    $plan = $builder->build($closure);
    $usage = $plan->usages[0];
    unset($capture, $closure, $plan);

    expect($reference->get())->toBeInstanceOf(stdClass::class)
        ->and($usage->target->getAttributes(Label::class)[0]->getArguments())->toBe(['captured']);

    unset($usage);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

test('the object attribute processor prepares the Closure class declaration', function (): void {
    $closure = #[Label('callback')] static fn(): int => 1;
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Label::class));
    $builder = new AttributePlanBuilder($registry);
    $processor = new AttributeProcessor($builder);
    $reflection = new ReflectionObject($closure);

    $processor->prepare($reflection);

    expect($processor->hasHandlers($reflection))->toBeFalse()
        ->and($builder->build($closure)->usages)->toHaveCount(1);
});
