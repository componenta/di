<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Cache\DiCacheGeneratorInterface;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
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

    it('only emits compiler-owned generated definition code as raw PHP', function () {
        $value = new GeneratedDefinitionCode(
            'throw new \\RuntimeException("must not execute")',
        );

        (new DiCacheGenerator())->generate([
            ConfigKey::SERVICES => ['generated-code-value' => $value],
        ], $this->path);

        $cache = require $this->path;
        $restored = $cache[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['generated-code-value'];

        expect($restored)->toBeInstanceOf(GeneratedDefinitionCode::class)
            ->and($restored->code)->toBe($value->code);
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

    it('normalizes directory creation warnings into the DI exception boundary', function (): void {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'di_cache_directory_' . bin2hex(random_bytes(4));
        $blocker = $root . DIRECTORY_SEPARATOR . 'blocker';
        $target = $blocker . DIRECTORY_SEPARATOR . 'cache.php';
        $warnings = [];
        mkdir($root);
        file_put_contents($blocker, 'not-a-directory');

        set_error_handler(
            static function (int $_severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_WARNING,
        );

        try {
            expect(fn() => (new DiCacheGenerator())->generate(
                [ConfigKey::SERVICES => ['fresh' => true]],
                $target,
            ))->toThrow(InvalidConfigurationException::class, 'Failed to create DI cache directory');

            expect($warnings)->toBe([]);
        } finally {
            restore_error_handler();
            @unlink($blocker);
            @rmdir($root);
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

    it('preserves an existing cache when serialization fails before commit', function () {
        $generator = new DiCacheGenerator();
        $previous = '<?php return ["previous" => true];';
        file_put_contents($this->path, $previous);
        $dependencies = [
            ConfigKey::SERVICES => ['bad' => fn() => 'unserialisable'],
        ];

        expect(fn() => $generator->generate($dependencies, $this->path))
            ->toThrow(InvalidConfigurationException::class)
            ->and(file_get_contents($this->path))->toBe($previous)
            ->and(glob($this->path . '.tmp.*') ?: [])->toBe([]);
    });

    it('keeps atomic commit failures inside the DI exception boundary without leaking warnings', function () {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'di_cache_commit_' . bin2hex(random_bytes(4));
        $target = $root . DIRECTORY_SEPARATOR . 'cache.php';
        $warnings = [];
        mkdir($root);
        mkdir($target);

        set_error_handler(
            static function (int $_severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_WARNING,
        );

        try {
            expect(fn() => (new DiCacheGenerator())->generate(
                [ConfigKey::SERVICES => ['fresh' => true]],
                $target,
            ))->toThrow(InvalidConfigurationException::class, 'Failed to commit DI cache file');

            expect($warnings)->toBe([])
                ->and(glob($target . '.tmp.*') ?: [])->toBe([])
                ->and(is_dir($target))->toBeTrue();
        } finally {
            restore_error_handler();
            foreach (glob($target . '.tmp.*') ?: [] as $tmp) {
                @unlink($tmp);
            }
            @rmdir($target);
            @rmdir($root);
        }
    });
});
