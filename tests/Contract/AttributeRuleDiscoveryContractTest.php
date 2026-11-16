<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeRuleDiscovery;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use ReflectionClass;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class DiscoveredMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final class RequiredMarker {}

final class NativeArgument
{
    public function __construct(string $alias)
    {
        if (!class_exists($alias, false)) {
            class_alias(DiscoveredMarker::class, $alias);
        }
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LoadingMarker
{
    public function __construct(public NativeArgument $argument) {}
}

final class ArgumentReadingRule implements AttributeCompositionRuleInterface
{
    public bool $enabled = true;

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($this->enabled && !($attribute->arguments[0] ?? null) instanceof NativeArgument) {
            throw new LogicException('Expected the native argument fixture.');
        }
    }
}

final class CountingHandler implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
    }
}

/** @return ReflectionClass<object> */
function target(): ReflectionClass
{
    $suffix = bin2hex(random_bytes(6));
    $alias = 'LateMarker_' . $suffix;
    $class = 'Target_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s, LoadingMarker(new NativeArgument(%s))] final class %s {}',
        __NAMESPACE__,
        $alias,
        var_export(__NAMESPACE__ . '\\' . $alias, true),
        $class,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!class_exists($name)) {
        throw new LogicException('Expected the rule discovery target.');
    }

    return new ReflectionClass($name);
}

test('direct plans include metadata discovered while a rule reads native arguments', function (bool $warm): void {
    $handler = new CountingHandler();
    $rule = new ArgumentReadingRule();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(DiscoveredMarker::class, $handler));
    $registry->register(new AttributeDefinition(LoadingMarker::class, $handler, rules: [$rule]));
    $registry->seal();
    $builder = new AttributePlanBuilder($registry);
    $target = target();
    if ($warm) {
        $rule->enabled = false;
        expect($builder->build($target)->attributes(DiscoveredMarker::class))->toBe([]);
        $rule->enabled = true;
    }

    $plan = $builder->build($target);

    expect($plan->attributes(DiscoveredMarker::class))->toHaveCount(1)
        ->and($plan->attributes(LoadingMarker::class))->toHaveCount(1)
        ->and($handler->calls)->toBe(0);

    expect($builder->build($target)->attributes(DiscoveredMarker::class))->toHaveCount(1)
        ->and($handler->calls)->toBe(0);
})->with(['cold metadata' => false, 'previously inspected metadata' => true]);

test('the first direct plan rejects constraints introduced by rule-time discovery', function (bool $requires): void {
    $handler = new CountingHandler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        DiscoveredMarker::class,
        $handler,
        requires: $requires ? [RequiredMarker::class] : [],
        forbids: $requires ? [] : [LoadingMarker::class],
    ));
    $registry->register(new AttributeDefinition(LoadingMarker::class, $handler, rules: [new ArgumentReadingRule()]));
    $registry->seal();
    $builder = new AttributePlanBuilder($registry);
    $target = target();

    expect(fn() => $builder->build($target))
        ->toThrow(AttributeCompositionException::class, $requires ? 'requires' : 'forbids')
        ->and($handler->calls)->toBe(0);
})->with(['missing required attribute' => true, 'forbidden combination' => false]);
