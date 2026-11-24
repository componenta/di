<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\ActiveParameterViewContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextAccess;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionParameter;
use stdClass;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class KeepValue {}

final class KeepValueHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return $value;
    }
}

final class ObservePayload implements ParameterResolverInterface
{
    public ParametersResolver $parameters;
    public ?object $earlier = null;

    public function __construct(private readonly ParameterTarget $payload, private readonly string $read) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'prefix';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        $copy = match ($this->read) {
            'helper' => ParameterContextAccess::provided($context)['payload'],
            'standalone' => (new ArrayResolver())->resolveParameter($this->payload, $context)[1] ?? null,
            'reentrant' => $this->parameters->resolveParameter($this->payload, $context)[1],
            default => $context->provided['payload'],
        };
        if (!is_array($copy)) {
            throw new LogicException('Expected the explicitly supplied payload.');
        }

        $earlier = $context->resolved[0] ?? null;
        $this->earlier = is_object($earlier) ? $earlier : null;
        $copy['label'] = 'formatted locally';
        return [$target->position, 'prefix'];
    }
}

/**
 * @param array{label:string} $payload
 * @return array{stdClass,string,string}
 */
function consume(stdClass $owner, string $prefix, array $payload): array
{
    $selected = $payload['label'];
    $payload['label'] = 'changed inside callable';
    return [$owner, $prefix, $selected];
}

/**
 * @param array{label:string} $payload
 * @return array{stdClass,string,string}
 */
function consumeComposed(stdClass $owner, string $prefix, #[KeepValue] array $payload): array
{
    return consume($owner, $prefix, $payload);
}

test('custom resolver reads cannot rebind a later supplied literal in an active invocation', function (string $read, bool $composed): void {
    $call = $composed ? consumeComposed(...) : consume(...);
    $payload = new ParameterTarget(new ReflectionParameter($call, 'payload'));
    $custom = new ObservePayload($payload, $read);
    $builder = (new ContainerBuilder())->addParameterResolver($custom, 10000);
    if ($composed) {
        $builder->addAttributeDefinition(new AttributeDefinition(KeepValue::class, new KeepValueHandler()));
    }
    [$container, $runtime] = $builder->assemble();
    Assert::assertInstanceOf(ParametersResolver::class, $runtime->parameters);
    $custom->parameters = $runtime->parameters;
    $owner = new stdClass();
    $label = 'original';

    expect($container->call($call, [stdClass::class => $owner, 'payload' => ['label' => &$label]]))
        ->toBe([$owner, 'prefix', 'original'])
        ->and($label)->toBe('changed inside callable')
        ->and($custom->earlier)->toBe($owner);
})->with(['snapshot', 'helper', 'standalone', 'reentrant'])
    ->with(['plain input' => false, 'composed input' => true]);

test('a caller-created standalone context still resolves literal nested references', function (): void {
    $target = new ParameterTarget(new ReflectionParameter(consume(...), 'payload'));
    $label = 'original';
    $context = new ParameterResolutionContext(['payload' => ['label' => &$label]]);
    $copy = (new ArrayResolver())->resolveParameter($target, $context)[1] ?? null;
    if (!is_array($copy)) {
        throw new LogicException('Expected the explicitly supplied payload.');
    }

    $copy['label'] = 'changed through standalone result';
    expect($label)->toBe('changed through standalone result');
});
