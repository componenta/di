<?php

declare(strict_types=1);

use Componenta\Config\Config as AppConfig;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\ConfigValueExtractor;

it('rejects unknown keys in a versioned cache envelope', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new AppConfig([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [],
            'dependencis' => [],
        ],
    ))->toThrow(InvalidConfigurationException::class);
});

it('rejects a cache envelope with dependencies but no version', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new AppConfig([]),
        [ConfigKey::DEPENDENCIES => []],
    ))->toThrow(InvalidConfigurationException::class);
});

it('preserves an explicitly present null in ArrayObject configuration', function (): void {
    $extractor = new ConfigValueExtractor();
    $config = new ArrayObject(['nullable' => null]);

    expect($extractor->extract($config, new ConfigAttribute('nullable'), 'fallback'))
        ->toBeNull();
});

it('resolves a drive-relative compiled factory path against baseDir', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-drive-relative-' . bin2hex(random_bytes(5));
    mkdir($directory, 0775, true);
    $file = $directory . '/C:factory.php';
    $class = 'Componenta\\DI\\Tests\\Generated\\DriveRelativeShard';

    $code = <<<'PHP'
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
PHP;

    file_put_contents($file, $code);

    try {
        $container = ContainerBuilder::configureFromCache(
            new AppConfig([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
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
