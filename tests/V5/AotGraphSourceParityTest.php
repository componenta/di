<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AotGraphSource {}

final class AotGraphNonAutowireDependency
{
    public function __construct(
        #[ConfigAttribute('value'), Env('VALUE')]
        public string $value,
    ) {}
}

final readonly class AotGraphSourceRoot
{
    public function __construct(
        #[AotGraphSource]
        public AotGraphNonAutowireDependency $dependency,
    ) {}
}

final readonly class AotGraphSourceHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        $reflection = new ReflectionClass(AotGraphNonAutowireDependency::class);

        return ParameterAttributeValue::resolved($reflection->newInstanceWithoutConstructor());
    }
}

function cleanupAotGraphDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}

test('AOT graph does not treat a class-typed ValueProvider parameter as an autowire dependency', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-aot-source-' . bin2hex(random_bytes(5));
    $builder = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        AotGraphSource::class,
        new AotGraphSourceHandler(),
        capabilities: [ValueProvider::class],
    ));

    try {
        $development = $builder->build();
        $developmentEntry = $development->make(AotGraphSourceRoot::class);

        $compiled = $builder->compileFactories([AotGraphSourceRoot::class], $directory);
        expect(array_keys($compiled))->toBe([AotGraphSourceRoot::class]);

        $data = $builder->toArray();
        $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );
        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();
        $productionEntry = $production->make(AotGraphSourceRoot::class);

        expect($developmentEntry->dependency)->toBeInstanceOf(AotGraphNonAutowireDependency::class)
            ->and($productionEntry->dependency)->toBeInstanceOf(AotGraphNonAutowireDependency::class);
    } finally {
        cleanupAotGraphDirectory($directory);
    }
});
