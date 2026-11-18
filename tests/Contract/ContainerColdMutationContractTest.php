<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ContainerColdMutation;

use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

test('cold mutation helpers preserve protected routing and current dependency ownership', function (string $scenario, ?string $error, array $events, array $result): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/ContainerMutationCold.php',
        $root . '/vendor/autoload.php',
        $root . '/src',
        $scenario,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the cold mutation fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr);
    expect(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'autoloaded' => true,
        'error' => $error,
        'events' => $events,
        'result' => $result,
    ]);
})->with([
    'delegator checks the current protected target' => [
        'delegator',
        InvalidConfigurationException::class,
        [],
        ['public_is_container' => true, 'decorator_calls' => 0],
    ],
    'alias validates delegators added during helper loading' => [
        'alias',
        InvalidConfigurationException::class,
        [],
        ['public' => 'ordinary:nested', 'protected_is_container' => true],
    ],
    'set retains its captured target and invalidates newly added dependencies' => [
        'set',
        null,
        ['base:old'],
        ['owner' => 'new', 'selected' => 'next', 'product' => 'base:new'],
    ],
    'retirement loading adds a dependency before external ownership inspection' => [
        'external retirement',
        null,
        ['base:old'],
        ['owner' => 'new', 'product' => 'base:new'],
    ],
    'registry loading adds a dependency before external ownership inspection' => [
        'external registry',
        null,
        ['base:old'],
        ['owner' => 'new', 'product' => 'base:new'],
    ],
    'nested external registration keeps its earlier ownership' => [
        'nested registry',
        null,
        ['base:old', 'base:nested'],
        ['owner' => 'nested', 'product' => 'base:nested'],
    ],
]);
