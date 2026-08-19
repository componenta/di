<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final class InvalidAotComposition
{
    public function __construct(
        #[ConfigAttribute('value'), Env('VALUE')]
        public string $value,
    ) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AotExtensionValue
{
    public function __construct(public string $value) {}
}

final readonly class AotExtensionParameterResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(AotExtensionValue::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(AotExtensionValue::class);
        return $attribute instanceof AotExtensionValue
            ? [$target->position, $attribute->value]
            : null;
    }
}

final class AotExtensionTarget
{
    public function __construct(#[AotExtensionValue('extension-ok')] public string $value) {}
}

function productionBuilderFromCompiled(
    ContainerBuilder $builder,
    array $compiled,
    string $directory,
): ContainerBuilder {
    $data = $builder->toArray();
    $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $compiled,
    );

    return ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
        $directory,
    );
}

function aotExtensionBuilder(int $definitionVersion = 1): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AotExtensionValue::class,
            handler: null,
            capabilities: [ValueProvider::class],
            version: $definitionVersion,
        ))
        ->addParameterResolver(new AotExtensionParameterResolver(), 750);
}

test('AOT compilation rejects invalid attribute composition before writing a shard', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-invalid-aot-' . bin2hex(random_bytes(5));

    try {
        expect(fn() => (new ContainerBuilder())->compileFactories(
            [InvalidAotComposition::class],
            $directory,
        ))->toThrow(AttributeCompositionException::class);

        expect(is_dir($directory) ? (glob($directory . '/*') ?: []) : [])->toBe([]);
    } finally {
        cleanupDirectory($directory);
    }
});

test('custom parameter resolvers execute identically in development and compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-extension-aot-' . bin2hex(random_bytes(5));
    $builder = aotExtensionBuilder();
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([AotExtensionTarget::class], $directory);
        $production = productionBuilderFromCompiled($builder, $compiled, $directory)->build();

        expect($development->make(AotExtensionTarget::class)->value)->toBe('extension-ok')
            ->and($production->make(AotExtensionTarget::class)->value)->toBe('extension-ok');
    } finally {
        cleanupDirectory($directory);
    }
});

test('compiled production rejects stale shards when attribute semantics change', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-extension-fingerprint-' . bin2hex(random_bytes(5));
    $compilerBuilder = aotExtensionBuilder(1);

    try {
        $compiled = $compilerBuilder->compileFactories([AotExtensionTarget::class], $directory);
        $runtimeBuilder = aotExtensionBuilder(2);
        $production = productionBuilderFromCompiled($runtimeBuilder, $compiled, $directory)->build();

        expect(fn() => $production->make(AotExtensionTarget::class))
            ->toThrow(InvalidConfigurationException::class, 'semantic fingerprint');
    } finally {
        cleanupDirectory($directory);
    }
});
