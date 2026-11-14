<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\Php85AttributeCallable;

/** @return array{code:int,stdout:string,stderr:string} */
function nativeFixture(string $name, string $mode = 'cached'): array
{
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/Php85Callbacks/' . $name . '.php.fixture',
        $root . '/vendor/autoload.php',
        $root . '/src',
        $mode,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start the native PHP fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

test('native Init expressions preserve legacy callables lexical scope state and lifetime', function (string $mode): void {
    $result = nativeFixture('InitNative', $mode);
    expect($result['code'])->toBe(0, $result['stdout'] . $result['stderr']);
    expect(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR))->toBe([
        'source_map' => ['class' => true, 'functions' => true],
        'values' => [
            [1, 1, 1, 1, 'one', 'one', 'one', 'one'],
            [1, 2, 2, 2, 'two', 'two', 'two', 'two'],
            [1, 3, 3, 3, 'three', 'three', 'three', 'three'],
        ],
        'shared' => true,
        'inline_static_retained' => false,
        'lazy' => [0, 'initialized', 1],
        'trait_inline_scope' => ['first', 'second'],
    ]);
})->with(['cached', 'uncached'])
    ->skip(PHP_VERSION_ID < 80500, 'Native callback expressions require PHP 8.5.');

test('unsupported native callback forms fail at their engine owned boundary', function (string $fixture, bool $compiled): void {
    $result = nativeFixture($fixture);
    expect($result['code'])->not->toBe(0)
        ->and(str_contains($result['stdout'], "compiled\n"))->toBe($compiled)
        ->and($result['stdout'] . $result['stderr'])->toContain('Fatal error');
})->with([
    ['InvalidArrow', false],
    ['InvalidCapture', false],
    ['InvalidNonStatic', false],
    ['InvalidDynamicReference', false],
    ['InvalidObjectReference', false],
    ['InvalidMagicReference', true],
])->skip(PHP_VERSION_ID < 80500, 'Native callback expressions require PHP 8.5.');

test('native setup callbacks preserve scope state lazy retries and runtime lifetimes', function (string $mode): void {
    $result = nativeFixture('SetUpNative', $mode);
    expect($result['code'])->toBe(0, $result['stdout'] . $result['stderr']);
    expect(json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR))->toBe([
        'source_map' => ['class' => true, 'functions' => true],
        'values' => [
            [[1, 'one', 'one', 1, 1, 1], true],
            [[1, 'two', 'two', 2, 2, 2], true],
            [[1, 'three', 'three', 3, 3, 3], true],
        ],
        'shared' => true,
        'private_scope' => 'PrivateScope:three',
        'lazy' => [0, 'initialized', 1, true],
        'unshared_released' => [true, true, true],
        'eager_retry' => [true, true, 2, 'ready', true],
        'lazy_retry' => [0, true, true, 'ready', 2],
        'callbacks_released' => [true, true, true, true],
        'static_locals_released' => [true, true, true, true],
    ]);
})->with(['cached', 'uncached'])
    ->skip(PHP_VERSION_ID < 80500, 'Native callback expressions require PHP 8.5.');

test('setup property narrowing is an explicit major migration incompatibility', function (): void {
    $result = nativeFixture('SetUpPropertyNarrowing');

    expect($result['code'])->not->toBe(0)
        ->and($result['stdout'] . $result['stderr'])->toContain('must be Closure|string')
        ->and($result['stdout'])->not->toContain("compiled\n");
});
