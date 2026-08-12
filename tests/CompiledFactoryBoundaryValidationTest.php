<?php

declare(strict_types=1);

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects compiled factory definitions whose file contains the cache separator', function (): void {
    $definition = new CompiledFactoryDefinition(
        "bad\0path",
        'GeneratedFactoryForBoundaryTest',
        'createService',
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

it('rejects compiled factory definitions with an invalid method name at registration time', function (): void {
    $definition = new CompiledFactoryDefinition(
        '/tmp/generated-factory.php',
        'GeneratedFactoryForBoundaryTest',
        "create\0Service",
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

it('resolves a drive-relative compiled factory path against baseDir', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-drive-relative-' . bin2hex(random_bytes(5));
    mkdir($directory, 0775, true);
    $file = $directory . '/C:factory.php';
    $class = 'Componenta\\DI\\Tests\\Generated\\DriveRelativeShard';

    $code = <<<'SHARD'
<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Generated;

final class DriveRelativeShard
{
    public function __construct(
        private readonly array $parameterResolvers,
        private readonly array $attributeHandlers,
        private readonly \Componenta\DI\ProxyFactoryInterface $proxyFactory,
    ) {}

    public function create(array $context): object
    {
        return new \stdClass();
    }
}

return DriveRelativeShard::class;
SHARD;

    file_put_contents($file, $code);

    try {
        $container = ContainerBuilder::configureFromCache(
            new \Componenta\Config\Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                \Componenta\DI\ConfigKey::DEPENDENCIES => [
                    \Componenta\DI\ConfigKey::FACTORIES => [
                        'drive.relative' => new CompiledFactoryDefinition(
                            'C:factory.php',
                            $class,
                            'create',
                        ),
                    ],
                ],
            ],
            $directory,
        )->build();

        expect($container->get('drive.relative'))->toBeInstanceOf(stdClass::class);
    } finally {
        @unlink($file);
        @rmdir($directory);
    }
});