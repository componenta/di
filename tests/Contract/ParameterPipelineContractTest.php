<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionAccess;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Resolver\Attribute\Handler\EnvHandler;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Error;
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

function pipelineAuditEnvironment(#[Env('PIPELINE_LABEL')] string $value = 'default'): void {}

test('the public attribute resolver returns the declared position and preserves supplied value priority', function (): void {
    [$container, $runtime] = (new ContainerBuilder(new Config([], new Environment(['PIPELINE_LABEL' => 'environment']))))->assemble();
    $resolver = new AttributeParameterResolver($runtime->plans);
    $target = new ParameterTarget(new ReflectionParameter(
        static fn(string $prefix, #[Env('PIPELINE_LABEL')] string $value): string => $prefix . $value,
        'value',
    ));

    expect($resolver->resolveParameter($target, new ParameterResolutionContext()))
        ->toBe([1, 'environment'])
        ->and($resolver->resolveParameter($target, new ParameterResolutionContext(['value' => 'provided'])))
        ->toBe([1, 'provided']);
});

test('parameter resolver registration requires one attribute definition registry', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $pipeline = new ParametersResolver(new AttributePlanBuilder($registry));
    $foreign = new AttributeParameterResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));

    expect(fn() => $pipeline->add($foreign, 100))
        ->toThrow(InvalidConfigurationException::class, 'same attribute definition registry');

    $pipeline->add(new AttributeParameterResolver(new AttributePlanBuilder($registry)), 100);
    $pipeline->add(new DefaultValueResolver());
    $pipeline->seal();
    $target = new ReflectionParameter(__NAMESPACE__ . '\pipelineAuditEnvironment', 0);
    expect($pipeline->resolve([$target]))->toBe(['default']);

    $container = (new ContainerBuilder(new Config([], new Environment(['PIPELINE_LABEL' => 'environment']))))->build();
    $registry->register(new AttributeDefinition(Env::class, new EnvHandler($container)));
    expect($pipeline->resolve([$target]))->toBe(['environment']);
});

test('equal resolver priorities preserve registration order', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $first = new FixedPipelineValue('first');
    $second = new FixedPipelineValue('second');
    $lower = new FixedPipelineValue('lower');
    $pipeline->add($lower, -1);
    $pipeline->add($first);
    $pipeline->add($second, 0);

    $declarations = (new ReflectionFunction(__NAMESPACE__ . '\pipelineAuditCallable'))->getParameters();
    expect($pipeline->resolve($declarations))->toBe(['first']);

    $pipeline->seal();
    expect($pipeline->isSealed)->toBeTrue()
        ->and($pipeline->resolve($declarations))->toBe(['first']);
});

test('registration invalidates the previously observed resolver order', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $first = new FixedPipelineValue('first');
    $higher = new FixedPipelineValue('higher');
    $pipeline->add($first);

    $declarations = (new ReflectionFunction(__NAMESPACE__ . '\pipelineAuditCallable'))->getParameters();
    $plan = ParameterExecutionAccess::for($pipeline)->prepare($declarations);
    expect(ParameterExecutionAccess::for($pipeline)->resolvePrepared($plan))->toBe(['first']);
    $pipeline->add($higher, 10);

    expect(ParameterExecutionAccess::for($pipeline)->resolvePrepared($plan))->toBe(['higher']);
});

test('a parameter resolver pipeline cannot be cloned with its plan owner and caches', function (): void {
    $pipeline = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));

    expect(static fn(): ParametersResolver => clone $pipeline)->toThrow(Error::class, '__clone');
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
    $issued = ParameterExecutionAccess::for($pipeline)->prepare([$reflection]);
    $foreign = ParameterExecutionAccess::for($other)->prepare([$reflection]);
    $unissued = new PreparedParameterPlan($issued->targets, $issued->resolverRevision, new stdClass(), false);

    expect(get_object_vars($issued))->not->toHaveKey('owner')
        ->and(ParameterExecutionAccess::for($pipeline)->isCurrentPlan($issued))->toBeTrue()
        ->and(ParameterExecutionAccess::for($pipeline)->resolvePrepared($issued, ['value' => 'provided']))->toBe(['provided']);
    foreach ([$foreign, $unissued] as $plan) {
        expect(ParameterExecutionAccess::for($pipeline)->isCurrentPlan($plan))->toBeFalse()
            ->and(fn() => ParameterExecutionAccess::for($pipeline)->resolvePrepared($plan, ['value' => 'provided']))
            ->toThrow(InvalidConfigurationException::class, 'Prepared parameter plan belongs to another resolver pipeline.');
    }
});
