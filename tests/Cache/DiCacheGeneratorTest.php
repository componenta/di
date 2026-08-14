<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Cache\DiCacheGeneratorInterface;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

function tempCachePath(string $suffix = '.php'): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'di_cache_' . bin2hex(random_bytes(4)) . $suffix;
}

describe('Cache\\DiCacheGenerator', function () {
    beforeEach(function () {
        $this->path = tempCachePath();
    });

    afterEach(function () {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    });

    it('implements DiCacheGeneratorInterface', function () {
        expect(new DiCacheGenerator())->toBeInstanceOf(DiCacheGeneratorInterface::class);
    });

    it('resolves the default cache generator through the container alias', function () {
        $container = (new ContainerBuilder())->build();

        expect($container->get(DiCacheGeneratorInterface::class))
            ->toBeInstanceOf(DiCacheGenerator::class);
    });

    it('writes a versioned cache envelope that configureFromCache can load', function () {
        $generator = new DiCacheGenerator();
        $dependencies = [
            ConfigKey::SERVICES => ['cached.service' => 'cached-value'],
            ConfigKey::ALIASES => ['cached.alias' => 'cached.service'],
        ];

        $generator->generate($dependencies, $this->path);
        $cache = require $this->path;

        expect($cache['version'])->toBe(ContainerBuilder::CACHE_VERSION)
            ->and($cache)->toHaveKey(ConfigKey::DEPENDENCIES);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            dirname($this->path),
        )->build();

        expect($container->get('cached.alias'))->toBe('cached-value');
    });

    it('produces a file with <?php opener and declare(strict_types=1)', function () {
        $generator = new DiCacheGenerator();

        $generator->generate([ConfigKey::SERVICES => ['k' => 'v']], $this->path);

        $contents = file_get_contents($this->path);
        expect($contents)->toStartWith("<?php")
            ->and($contents)->toContain('declare(strict_types=1);')
            ->and($contents)->toContain('return ');
    });

    it('creates intermediate directories as needed', function () {
        $generator = new DiCacheGenerator();
        $rootDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'di_cache_test_' . bin2hex(random_bytes(4));
        $nested = $rootDir . '/nested/deep/cache.php';

        try {
            $generator->generate([ConfigKey::SERVICES => ['created' => true]], $nested);

            expect(file_exists($nested))->toBeTrue();
        } finally {
            if (file_exists($nested)) {
                unlink($nested);
            }
            foreach ([dirname($nested), dirname(dirname($nested)), $rootDir] as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
        }
    });

    it('replaces an existing file without retaining previous contents', function () {
        $generator = new DiCacheGenerator();
        file_put_contents($this->path, '<?php return ["previous" => true];');

        $generator->generate([ConfigKey::SERVICES => ['fresh' => true]], $this->path);
        $cache = require $this->path;

        expect($cache[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['fresh'])->toBeTrue()
            ->and(file_get_contents($this->path))->not->toContain('previous');
    });

    it('rejects invalid dependency shapes before writing a cache', function () {
        $generator = new DiCacheGenerator();

        expect(fn() => $generator->generate(['unsupported' => []], $this->path))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('throws InvalidConfigurationException when dependencies contain unserialisable values', function () {
        $generator = new DiCacheGenerator();
        $dependencies = [
            ConfigKey::SERVICES => ['bad' => fn() => 'unserialisable'],
        ];

        expect(fn() => $generator->generate($dependencies, $this->path))
            ->toThrow(InvalidConfigurationException::class);
    });
});
