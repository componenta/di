<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionFunction;
use ReflectionParameter;
use stdClass;

final class FixedPipelineValue implements ParameterResolverInterface
{
    public function __construct(private readonly string $value) {}

    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, $this->value];
    }
}

function pipelineAuditCallable(string $value): void {}

test('the public attribute resolver returns the declared position and preserves supplied value priority', function (): void {
    $container = (new ContainerBuilder(new Config([], new Environment(['PIPELINE_LABEL' => 'environment']))))->build();
    $resolver = new AttributeParameterResolver($container->getAs(AttributePlanBuilder::class, AttributePlanBuilder::class));
    $target = new ParameterTarget(new ReflectionParameter(
        static fn(string $prefix, #[Env('PIPELINE_LABEL')] string $value): string => $prefix . $value,
        'value',
    ));

    expect($resolver->resolveParameter($target, new ParameterResolutionContext()))
        ->toBe([1, 'environment'])
        ->and($resolver->resolveParameter($target, new ParameterResolutionContext(['value' => 'provided'])))
        ->toBe([1, 'provided']);
});

test('equal resolver priorities preserve registration order', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $first = new FixedPipelineValue('first');
    $second = new FixedPipelineValue('second');
    $lower = new FixedPipelineValue('lower');
    $pipeline->add($lower, -1);
    $pipeline->add($first);
    $pipeline->add($second, 0);

    expect($pipeline->resolverList)->toBe([$first, $second, $lower])
        ->and($pipeline->semanticRegistrations())->toBe([
            ['resolver' => $first, 'priority' => 0],
            ['resolver' => $second, 'priority' => 0],
            ['resolver' => $lower, 'priority' => -1],
        ])
        ->and($pipeline->resolve((new ReflectionFunction(__NAMESPACE__ . '\pipelineAuditCallable'))->getParameters()))
        ->toBe(['first']);

    $pipeline->seal();
    expect($pipeline->isSealed)->toBeTrue()
        ->and($pipeline->resolve((new ReflectionFunction(__NAMESPACE__ . '\pipelineAuditCallable'))->getParameters()))
        ->toBe(['first']);
});

test('registration invalidates the previously observed resolver order', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $first = new FixedPipelineValue('first');
    $higher = new FixedPipelineValue('higher');
    $pipeline->add($first);

    expect($pipeline->resolverList)->toBe([$first])
        ->and($pipeline->semanticRegistrations())->toBe([['resolver' => $first, 'priority' => 0]]);
    $pipeline->add($higher, 10);

    expect($pipeline->resolverList)->toBe([$higher, $first])
        ->and($pipeline->semanticRegistrations())->toBe([
            ['resolver' => $higher, 'priority' => 10],
            ['resolver' => $first, 'priority' => 0],
        ]);
});

test('a resolver instance can only be registered once and sealing prevents further changes', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $resolver = new FixedPipelineValue('one');
    expect($pipeline->isSealed)->toBeFalse();
    $pipeline->add($resolver);
    expect(fn() => $pipeline->add($resolver, 12))->toThrow(
        InvalidConfigurationException::class,
        'Parameter resolver ' . FixedPipelineValue::class . ' is already registered.',
    );
    $pipeline->seal();
    expect(fn() => $pipeline->add(new FixedPipelineValue('two')))->toThrow(
        InvalidConfigurationException::class,
        'Parameter resolver pipeline is sealed and cannot be changed.',
    );
});

test('standalone parameter resolution uses the supplied target factory and explicit values', function (): void {
    $factory = new ParameterTargetFactory();
    $reflection = new ReflectionParameter(__NAMESPACE__ . '\pipelineAuditCallable', 0);
    $known = $factory->create($reflection);
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()), $factory);
    $pipeline->add(new ArrayResolver());
    $pipeline->seal();

    expect($pipeline->target($reflection))->toBe($known)
        ->and($pipeline->targets([$reflection]))->toBe([$known])
        ->and($pipeline->resolveParameter($known, new ParameterResolutionContext(['value' => 'single'])))
        ->toBe([0, 'single'])
        ->and($pipeline->resolveTargets([$known], ['value' => 'batch']))->toBe(['batch'])
        ->and($pipeline->resolve([$reflection], ['value' => 'reflection']))->toBe(['reflection']);
});

test('prepared parameter plans keep their owner private and are accepted only by their pipeline', function (): void {
    $plans = new AttributePlanBuilder(new AttributeDefinitionRegistry());
    $pipeline = new ParametersResolver($plans);
    $other = new ParametersResolver($plans);
    foreach ([$pipeline, $other] as $resolver) {
        $resolver->add(new ArrayResolver());
        $resolver->seal();
    }
    $reflection = new ReflectionParameter(__NAMESPACE__ . '\\pipelineAuditCallable', 0);
    $issued = $pipeline->prepare([$reflection]);
    $foreign = $other->prepare([$reflection]);
    $unissued = new PreparedParameterPlan($issued->targets, $issued->resolverRevision, new stdClass(), false);

    expect(get_object_vars($issued))->not->toHaveKey('owner')
        ->and($pipeline->isCurrentPlan($issued))->toBeTrue()
        ->and($pipeline->resolvePrepared($issued, ['value' => 'provided']))->toBe(['provided']);
    foreach ([$foreign, $unissued] as $plan) {
        expect($pipeline->isCurrentPlan($plan))->toBeFalse()
            ->and(fn() => $pipeline->resolvePrepared($plan, ['value' => 'provided']))
            ->toThrow(InvalidConfigurationException::class, 'Prepared parameter plan belongs to another resolver pipeline.');
    }
});
