<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ParameterExecutionRevision;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DeclaredSource {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ExistingTransformer {}

final class ExistingTransformerHandler implements ParameterAttributeHandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $continueResolution) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        ++$this->calls;
        return $this->continueResolution
            ? ParameterAttributeValue::unresolved()
            : ParameterAttributeValue::resolved('known-value');
    }
}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class InvocationLog
{
    public static int $bodyCalls = 0;
}

final class AutoloadPhase
{
    public bool $armed = false;
    public int $attempts = 0;
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

test('parameter execution never silently skips an available attribute source', function (bool $warm, bool $uncached, string $priorHandler): void {
    // These delays span every current cold/warm discovery point, including the
    // uncached control and loading after resolution. They are test inputs, not
    // an expected poll count or a production retry limit. The endpoints also
    // exercise an immediately available source and one that never loads.
    foreach ([...range(1, 320), PHP_INT_MAX] as $availableOnAttempt) {
        $suffix = bin2hex(random_bytes(6));
        $attribute = 'Source_' . $suffix;
        $attributeClass = __NAMESPACE__ . '\\' . $attribute;
        $function = 'read_' . $suffix;
        $callable = __NAMESPACE__ . '\\' . $function;
        eval(sprintf(
            'namespace %s; function %s(#[%s] string $value): array { ++InvocationLog::$bodyCalls; return [$value, class_exists(%s, false)]; }',
            __NAMESPACE__,
            $function,
            $priorHandler === 'none' ? $attribute : 'ExistingTransformer, ' . $attribute,
            var_export($attributeClass, true),
        ));

        $phase = new AutoloadPhase();
        $loader = static function (string $class) use ($attribute, $attributeClass, $availableOnAttempt, $phase): void {
            if ($class !== $attributeClass || !$phase->armed || ++$phase->attempts !== $availableOnAttempt) {
                return;
            }
            eval(sprintf(
                'namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s extends DeclaredSource {}',
                __NAMESPACE__,
                $attribute,
            ));
        };
        spl_autoload_register($loader);

        try {
            $handler = new SourceHandler();
            $existing = new ExistingTransformerHandler($priorHandler === 'unresolved');
            $container = (new ContainerBuilder())
                ->addAttributeDefinition(new AttributeDefinition(
                    ExistingTransformer::class,
                    $existing,
                    [ValueTransformer::class],
                ))
                ->addAttributeDefinition(new AttributeDefinition(
                    DeclaredSource::class,
                    $handler,
                    [ValueProvider::class],
                    rules: $uncached ? [new NoOpRule()] : [],
                ))
                ->build();
            $withoutSource = $priorHandler === 'resolved' ? 'known-value' : 'caller-value';
            if ($warm) {
                expect($container->call($callable, ['value' => 'caller-value']))
                    ->toBe([$withoutSource, false]);
            }

            $phase->armed = true;
            InvocationLog::$bodyCalls = 0;
            $existing->calls = 0;
            try {
                $result = $container->call($callable, ['value' => 'caller-value']);
            } catch (AttributeCompositionException) {
                // A source discovered after the parameter has completed must
                // fail before the body rather than replaying completed work.
                expect(InvocationLog::$bodyCalls)->toBe(0)
                    ->and($handler->calls)->toBe(0)
                    ->and($existing->calls)->toBe($priorHandler === 'none' ? 0 : 1)
                    ->and(class_exists($attributeClass, false))->toBeTrue();
                continue;
            }

            expect(InvocationLog::$bodyCalls)->toBe(1)
                ->and($existing->calls)->toBe($priorHandler === 'none' ? 0 : 1);
            if (class_exists($attributeClass, false)) {
                expect($result)->toBe(['source-value', true])
                    ->and($handler->calls)->toBe(1);
            } else {
                expect($result)->toBe([$withoutSource, false])
                    ->and($handler->calls)->toBe(0);
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }
})->with(['cold' => false, 'warm' => true])
    ->with(['cached' => false, 'uncached' => true])
    ->with(['none', 'resolved', 'unresolved']);
