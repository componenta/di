<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final readonly class ExistingInvokableAliasTarget {}
final readonly class RequestedInvokableAliasTarget {}

it('rejects a keyed invokable that conflicts with an existing alias', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::ALIASES => [
                'handler' => ExistingInvokableAliasTarget::class,
            ],
            ConfigKey::INVOKABLES => [
                'handler' => RequestedInvokableAliasTarget::class,
            ],
        ],
    ]);

    expect(fn() => ContainerBuilder::configure($config))
        ->toThrow(
            InvalidConfigurationException::class,
            'Invokable alias "handler" conflicts',
        );
});

it('accepts a keyed invokable when an existing alias resolves to the same target', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::ALIASES => [
                'handler' => ExistingInvokableAliasTarget::class,
            ],
            ConfigKey::INVOKABLES => [
                'handler' => ExistingInvokableAliasTarget::class,
            ],
        ],
    ]);

    expect(ContainerBuilder::configure($config)->build()->get('handler'))
        ->toBeInstanceOf(ExistingInvokableAliasTarget::class);
});

it('rejects a conflicting fluent invokable alias registration', function (): void {
    $builder = (new ContainerBuilder())
        ->addAlias('handler', ExistingInvokableAliasTarget::class);

    expect(fn() => $builder->addInvokable(
        'handler',
        RequestedInvokableAliasTarget::class,
    ))->toThrow(
        InvalidConfigurationException::class,
        'Invokable alias "handler" conflicts',
    );
});
