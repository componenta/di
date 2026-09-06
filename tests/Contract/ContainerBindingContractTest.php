<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;

test(
    'container factory rejects ambiguous or protected canonical bindings',
    function (
        array $sections,
        string $message,
    ): void {
        $namedSections = [];
        foreach ($sections as $key => $value) {
            if (!is_string($key)) {
                throw new \LogicException('Expected dependency section names to be strings.');
            }
            $namedSections[$key] = $value;
        }

        expect(fn() => (new ContainerFactory())->create(
            new Config([], new Environment([])),
            new DependencyDefinitions($namedSections),
        ))->toThrow(InvalidConfigurationException::class, $message);
    },
)->with([
    'factory hidden by alias' => [[
        ConfigKey::FACTORIES => [
            'bound.entry' => static fn(): object => new \stdClass(),
        ],
        ConfigKey::ALIASES => [
            'bound.entry' => 'target.entry',
        ],
    ], 'Factory id "bound.entry" is also an alias'],
    'invokable hidden by alias' => [[
        ConfigKey::INVOKABLES => [
            'bound.entry' => \stdClass::class,
        ],
        ConfigKey::ALIASES => [
            'bound.entry' => 'target.entry',
        ],
    ], 'Invokable alias "bound.entry" conflicts with existing target "target.entry"'],
    'two services share one canonical id' => [[
        ConfigKey::SERVICES => [
            'canonical.entry' => new \stdClass(),
            'alias.entry' => new \stdClass(),
        ],
        ConfigKey::ALIASES => [
            'alias.entry' => 'canonical.entry',
        ],
    ], 'Canonical DI id "canonical.entry" has multiple bindings'],
    'protected factory id' => [[
        ConfigKey::FACTORIES => [
            Container::class => static fn(): object => new \stdClass(),
        ],
    ], 'Cannot register factory for protected DI id'],
    'service alias resolves to protected id' => [[
        ConfigKey::SERVICES => [
            'protected.alias' => new \stdClass(),
        ],
        ConfigKey::ALIASES => [
            'protected.alias' => Container::class,
        ],
    ], 'resolves to protected DI id'],
    'delegator alias resolves to protected id' => [[
        ConfigKey::ALIASES => [
            'protected.alias' => Container::class,
        ],
        ConfigKey::DELEGATORS => [
            'protected.alias' => [
                static fn(mixed $entry): mixed => $entry,
            ],
        ],
    ], 'Cannot register delegator for id "protected.alias"'],
]);
