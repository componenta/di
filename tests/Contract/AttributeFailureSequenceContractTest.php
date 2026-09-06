<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;
use RuntimeException;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class StopAttributeSequence {}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class UnexpectedLaterAttribute
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
        throw new LogicException('A later attribute must not be constructed after the sequence fails.');
    }
}

final class AttributeSequenceFailure extends RuntimeException implements ExceptionInterface {}

final class StopAttributeSequenceHandler implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    public int $calls = 0;

    public function __construct(private AttributeSequenceFailure $failure) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        ++$this->calls;
        throw $this->failure;
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
        throw $this->failure;
    }
}

final class StoppedAttributeProperty
{
    #[StopAttributeSequence, UnexpectedLaterAttribute]
    public string $value = 'input';
}

function stoppedAttributeParameter(#[StopAttributeSequence, UnexpectedLaterAttribute] string $value): string
{
    return $value;
}

test('the first failing handler stops attribute construction and preserves its exception', function (bool $property): void {
    UnexpectedLaterAttribute::$constructions = 0;
    $failure = new AttributeSequenceFailure('First handler rejected the input.');
    $handler = new StopAttributeSequenceHandler($failure);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            StopAttributeSequence::class,
            $handler,
            [ValueTransformer::class],
            before: [UnexpectedLaterAttribute::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            UnexpectedLaterAttribute::class,
            $handler,
            [ValueTransformer::class],
        ))
        ->build();

    $caught = null;
    try {
        if ($property) {
            $container->make(StoppedAttributeProperty::class);
        } else {
            $container->call(__NAMESPACE__ . '\\stoppedAttributeParameter', ['value' => 'input']);
        }
    } catch (AttributeSequenceFailure $exception) {
        $caught = $exception;
    }

    expect($caught)->toBe($failure)
        ->and($handler->calls)->toBe(1)
        ->and(UnexpectedLaterAttribute::$constructions)->toBe(0);
})->with(['property' => true, 'parameter' => false]);
