<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ParameterClassificationRefresh;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionFunction;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DeclaredSource {}

interface ClassifiedType {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
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

test('all parameter paths observe sources loaded incidentally while classifying immutable types', function (string $path, bool $uncached): void {
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
    $loader = static function (string $class) use ($type, $attribute): void {
        if ($class !== __NAMESPACE__ . '\\' . $type) {
            return;
        }
        // A class file can declare related attributes as well as the requested
        // type. The classifier's answer remains immutable and false throughout.
        eval(sprintf(
            'namespace %s; final class %s {} #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s extends DeclaredSource {}',
            __NAMESPACE__,
            $type,
            $attribute,
        ));
    };
    spl_autoload_register($loader);

    try {
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
        $resolver = $container->get(ParametersResolver::class);
        $callable = __NAMESPACE__ . '\\' . $function;
        if (!is_callable($callable)) {
            throw new \LogicException('The generated function must be callable.');
        }
        $parameters = new ReflectionFunction($callable)->getParameters();
        expect(class_exists(__NAMESPACE__ . '\\' . $attribute, false))->toBeFalse();

        if ($path === 'call') {
            $result = $container->call($callable);
        } else {
            $arguments = match ($path) {
                'single' => [$resolver->resolveParameter(new ParameterTarget($parameters[0]), new ParameterResolutionContext())[1]],
                'resolve' => $resolver->resolve($parameters),
                'prepared' => $resolver->resolvePrepared($resolver->prepare($parameters)),
                default => throw new \LogicException('Unknown resolution path.'),
            };
            $result = $container->call(new PreparedCallable($callable), $arguments);
        }

        expect($result)->toBe('source-value')
            ->and($handler->calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['single', 'resolve', 'prepared', 'call'])->with(['cached' => false, 'fresh' => true]);
