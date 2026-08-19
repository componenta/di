<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use ReflectionClass;
use Reflector;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class AuditStatefulParameterAttribute
{
    public ?object $runtimeState = null;
}

final class AuditStatefulParameterHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$attribute instanceof AuditStatefulParameterAttribute) {
            throw new LogicException('Unexpected attribute.');
        }
        if ($attribute->runtimeState !== null) {
            throw new LogicException('Parameter attribute runtime state leaked between resolutions.');
        }
        $attribute->runtimeState = new \stdClass();
        return ParameterAttributeValue::resolved('isolated');
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class AuditStatefulObjectAttribute
{
    public ?object $runtimeState = null;
}

#[AuditStatefulObjectAttribute]
final class AuditStatefulObjectTarget {}

final class AuditStatefulObjectHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof AuditStatefulObjectAttribute || !$target instanceof ReflectionClass) {
            throw new LogicException('Unexpected attribute target.');
        }
        if ($attribute->runtimeState !== null) {
            throw new LogicException('Object attribute runtime state leaked between resolutions.');
        }
        $attribute->runtimeState = new \stdClass();
    }
}

test('parameter attribute runtime instances are isolated between calls', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AuditStatefulParameterAttribute::class,
            new AuditStatefulParameterHandler(),
            capabilities: [ValueProvider::class],
        ))
        ->build();

    $callable = static fn(#[AuditStatefulParameterAttribute] string $value): string => $value;

    expect($container->call($callable))->toBe('isolated')
        ->and($container->call($callable))->toBe('isolated');
});

test('object attribute runtime instances are isolated between object creations', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AuditStatefulObjectAttribute::class,
            new AuditStatefulObjectHandler(),
        ))
        ->build();

    expect($container->make(AuditStatefulObjectTarget::class))->toBeInstanceOf(AuditStatefulObjectTarget::class)
        ->and($container->make(AuditStatefulObjectTarget::class))->toBeInstanceOf(AuditStatefulObjectTarget::class);
});
