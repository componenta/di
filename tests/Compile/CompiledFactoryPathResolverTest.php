<?php

declare(strict_types=1);

use Componenta\DI\Compile\Factory\CompiledFactoryPathResolver;
use Componenta\DI\Exception\InvalidConfigurationException;

function compiledFactoryPathFixture(): array
{
    $root = sys_get_temp_dir() . '/componenta-compiled-path-' . bin2hex(random_bytes(5));
    $base = $root . '/base';
    mkdir($base, 0777, true);
    file_put_contents($base . '/valid.php', '<?php return true;');
    file_put_contents($root . '/outside.php', '<?php return true;');

    return [$root, $base];
}

function removeCompiledFactoryPathFixture(string $root): void
{
    if (is_link($root . '/base/link.php')) {
        @unlink($root . '/base/link.php');
    }
    @unlink($root . '/base/valid.php');
    @unlink($root . '/outside.php');
    @rmdir($root . '/base');
    @rmdir($root);
}

describe('CompiledFactoryPathResolver', function () {
    it('canonicalizes a shard inside the configured base directory', function () {
        [$root, $base] = compiledFactoryPathFixture();

        try {
            $resolved = (new CompiledFactoryPathResolver($base))->resolve('valid.php');

            expect($resolved)->toBe(realpath($base . '/valid.php'));
        } finally {
            removeCompiledFactoryPathFixture($root);
        }
    });

    it('rejects traversal outside the configured base directory', function () {
        [$root, $base] = compiledFactoryPathFixture();

        try {
            expect(fn() => (new CompiledFactoryPathResolver($base))->resolve('../outside.php'))
                ->toThrow(InvalidConfigurationException::class, 'outside base directory');
        } finally {
            removeCompiledFactoryPathFixture($root);
        }
    });

    it('rejects absolute paths in untrusted mode', function () {
        [$root, $base] = compiledFactoryPathFixture();

        try {
            $outside = realpath($root . '/outside.php');
            expect($outside)->not->toBeFalse();

            expect(fn() => (new CompiledFactoryPathResolver($base))->resolve($outside))
                ->toThrow(InvalidConfigurationException::class, 'relative path');
        } finally {
            removeCompiledFactoryPathFixture($root);
        }
    });

    it('requires an explicit base directory in untrusted mode', function () {
        expect(fn() => (new CompiledFactoryPathResolver(null))->resolve('factory.php'))
            ->toThrow(InvalidConfigurationException::class, 'require a base directory');
    });

    it('rejects a symlink that resolves outside the configured base directory', function () {
        [$root, $base] = compiledFactoryPathFixture();

        try {
            $created = @symlink($root . '/outside.php', $base . '/link.php');
            if (!$created) {
                expect(true)->toBeTrue();
                return;
            }

            expect(fn() => (new CompiledFactoryPathResolver($base))->resolve('link.php'))
                ->toThrow(InvalidConfigurationException::class, 'outside base directory');
        } finally {
            removeCompiledFactoryPathFixture($root);
        }
    });

    it('preserves an explicitly trusted absolute path', function () {
        [$root, $base] = compiledFactoryPathFixture();

        try {
            $outside = realpath($root . '/outside.php');
            expect((new CompiledFactoryPathResolver($base, true))->resolve($outside))
                ->toBe($outside);
        } finally {
            removeCompiledFactoryPathFixture($root);
        }
    });
});
