<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class CustomParityStamp
{
    public function __construct(public string $value) {}
}

final class CustomParityParameterResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'custom';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, 'custom-resolved'];
    }
}

final class CustomParityAttributeHandler implements AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 0;
    }

    public function supportsAttribute(string $attributeClass, \Reflector $target): bool
    {
        return $target instanceof \ReflectionProperty
            && $attributeClass === CustomParityStamp::class;
    }

    public function handle(
        object $attribute,
        \Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof CustomParityStamp || !$target instanceof \ReflectionProperty) {
            throw new LogicException('Unexpected custom parity attribute target.');
        }

        if ($context->claimProperty($target)) {
            $context->writeProperty($target, $attribute->value);
        }
    }
}

#[SetUp('configure', ['value' => 'setup-override'])]
#[SetUp('finish', ['value' => 'finish-override'])]
final class CustomExtensionProductionParityEntry
{
    #[CustomParityStamp('attribute-handled')]
    public string $stamp;

    /** @var list<string> */
    public array $steps = [];

    public string $configured = '';

    public function __construct(public string $custom) {}

    public function configure(string $value): void
    {
        $this->configured = $value;
        $this->steps[] = 'configure:' . $value;
    }

    public function finish(string $value): void
    {
        $this->steps[] = 'finish:' . $value;
    }
}

function customExtensionParityBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addParameterResolver(CustomParityParameterResolver::class, 5000)
        ->addAttributeHandler(CustomParityAttributeHandler::class);
}

/** @return array{custom: string, stamp: string, configured: string, steps: list<string>} */
function customExtensionParitySnapshot(CustomExtensionProductionParityEntry $entry): array
{
    return [
        'custom' => $entry->custom,
        'stamp' => $entry->stamp,
        'configured' => $entry->configured,
        'steps' => $entry->steps,
    ];
}

it('keeps runtime fallback extensions and setup semantics identical in compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-custom-extension-parity-' . bin2hex(random_bytes(5));

    try {
        $development = customExtensionParityBuilder()->build();
        $compiler = customExtensionParityBuilder();
        $factories = $compiler->compileFactories(
            [CustomExtensionProductionParityEntry::class],
            $directory,
        );
        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                    ConfigKey::PARAMETER_RESOLVERS => [
                        5000 => CustomParityParameterResolver::class,
                    ],
                    ConfigKey::ATTRIBUTE_HANDLERS => [
                        CustomParityAttributeHandler::class,
                    ],
                ],
            ],
            $directory,
        )->build();

        $expected = $development->make(
            CustomExtensionProductionParityEntry::class,
            ['value' => 'creation-context'],
        );
        $actual = $production->make(
            CustomExtensionProductionParityEntry::class,
            ['value' => 'creation-context'],
        );

        expect(customExtensionParitySnapshot($actual))
            ->toBe(customExtensionParitySnapshot($expected))
            ->toBe([
                'custom' => 'custom-resolved',
                'stamp' => 'attribute-handled',
                'configured' => 'setup-override',
                'steps' => [
                    'configure:setup-override',
                    'finish:finish-override',
                ],
            ]);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});
