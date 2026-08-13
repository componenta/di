<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Fixture\SimpleService;

test('compiled shard cache never reuses a shard for a conflicting declared class', function () {
    $directory = sys_get_temp_dir() . '/componenta-di-shard-' . bin2hex(random_bytes(5));

    try {
        $definition = minimalBuilder()->compileFactories([SimpleService::class], $directory)[SimpleService::class];
        $runtime = minimalBuilder()->build();
        $parameters = $runtime->get(ParametersResolver::class);
        $attributes = $runtime->get(AttributeProcessor::class);

        expect($parameters)->toBeInstanceOf(ParametersResolver::class)
            ->and($attributes)->toBeInstanceOf(AttributeProcessor::class);

        $resolver = new FactoryResolver(
            [
                'first' => $definition,
                'second' => new CompiledFactoryDefinition(
                    $definition->file,
                    $definition->class . 'Mismatch',
                    $definition->method,
                ),
            ],
            $runtime,
            $runtime,
            $parameters,
            $attributes,
            $directory,
        );

        expect($resolver->resolve('first'))->toBeInstanceOf(SimpleService::class);
        expect(fn() => $resolver->resolve('second'))
            ->toThrow(InvalidConfigurationException::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
