<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use ReflectionProperty;
use Reflector;

final readonly class PromotedReadonlyConfigCastTarget
{
    public function __construct(
        #[ConfigAttribute('raw'), Cast('trim')]
        public string $value,
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ReadonlyTransformA {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ReadonlyTransformB {}

final class ReadonlyTransformHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionProperty) {
            throw new \LogicException('ReadonlyTransformHandler requires a property target.');
        }

        if ($context->propertyClaimed($target)) {
            $current = $context->readProperty($target);
            if (!is_string($current)) {
                throw new \LogicException('Expected the readonly transformer fixture to read a string.');
            }

            $context->writeProperty(
                $target,
                $current . ':next',
            );
            return;
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        $initial = $context->parameters[$target->getName()] ?? 'initial';
        if (!is_string($initial)) {
            throw new \LogicException('Expected the readonly transformer fixture to receive a string.');
        }

        $context->writeProperty($target, $initial);
    }
}

/** @return non-empty-string */
function multipleReadonlyTransformersTarget(): string
{
    $class = __NAMESPACE__ . '\\MultipleReadonlyTransformersTarget';

    if (!class_exists($class, false)) {
        eval(<<<'PHP'
namespace Componenta\DI\Tests\V5;

final class MultipleReadonlyTransformersTarget
{
    #[ReadonlyTransformA, ReadonlyTransformB]
    public readonly string $value;
}
PHP);
    }

    if (!class_exists($class, false)) {
        throw new \LogicException('Failed to define the readonly transformer fixture.');
    }

    return $class;
}

test('promoted readonly values compose identically for every container build', function (): void {
    $config = new Config(['raw' => '  composed  '], new Environment([]));
    $containers = [
        ContainerBuilder::configure($config)
            ->addService(CasterProviderInterface::class, new TestCasterProvider())
            ->build(),
        ContainerBuilder::configure($config)
            ->addService(CasterProviderInterface::class, new TestCasterProvider())
            ->build(),
    ];

    foreach ($containers as $container) {
        expect($container->make(PromotedReadonlyConfigCastTarget::class)->value)
            ->toBe('composed');
    }
});

test('multiple transformers on a non-promoted readonly property fail composition before writes', function (): void {
    $handler = new ReadonlyTransformHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ReadonlyTransformA::class,
            $handler,
            capabilities: [ValueTransformer::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            ReadonlyTransformB::class,
            $handler,
            capabilities: [ValueTransformer::class],
        ))
        ->build();

    expect(fn() => $container->make(multipleReadonlyTransformersTarget(), ['value' => 'seed']))
        ->toThrow(AttributeCompositionException::class, 'multiple value transformers');
});
