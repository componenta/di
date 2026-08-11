<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final class InvokableWithRequiredDependency
{
    public function __construct(public stdClass $dependency) {}
}

it('rejects an invokable class that cannot be constructed without arguments', function (): void {
    expect(fn () => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::INVOKABLES => [InvokableWithRequiredDependency::class]],
    ))->toThrow(InvalidConfigurationException::class, 'require no constructor arguments');
});

it('rejects an invokable class name that is not loadable', function (): void {
    expect(fn () => (new ContainerBuilder())->addInvokable('Missing\\Invokable\\Service'))
        ->toThrow(InvalidConfigurationException::class, 'is not loadable');
});
