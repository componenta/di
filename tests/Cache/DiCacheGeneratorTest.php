<?php

declare(strict_types=1);

use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Cache\DiCacheGeneratorInterface;
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

    it('writes a PHP file that returns the exact input array', function () {
        $generator = new DiCacheGenerator();
        $config = [
            'factories' => ['svc' => 'FactoryClass'],
            'aliases'   => ['a' => 'b'],
            'attribute_handlers' => ['HandlerClass'],
        ];

        $generator->generate($config, $this->path);

        $returned = require $this->path;

        expect($returned)->toBe($config);
    });

    it('produces a file with <?php opener and declare(strict_types=1)', function () {
        $generator = new DiCacheGenerator();

        $generator->generate(['k' => 'v'], $this->path);

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
            $generator->generate(['created' => true], $nested);

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

        $generator->generate(['fresh' => true], $this->path);

        expect(require $this->path)->toBe(['fresh' => true])
            ->and(file_get_contents($this->path))->not->toContain('previous');
    });

    it('throws InvalidConfigurationException when the config contains unserialisable values', function () {
        $generator = new DiCacheGenerator();
        // Closures cannot be serialised to PHP source by Export::pretty().
        $config = ['factory' => fn() => 'unserialisable'];

        expect(fn() => $generator->generate($config, $this->path))
            ->toThrow(InvalidConfigurationException::class);
    });
});
