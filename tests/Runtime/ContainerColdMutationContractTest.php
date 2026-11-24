<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\ContainerColdMutationContract;

use Componenta\DI\Exception\InvalidConfigurationException;

use function Componenta\DI\Tests\Support\runContainerFixture;

test('cold mutation helpers preserve protected routing and current dependency ownership', function (string $scenario, ?string $error, array $events, array $result): void {
    expect(runContainerFixture('ContainerMutationCold.php', $scenario))->toBe([
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
