<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Handler\ValueTransformerHandlerInterface;
use Componenta\DI\Exception\ValueProviderConflictException;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValuePipeline;
use Componenta\DI\Value\ValueResult;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PipelineSource
{
    public function __construct(public string $value) {}
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class PipelineTransform
{
    public function __construct(public string $suffix) {}
}

final class PipelineProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ExplicitFirst;
    }

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        return $attribute instanceof PipelineSource ? $attribute->value : null;
    }
}

final readonly class PipelineTransformer implements ValueTransformerHandlerInterface
{
    public function transform(
        object $attribute,
        mixed $value,
        ValueTargetInterface $target,
        ValueContext $context,
    ): mixed {
        return (string) $value . ($attribute instanceof PipelineTransform ? $attribute->suffix : '');
    }
}

final readonly class PipelineMappedFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return true;
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        return array_key_exists($target->name, $context->resolution->mapped)
            ? new ValueResult($context->resolution->mapped[$target->name])
            : null;
    }
}

function pipelinePlan(ReflectionParameter $parameter): array
{
    $registry = new AttributeDefinitionRegistry();
    $registry->defineCapability(new CapabilityPolicy(ValueProvider::class, 1));
    $registry->defineCapability(new CapabilityPolicy(ValueTransformer::class));
    $registry->register(new AttributeDefinition(PipelineSource::class, new PipelineProvider(), [ValueProvider::class]));
    $registry->register(new AttributeDefinition(PipelineTransform::class, new PipelineTransformer(), [ValueTransformer::class]));
    $registry->seal();

    return [
        new ParameterTarget($parameter),
        (new AttributePlanBuilder($registry))->build($parameter),
    ];
}

it('resolves one provider and applies repeatable transforms in declaration order', function (): void {
    $callable = static function (
        #[PipelineSource('raw')]
        #[PipelineTransform(':a')]
        #[PipelineTransform(':b')]
        string $value,
    ): void {};
    [$target, $plan] = pipelinePlan((new ReflectionFunction($callable))->getParameters()[0]);

    expect((new ValuePipeline([]))->resolve(
        $target,
        $plan,
        new ValueContext(new ResolutionContext()),
    ))->toBe('raw:a:b');
});

it('lets a trusted explicit value override an ExplicitFirst provider before transforms', function (): void {
    $callable = static function (
        #[PipelineSource('provider')]
        #[PipelineTransform(':cast')]
        string $value,
    ): void {};
    [$target, $plan] = pipelinePlan((new ReflectionFunction($callable))->getParameters()[0]);

    expect((new ValuePipeline([]))->resolve(
        $target,
        $plan,
        new ValueContext(ResolutionContext::explicit(['value' => 'explicit'])),
    ))->toBe('explicit:cast');
});

it('rejects mapped input colliding with a declared provider', function (): void {
    $callable = static function (#[PipelineSource('provider')] string $value): void {};
    [$target, $plan] = pipelinePlan((new ReflectionFunction($callable))->getParameters()[0]);

    expect(fn() => (new ValuePipeline([]))->resolve(
        $target,
        $plan,
        new ValueContext(ResolutionContext::mapped(['value' => 'mapped'])),
    ))->toThrow(ValueProviderConflictException::class);
});

it('uses fallback input when no value provider is declared', function (): void {
    $callable = static function (#[PipelineTransform(':done')] string $value): void {};
    [$target, $plan] = pipelinePlan((new ReflectionFunction($callable))->getParameters()[0]);

    expect((new ValuePipeline([new PipelineMappedFallback()]))->resolve(
        $target,
        $plan,
        new ValueContext(ResolutionContext::mapped(['value' => 'mapped'])),
    ))->toBe('mapped:done');
});
