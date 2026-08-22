<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
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
            $context->writeProperty(
                $target,
                (string) $context->readProperty($target) . ':next',
            );
            return;
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        $context->writeProperty(
            $target,
            (string) ($context->parameters[$target->getName()] ?? 'initial'),
        );
    }
}

final class MultipleReadonlyTransformersTarget
{
    #[ReadonlyTransformA, ReadonlyTransformB]
    public readonly string $value;
}

function cleanupReadonlyCompositionDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

test('promoted readonly values compose through constructor parameters in development and AOT', function (): void {
    $directory = sys_get_temp_dir()
        . '/componenta-di-readonly-composition-'
        . bin2hex(random_bytes(5));
    $config = new Config(['raw' => '  composed  ']);
    $builder = ContainerBuilder::configure($config)
        ->addService(CasterProviderInterface::class, new TestCasterProvider());
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories(
            [PromotedReadonlyConfigCastTarget::class],
            $directory,
        );
        $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );
        $production = ContainerBuilder::configureFromCache(
            $config,
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();

        expect($development->make(PromotedReadonlyConfigCastTarget::class)->value)->toBe('composed')
            ->and($production->make(PromotedReadonlyConfigCastTarget::class)->value)->toBe('composed');
    } finally {
        cleanupReadonlyCompositionDirectory($directory);
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

    expect(fn() => $container->make(MultipleReadonlyTransformersTarget::class, ['value' => 'seed']))
        ->toThrow(AttributeCompositionException::class, 'multiple value transformers');
});
