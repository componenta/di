from pathlib import Path

builder = Path('src/ContainerBuilder.php')
text = builder.read_text()
old = """        if (array_key_exists('version', $cache)
            || array_key_exists(ConfigKey::DEPENDENCIES, $cache)
        ) {
            $version = $cache['version'] ?? self::CACHE_VERSION;
            if ($version !== self::CACHE_VERSION) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container cache version \"%s\"; expected \"%d\".',
                    is_scalar($version) ? (string) $version : get_debug_type($version),
                    self::CACHE_VERSION,
                ));
            }

            $dependencies = $cache[ConfigKey::DEPENDENCIES] ?? [];
            if (!is_array($dependencies)) {
                throw new InvalidConfigurationException(
                    'Container cache dependencies section must be an array.',
                );
            }
        } else {
            $dependencies = $cache;
        }
"""
new = """        $isEnvelope = array_key_exists('version', $cache)
            || array_key_exists(ConfigKey::DEPENDENCIES, $cache)
            || array_key_exists(self::CACHE_VALIDATED_KEY, $cache);

        if ($isEnvelope) {
            self::assertCacheEnvelopeShape($cache);

            $version = $cache['version'];
            if ($version !== self::CACHE_VERSION) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container cache version \"%s\"; expected \"%d\".',
                    is_scalar($version) ? (string) $version : get_debug_type($version),
                    self::CACHE_VERSION,
                ));
            }

            $dependencies = $cache[ConfigKey::DEPENDENCIES] ?? [];
            if (!is_array($dependencies)) {
                throw new InvalidConfigurationException(
                    'Container cache dependencies section must be an array.',
                );
            }
        } else {
            $dependencies = $cache;
        }
"""
if old not in text:
    raise SystemExit('configureFromCache block not found')
text = text.replace(old, new, 1)

marker = """    /**
     * @param array<string, mixed> $dependencies
     * @phpstan-assert DependencyShape $dependencies
     */
    private static function assertDependencyShape(array $dependencies): void
    {
        $allowed = array_fill_keys(ConfigKey::dependencyKeys(), true);
"""
helper = """    /** @param array<string, mixed> $cache */
    private static function assertCacheEnvelopeShape(array $cache): void
    {
        $allowed = [
            'version' => true,
            self::CACHE_VALIDATED_KEY => true,
            ConfigKey::DEPENDENCIES => true,
        ];

        foreach ($cache as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container cache key \"%s\".',
                    (string) $key,
                ));
            }
        }

        if (!array_key_exists('version', $cache)) {
            throw new InvalidConfigurationException(
                'Container cache envelope must declare a version.',
            );
        }

        if (array_key_exists(self::CACHE_VALIDATED_KEY, $cache)
            && !is_bool($cache[self::CACHE_VALIDATED_KEY])
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Container cache \"%s\" marker must be bool; got %s.',
                self::CACHE_VALIDATED_KEY,
                get_debug_type($cache[self::CACHE_VALIDATED_KEY]),
            ));
        }
    }

""" + marker
if marker not in text:
    raise SystemExit('assertDependencyShape marker not found')
text = text.replace(marker, helper, 1)
builder.write_text(text)

resolver = Path('src/Resolver/Entry/FactoryResolver.php')
text = resolver.read_text()
old = "            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'));\n"
new = """            || (strlen($path) >= 3
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && ($path[2] === '/' || $path[2] === '\\\\')));
"""
if old not in text:
    raise SystemExit('isAbsolutePath block not found')
resolver.write_text(text.replace(old, new, 1))

Path('tests/CacheEnvelopeValidationTest.php').write_text(r'''<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects unknown keys in a versioned cache envelope', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [],
            'dependencis' => [],
        ],
    ))->toThrow(InvalidConfigurationException::class);
});

it('rejects a cache envelope with dependencies but no version', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [ConfigKey::DEPENDENCIES => []],
    ))->toThrow(InvalidConfigurationException::class);
});

it('rejects a malformed cache validation marker', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => 'yes',
            ConfigKey::DEPENDENCIES => [],
        ],
    ))->toThrow(InvalidConfigurationException::class);
});

it('keeps accepting a raw dependency array', function (): void {
    $builder = ContainerBuilder::configureFromCache(
        new Config([]),
        [ConfigKey::SERVICES => ['cache.raw' => 42]],
    );

    expect($builder->build()->get('cache.raw'))->toBe(42);
});
''')

boundary = Path('tests/CompiledFactoryBoundaryValidationTest.php')
text = boundary.read_text()
addition = r'''

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
'''
if "resolves a drive-relative compiled factory path against baseDir" not in text:
    boundary.write_text(text.rstrip() + addition + "\n")

for path in [
    Path('tests/Recheck5ProbeTest.php'),
    Path('.github/workflows/recheck5-probes.yml'),
    Path('.github/workflows/recheck5-apply.yml'),
    Path('.github/recheck5_patch.py'),
]:
    if path.exists():
        path.unlink()
