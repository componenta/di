<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('does not infer compiled factory trust from an object restored at the cache boundary', function (): void {
    $root = sys_get_temp_dir() . '/componenta-compiled-object-trust-' . bin2hex(random_bytes(5));
    $base = $root . '/base';
    $outside = $root . '/outside.php';
    mkdir($base, 0777, true);
    file_put_contents($outside, '<?php throw new RuntimeException("must not execute");');

    try {
        $definition = new CompiledFactoryDefinition(
            $outside,
            stdClass::class,
            'create',
        );
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => ['entry' => $definition],
                ],
            ],
            $base,
        )->build();

        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'relative path');
    } finally {
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
    }
});

it('keeps a direct programmatic compiled factory object as an explicit trusted escape hatch', function (): void {
    $class = 'CompiledFactoryProgrammaticTrust_' . bin2hex(random_bytes(6));
    $file = tempnam(sys_get_temp_dir(), 'componenta-di-trusted-shard-');

    expect($file)->not->toBeFalse();
    /** @var non-empty-string $file */
    file_put_contents(
        $file,
        sprintf(
            <<<'PHP'
<?php

final class %s
{
    public function __construct(
        array $parameterResolvers,
        array $attributeHandlers,
        \Componenta\DI\ProxyFactoryInterface $proxyFactory,
    ) {}

    public function create(array $parameters = []): string
    {
        return 'trusted-programmatic';
    }
}

return %s::class;
PHP,
            $class,
            $class,
        ),
    );

    try {
        $container = ContainerBuilder::configureWithDependencies(
            new Config([]),
            [
                ConfigKey::FACTORIES => [
                    'entry' => new CompiledFactoryDefinition($file, $class, 'create'),
                ],
            ],
        )->build();

        expect($container->get('entry'))->toBe('trusted-programmatic');
    } finally {
        @unlink($file);
    }
});
