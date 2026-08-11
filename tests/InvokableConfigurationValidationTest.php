<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Fixture\InvokableWithInjectedProperty;

final class InvokableWithRequiredDependency
{
    public function __construct(public stdClass $dependency) {}
}

#[Lazy]
final class ConfiguredPrivateLazyInvokable
{
    public bool $initialized;

    private function __construct()
    {
        $this->initialized = true;
    }
}

#[Proxy(stdClass::class)]
final class InvalidConfiguredClassProxy {}

it('rejects an invokable class that cannot be constructed without arguments', function (): void {
    expect(fn() => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::INVOKABLES => [InvokableWithRequiredDependency::class]],
    ))->toThrow(InvalidConfigurationException::class, 'require no constructor arguments');
});

it('rejects an invokable class name that is not loadable', function (): void {
    expect(fn() => (new ContainerBuilder())->addInvokable('Missing\\Invokable\\Service'))
        ->toThrow(InvalidConfigurationException::class, 'is not loadable');
});

it('rejects a class-level proxy argument on the invokable path', function (): void {
    expect(fn() => (new ContainerBuilder())->addInvokable(InvalidConfiguredClassProxy::class))
        ->toThrow(InvalidConfigurationException::class, 'must not specify a proxy class');
});

it('keeps private no-argument constructors available to native lazy invokables', function (): void {
    $container = (new ContainerBuilder())
        ->addInvokable(ConfiguredPrivateLazyInvokable::class)
        ->build();

    $entry = $container->get(ConfiguredPrivateLazyInvokable::class);

    expect($entry)->toBeInstanceOf(ConfiguredPrivateLazyInvokable::class)
        ->and($entry->initialized)->toBeTrue();
});

it('rejects configured invokables that require the attribute lifecycle pipeline', function (): void {
    expect(fn() => (new ContainerBuilder())
        ->addInvokable(InvokableWithInjectedProperty::class)
        ->build())
        ->toThrow(InvalidConfigurationException::class, 'attribute lifecycle');
});

it('rejects runtime invokable definitions that require the attribute lifecycle pipeline', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->set(
        'service',
        Definition::invokable(InvokableWithInjectedProperty::class),
    ))->toThrow(InvalidConfigurationException::class, 'attribute lifecycle');
});
