<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ParameterClassificationReentrancy;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use ReflectionFunction;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DeclaredSource {}

interface ClassifiedType {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class TypeClassifier implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        foreach ($target->typeNames as $type) {
            if (is_a($type, ClassifiedType::class, true)) {
                return true;
            }
        }
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

final class SourceHandler implements ParameterAttributeHandlerInterface
{
    public int $calls = 0;

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        ++$this->calls;
        return ParameterAttributeValue::resolved('source-value');
    }
}

test('immutable classification tolerates metadata refreshed by nested or interleaved resolution', function (
    string $path,
    bool $uncached,
    bool $suspend,
): void {
    $suffix = bin2hex(random_bytes(5));
    $type = 'Classified_' . $suffix;
    $attribute = 'Source_' . $suffix;
    $function = 'read_' . $suffix;
    eval(sprintf(
        'namespace %s; function %s(#[%s] %s|string $value = "native-default"): mixed { return $value; }',
        __NAMESPACE__,
        $function,
        $attribute,
        $type,
    ));
    $handler = new SourceHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            DeclaredSource::class,
            $handler,
            [ValueProvider::class],
            rules: $uncached ? [new NoOpRule()] : [],
        ))
        ->addParameterResolver(new TypeClassifier(), -1000)
        ->build();
    $nestedResults = [];
    $loader = static function (string $class) use ($type, $attribute, $container, $suspend, &$nestedResults): void {
        if ($class !== __NAMESPACE__ . '\\' . $type) {
            return;
        }
        eval(sprintf(
            'namespace %s; final class %s {} #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s extends DeclaredSource {}',
            __NAMESPACE__,
            $type,
            $attribute,
        ));
        if ($suspend) {
            Fiber::suspend('metadata-loaded');
        } else {
            $nestedResults[] = $container->call(static fn(): string => 'nested-result');
        }
    };
    spl_autoload_register($loader);

    try {
        $callable = __NAMESPACE__ . '\\' . $function;
        $resolver = $container->getAs(ParametersResolver::class, ParametersResolver::class);
        $target = $resolver->target(new ReflectionFunction($callable)->getParameters()[0]);
        $operation = $path === 'call'
            ? static fn(): mixed => $container->call($callable)
            : static fn(): mixed => $resolver->resolveParameter($target, new ParameterResolutionContext())[1];

        if ($suspend) {
            $fiber = new Fiber($operation);
            expect($fiber->start())->toBe('metadata-loaded');
            $nestedResults[] = $container->call(static fn(): string => 'nested-result');
            $fiber->resume();
            $result = $fiber->getReturn();
        } else {
            $result = $operation();
        }

        expect($result)->toBe('source-value')
            ->and($nestedResults)->toBe(['nested-result'])
            ->and($handler->calls)->toBe(1)
            ->and($operation())->toBe('source-value')
            ->and($handler->calls)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['call', 'single'])
    ->with(['cached' => false, 'non-memoized' => true])
    ->with(['nested autoloader call' => false, 'interleaved fiber' => true]);

test('a selected parameter target stays in use if an autoloader rebinds the caller slot', function (): void {
    $container = (new ContainerBuilder())->addParameterResolver(new TypeClassifier(), -1000)->build();
    $resolver = $container->getAs(ParametersResolver::class, ParametersResolver::class);
    $suffix = bin2hex(random_bytes(5));
    $type = 'UnavailableTargetType_' . $suffix;
    $function = 'target_' . $suffix;
    eval(sprintf(
        'namespace %s; function %s(%s|string $value = "primary"): mixed { return $value; }',
        __NAMESPACE__,
        $function,
        $type,
    ));
    $primary = new ReflectionFunction(__NAMESPACE__ . '\\' . $function);
    $alternate = new ReflectionFunction(static fn(string $value = 'alternate'): string => $value);
    $selected = $resolver->target($primary->getParameters()[0]);
    $replacement = $resolver->target($alternate->getParameters()[0]);
    $targets = [&$selected];
    $loader = static function (string $class) use (&$selected, $replacement, $type): void {
        if ($class === __NAMESPACE__ . '\\' . $type) {
            $selected = $replacement;
        }
    };
    spl_autoload_register($loader);

    try {
        expect($resolver->resolveTargets($targets))->toBe(['primary'])
            ->and($resolver->resolveTargets($targets))->toBe(['alternate']);
    } finally {
        spl_autoload_unregister($loader);
    }
});

final class MutatingClassifier implements ParameterResolverInterface
{
    private bool $mutated = false;

    public function __construct(private readonly ParametersResolver $resolver) {}

    public function supports(ParameterTarget $target): bool
    {
        if (!$this->mutated) {
            $this->mutated = true;
            $this->resolver->add(new ArrayResolver());
        }
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

test('classification still rejects an actual resolver registration during supports', function (): void {
    $resolver = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $resolver->add(new MutatingClassifier($resolver));
    $parameters = new ReflectionFunction(static fn(string $value): string => $value)->getParameters();

    expect(fn() => $resolver->resolve($parameters, ['value' => 'provided']))
        ->toThrow(InvalidConfigurationException::class, 'supports() must not mutate the resolver chain');
});
