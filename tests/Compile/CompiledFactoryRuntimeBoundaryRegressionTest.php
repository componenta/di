<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final readonly class CompiledFactoryRuntimeBoundaryTarget {}

final class CompiledFactoryRuntimeBoundaryDriftResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

it('rejects a compiled shard when the runtime resolver pipeline has drifted', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-factory-pipeline-' . bin2hex(random_bytes(5));

    try {
        $factories = (new ContainerBuilder())->compileFactories(
            [CompiledFactoryRuntimeBoundaryTarget::class],
            $directory,
        );
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->addParameterResolver(new CompiledFactoryRuntimeBoundaryDriftResolver(), 10_000)
            ->build();

        expect(fn() => $container->make(CompiledFactoryRuntimeBoundaryTarget::class))
            ->toThrow(InvalidConfigurationException::class, 'pipeline fingerprint');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('rejects modified compiled shard bytes before executing the file', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-factory-integrity-' . bin2hex(random_bytes(5));
    $marker = $directory . '/executed.txt';

    try {
        $factories = (new ContainerBuilder())->compileFactories(
            [CompiledFactoryRuntimeBoundaryTarget::class],
            $directory,
        );
        $definition = $factories[CompiledFactoryRuntimeBoundaryTarget::class];
        $file = $directory . '/' . $definition->file;
        $source = file_get_contents($file);
        file_put_contents(
            $file,
            str_replace(
                'namespace Componenta\\DI\\Generated;',
                'namespace Componenta\\DI\\Generated; file_put_contents(' . var_export($marker, true) . ", 'executed');",
                (string) $source,
            ),
        );
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->build();

        expect(fn() => $container->make(CompiledFactoryRuntimeBoundaryTarget::class))
            ->toThrow(InvalidConfigurationException::class, 'integrity check')
            ->and(is_file($marker))->toBeFalse();
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
