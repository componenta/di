<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;

test(
    'container factory rejects invalid dependency definitions at its public boundary',
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
    'unsupported section' => [
        ['unsupported' => []],
        'Unsupported container dependency key "unsupported"',
    ],
    'non-array section' => [
        [ConfigKey::FACTORIES => 'invalid'],
        'Container dependency "factories" must be an array; got string',
    ],
    'non-boolean replacement flag' => [
        [ConfigKey::PARAMETER_RESOLVERS_REPLACE => 1],
        'Container dependency "parameter_resolvers_replace" must be bool; got int',
    ],
    'empty invokable class' => [
        [ConfigKey::INVOKABLES => ['']],
        'Invokable entries must be non-empty class strings',
    ],
    'invalid alias target' => [
        [ConfigKey::ALIASES => ['alias' => '']],
        'Aliases must map non-empty string ids to non-empty string targets',
    ],
    'empty factory id' => [
        [ConfigKey::FACTORIES => ['' => static fn(): object => new \stdClass()]],
        'Factory ids must be non-empty strings',
    ],
    'empty delegator id' => [
        [ConfigKey::DELEGATORS => ['' => [static fn(object $entry): object => $entry]]],
        'Delegator ids must be non-empty strings',
    ],
    'invalid delegator specification' => [
        [ConfigKey::DELEGATORS => ['service' => [42]]],
        'Invalid delegator for "service": int',
    ],
    'empty service id' => [
        [ConfigKey::SERVICES => ['' => new \stdClass()]],
        'Service ids must be non-empty strings',
    ],
    'non-integer resolver priority' => [
        [ConfigKey::PARAMETER_RESOLVERS => ['high' => 'resolver']],
        'Parameter resolver priority must be int; got string',
    ],
    'invalid resolver specification' => [
        [ConfigKey::PARAMETER_RESOLVERS => [100 => new \stdClass()]],
        'Parameter resolver specification must be an instance, callable, non-empty service id or [service-id, method]',
    ],
    'associative attribute definitions' => [
        [ConfigKey::ATTRIBUTE_DEFINITIONS => ['named' => 'definition']],
        'Attribute definitions must be configured as a list',
    ],
    'invalid attribute definition specification' => [
        [ConfigKey::ATTRIBUTE_DEFINITIONS => [new \stdClass()]],
        'Attribute definition specification must be an instance, callable, non-empty service id or [service-id, method]',
    ],
    'associative capability policies' => [
        [ConfigKey::ATTRIBUTE_CAPABILITIES => ['named' => new \stdClass()]],
        'Attribute capabilities must be configured as a list',
    ],
    'invalid capability policy' => [
        [ConfigKey::ATTRIBUTE_CAPABILITIES => [new \stdClass()]],
        'Attribute capability entries must be Componenta\\DI\\Attribute\\Composition\\CapabilityPolicy; got stdClass',
    ],
]);

test('null dependency sections are rejected rather than treated as absent', function (string $section): void {
    expect(fn() => (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([$section => null]),
    ))->toThrow(InvalidConfigurationException::class, sprintf('Container dependency "%s" must be an array; got null', $section));
})->with([
    ConfigKey::FACTORIES,
    ConfigKey::INVOKABLES,
    ConfigKey::ALIASES,
    ConfigKey::DELEGATORS,
    ConfigKey::SERVICES,
    ConfigKey::PARAMETER_RESOLVERS,
    ConfigKey::ATTRIBUTE_DEFINITIONS,
    ConfigKey::ATTRIBUTE_CAPABILITIES,
]);

test('null replacement flags are rejected rather than treated as false', function (string $section): void {
    expect(fn() => (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([$section => null]),
    ))->toThrow(InvalidConfigurationException::class, sprintf('Container dependency "%s" must be bool; got null', $section));
})->with([ConfigKey::PARAMETER_RESOLVERS_REPLACE, ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE]);
