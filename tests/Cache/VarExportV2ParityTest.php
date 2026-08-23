<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final readonly class DiPortableCapturedProduct
{
    public function __construct(public string $value)
    {
    }
}

final readonly class DiUnsafeReadonlyState
{
    private string $derived;

    public function __construct(public string $value)
    {
        $this->derived = strtoupper($value);
    }
}

it('freezes scalar factory captures into a self-contained DI cache', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-captured-' . bin2hex(random_bytes(5)) . '.php';
    $prefix = 'captured:';
    $factory = static function ($container, array $context = []) use ($prefix): DiPortableCapturedProduct {
        return new DiPortableCapturedProduct($prefix . ($context['value'] ?? 'default'));
    };

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => ['captured' => $factory],
        ], $path);

        $cache = require $path;
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            dirname($path),
        )->build();

        expect($container->make('captured', ['value' => 'runtime'])->value)
            ->toBe('captured:runtime');
    } finally {
        @unlink($path);
    }
});

it('rejects source-root-dependent factory closures from portable DI artifacts', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-source-bound-' . bin2hex(random_bytes(5)) . '.php';
    $factory = static fn() => __FILE__;

    try {
        expect(fn() => (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => ['source-bound' => $factory],
        ], $path))->toThrow(InvalidConfigurationException::class, '__FILE__');

        expect(is_file($path))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('rejects PHP references in DI cache arrays', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-reference-' . bin2hex(random_bytes(5)) . '.php';
    $value = 'shared';
    $services = ['first' => &$value];

    try {
        expect(fn() => (new DiCacheGenerator())->generate([
            ConfigKey::SERVICES => $services,
        ], $path))->toThrow(InvalidConfigurationException::class, 'PHP reference');

        expect(is_file($path))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('rejects readonly objects with state outside constructor parameters', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-readonly-state-' . bin2hex(random_bytes(5)) . '.php';

    try {
        expect(fn() => (new DiCacheGenerator())->generate([
            ConfigKey::SERVICES => ['unsafe' => new DiUnsafeReadonlyState('x')],
        ], $path))->toThrow(InvalidConfigurationException::class, 'outside constructor state');

        expect(is_file($path))->toBeFalse();
    } finally {
        @unlink($path);
    }
});
