<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ContainerFactoryCompletion;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

test('container construction rejects an empty deferred factory before a lookup is attempted', function (): void {
    expect(fn() => (new ContainerBuilder())->addFactory('product', '')->build())
        ->toThrow(InvalidConfigurationException::class);
});

test('factory completion reports cold collaborator failure before returning a container and permits retry', function (): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/ContainerFactoryCompletion.php',
        $root . '/vendor/autoload.php',
        $root . '/src',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the factory completion fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr)
        ->and($stderr)->toBe('')
        ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'failed_before_return' => true,
            'original_failure_preserved' => true,
            'retry_has_own_core' => true,
            'retry_has_same_config' => true,
        ]);
});
